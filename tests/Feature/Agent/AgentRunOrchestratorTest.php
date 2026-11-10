<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** AG-S01: lát dọc đội AI. Dùng AI giả lập tất định để kiểm LUỒNG và RÀO CHẮN, không kiểm chất lượng AI thật. */
class AgentRunOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private const KW = 'học bổng toàn phần';

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
    }

    protected function tearDown(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    private function article(string $kw = self::KW, string $siteStatus = 'active'): Article
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => $siteStatus]);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => $kw, 'workflow_status' => 'planned']);
    }

    /** @param list<array<string,mixed>> $articles lần gọi n trả phần tử n (phần tử cuối lặp lại) */
    private function fake(array $articles, ?\Closure $onWrite = null): AiProviderInterface
    {
        return new class($articles, $onWrite) implements AiProviderInterface {
            public int $writes = 0;
            public array $options = [];

            public function __construct(private array $articles, private ?\Closure $onWrite)
            {
            }

            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['Mở đầu', 'Nội dung'], 'tokens_used' => 100, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->options[] = $options;
                if ($this->onWrite) {
                    ($this->onWrite)($this->writes);
                }
                $a = $this->articles[min($this->writes, count($this->articles) - 1)];
                $this->writes++;

                return $a + ['tokens_used' => 500, 'cost_vnd' => 1000.0, 'model' => 'fake', 'prompt_version' => 'test'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
    }

    private function good(): array
    {
        return QualityFixtures::goodAttrs(self::KW);
    }

    private function bad(): array
    {
        return ['html_content' => '<h2>Ngắn</h2><p>Quá ngắn.</p>'] + $this->good();
    }

    private function go(Article $a, AiProviderInterface $ai, ?RunPolicy $p = null): AgentRun
    {
        return (new AgentRunOrchestrator($ai, policy: $p ?? new RunPolicy()))->run($a, null);
    }

    public function test_bai_tot_di_thang_toi_hang_cho_duyet_va_khong_xuat_ban(): void
    {
        $a = $this->article();
        $run = $this->go($a, $this->fake([$this->good()]));

        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame(0, $run->repair_rounds);
        $this->assertSame('needs_review', $a->refresh()->workflow_status);
        $this->assertSame('needs_review', $run->finalVersion->review_status);
        $this->assertSame(0, Publication::count(), 'Bậc T1 không bao giờ tạo lượt xuất bản');
        $this->assertSame(['guard', 'risk', 'brief', 'research', 'write', 'grade', 'handoff'], $run->steps->pluck('stage')->all());
        $this->assertSame(1000.0, $run->cost_vnd);
        $this->assertSame(600, $run->tokens_used);
    }

    public function test_bai_loi_duoc_gui_lai_loi_chan_va_hoi_tu_sau_mot_vong_sua(): void
    {
        $a = $this->article();
        $ai = $this->fake([$this->bad(), $this->good()]);
        $run = $this->go($a, $ai);

        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame(1, $run->repair_rounds);
        $this->assertNotEmpty($ai->options[1]['feedback'], 'lần viết thứ hai phải nhận danh sách lỗi chặn');
        $this->assertStringContainsString('LEN01', implode(' ', $ai->options[1]['feedback']));
        $this->assertContains('repair', $run->steps->pluck('stage')->all());
        $this->assertSame('needs_review', $a->refresh()->workflow_status);
    }

    public function test_sua_khong_cai_thien_thi_dung_giu_nhap_va_noi_ro_ly_do(): void
    {
        $a = $this->article();
        $ai = $this->fake([$this->bad()]);
        $run = $this->go($a, $ai);

        $this->assertSame('held', $run->status);
        $this->assertSame(2, $ai->writes, 'chỉ sửa một lần rồi dừng vì không cải thiện, không đốt thêm chi phí');
        $this->assertStringContainsString('không cải thiện', (string) $run->outcome_reason);
        $this->assertSame('drafting', $run->finalVersion->review_status, 'bản giữ nháp không vào hàng chờ duyệt');
        $this->assertSame('drafting', $a->refresh()->workflow_status);
        $this->assertSame(0, Publication::count());
    }

    public function test_so_vong_sua_toi_da_duoc_ton_trong(): void
    {
        $a = $this->article();
        // mỗi lần bớt dần một lỗi để điểm giảm nhưng không bao giờ sạch
        $stages = [
            ['html_content' => '<p>x</p>'] + $this->good(),
            ['html_content' => '<h2>a</h2><p>Quá ngắn.</p>'] + $this->good(),
            ['html_content' => '<h2>a</h2><h2>b</h2><p>Quá ngắn.</p>'] + $this->good(),
            ['html_content' => '<h2>a</h2><h2>b</h2><h2>c</h2><p>Quá ngắn.</p>'] + $this->good(),
        ];
        $ai = $this->fake($stages);
        $run = $this->go($a, $ai, new RunPolicy(maxRepairRounds: 2));

        $this->assertLessThanOrEqual(3, $ai->writes, '1 lần viết + tối đa 2 lần sửa');
        $this->assertLessThanOrEqual(2, $run->repair_rounds);
        $this->assertSame('held', $run->status);
    }

    public function test_dung_khan_cap_chan_truoc_khi_goi_ai(): void
    {
        $a = $this->article();
        $actor = User::create(['name' => 'ap', 'email' => 'ap@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
        app(EmergencyStopService::class)->stop($actor, 'Kiểm tra', null);
        $ai = $this->fake([$this->good()]);
        $run = $this->go($a, $ai);

        $this->assertSame('blocked', $run->status);
        $this->assertSame(0, $ai->writes);
        $this->assertSame(0.0, $run->cost_vnd);
        $this->assertSame('planned', $a->refresh()->workflow_status);
    }

    public function test_website_khong_hoat_dong_bi_chan(): void
    {
        $ai = $this->fake([$this->good()]);
        $run = $this->go($this->article(siteStatus: 'error'), $ai);

        $this->assertSame('blocked', $run->status);
        $this->assertSame(0, $ai->writes);
    }

    public function test_loi_he_thong_tra_bai_ve_trang_thai_cu_va_khong_de_lai_ban_nhap(): void
    {
        $a = $this->article();
        $ai = $this->fake([$this->good()], function () { throw new RuntimeException('API key sk-SECRET123456789 hết hạn'); });
        $run = $this->go($a, $ai);

        $this->assertSame('failed', $run->status);
        $this->assertSame('planned', $a->refresh()->workflow_status);
        $this->assertNull($run->final_version_id);
        $this->assertStringNotContainsString('SECRET', json_encode($run->steps->toArray()).$run->outcome_reason, 'không lộ nội dung lỗi gốc');
    }

    public function test_chu_de_rui_ro_cao_van_viet_nhung_ghi_can_chuyen_gia(): void
    {
        $a = $this->article('cách điều trị tiểu đường');
        $run = (new AgentRunOrchestrator($this->fake([QualityFixtures::goodAttrs('cách điều trị tiểu đường')]), policy: new RunPolicy()))->run($a, null, 'manual', null, null, true); // kho trống: người dùng chọn vẫn viết

        $this->assertSame('high', $run->risk_level);
        $this->assertSame('ready_for_review', $run->status);
        $this->assertStringContainsString('chuyên môn', (string) $run->outcome_reason);
        $this->assertSame('warn', $run->steps->firstWhere('stage', 'risk')->status);
    }

    public function test_het_ngan_sach_dung_sua(): void
    {
        $a = $this->article();
        $stages = [['html_content' => '<p>x</p>'] + $this->good(), ['html_content' => '<h2>a</h2><p>y</p>'] + $this->good(), $this->good()];
        $ai = $this->fake(array_map(fn ($s) => $s + ['cost_vnd' => 40000.0], $stages));
        $run = $this->go($a, $ai, new RunPolicy(budgetVnd: 10000.0));

        $this->assertSame('held', $run->status);
        $this->assertStringContainsString('ngân sách', (string) $run->outcome_reason);
        $this->assertSame(1, $ai->writes);
    }

    public function test_html_nguy_hiem_bi_lam_sach_truoc_khi_luu(): void
    {
        $a = $this->article();
        $dirty = ['html_content' => $this->good()['html_content'].'<script>alert(1)</script><img src=x onerror=alert(1)>'] + $this->good();
        $run = $this->go($a, $this->fake([$dirty]));

        $this->assertStringNotContainsString('<script', $run->finalVersion->html_content);
        $this->assertStringNotContainsString('onerror', $run->finalVersion->html_content);
    }

    public function test_nhat_ky_ghi_ly_do_cho_moi_buoc(): void
    {
        $run = $this->go($this->article(), $this->fake([$this->bad(), $this->good()]));

        foreach ($run->steps as $s) {
            $this->assertNotSame('', trim((string) $s->detail), "bước {$s->stage} thiếu lý do");
        }
        $this->assertSame(range(1, $run->steps->count()), $run->steps->pluck('seq')->all());
    }

    public function test_ai_kich_ban_hoi_tu_sau_mot_vong_sua_va_van_duoc_ghi_nhan_la_gia_lap(): void
    {
        $a = $this->article('dịch vụ thiết kế website');
        $run = $this->go($a, new \App\Services\Ai\DemoScriptedAiProvider());

        $this->assertSame('ready_for_review', $run->status, (string) $run->outcome_reason);
        $this->assertSame(1, $run->repair_rounds);
        $this->assertSame('mock', $run->ai_mode, 'AI theo kịch bản không bao giờ được ghi là AI thật');
        $scores = $run->steps->where('stage', 'grade')->map(fn ($s) => $s->data['penalty'])->values()->all();
        $this->assertGreaterThan(0, $scores[0]);
        $this->assertSame(0, $scores[1] > 0 ? 1 : 0, 'điểm phạt chặn phải hết sau vòng sửa');
    }
}
