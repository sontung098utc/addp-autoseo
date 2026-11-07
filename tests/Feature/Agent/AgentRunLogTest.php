<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Agent\Run\RunScorer;
use App\Services\Content\Quality\QualityReport;
use App\Services\Content\Quality\RuleResult;
use App\Services\Publishing\EmergencyStopService;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** AG-S03: điểm 0–100, thử lại có backoff, nhật ký quyết định tự động. Dùng nhà cung cấp giả tất định (mức bằng chứng: test giả lập). */
class AgentRunLogTest extends TestCase
{
    use RefreshDatabase;

    private const KW = 'học bổng toàn phần';

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
    }

    private function article(string $kw = self::KW): Article
    {
        $w = Website::firstOrCreate(['base_url' => 'https://a.vn'], ['name' => 'S', 'cms_type' => 'wordpress', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => $kw, 'workflow_status' => 'planned']);
    }

    private function report(int $p0, int $p1, int $p2): QualityReport
    {
        $r = [];
        foreach ([[RuleResult::P0, $p0], [RuleResult::P1, $p1], [RuleResult::P2, $p2]] as [$sev, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $r[] = new RuleResult("X{$sev}{$i}", $sev, false, 'l', 'm');
            }
        }
        $r[] = new RuleResult('OK', RuleResult::P0, true, 'l', 'm');

        return new QualityReport($r);
    }

    /**
     * @param list<array<string,mixed>|\Throwable> $writes phần tử n cho lần gọi viết thứ n (cuối lặp lại); Throwable thì ném
     * @param list<\Throwable> $outlineErrors lỗi ném lần lượt ở các lần gọi dàn ý đầu
     */
    private function provider(array $writes, array $outlineErrors = []): AiProviderInterface
    {
        return new class($writes, $outlineErrors) implements AiProviderInterface {
            public int $writeCalls = 0;
            public int $outlineCalls = 0;

            public function __construct(private array $writes, private array $outlineErrors)
            {
            }

            public function generateOutline(Article $article, array $options = []): array
            {
                if (isset($this->outlineErrors[$this->outlineCalls])) {
                    throw $this->outlineErrors[$this->outlineCalls++];
                }
                $this->outlineCalls++;

                return ['outline' => ['Mở đầu'], 'tokens_used' => 10, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $item = $this->writes[min($this->writeCalls, count($this->writes) - 1)];
                $this->writeCalls++;
                if ($item instanceof \Throwable) {
                    throw $item;
                }

                return $item + ['tokens_used' => 100, 'cost_vnd' => 1000.0, 'model' => 'fake', 'prompt_version' => 'test'];
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

    private function timeout(string $msg = 'cURL error 28: timed out'): ConnectException
    {
        return new ConnectException($msg, new Request('POST', 'https://x.test/v1'));
    }

    /** @return array{0: AgentRun, 1: list<int>} */
    private function go(AiProviderInterface $ai, ?RunPolicy $p = null, ?Article $a = null): array
    {
        $slept = [];
        $run = (new AgentRunOrchestrator($ai, policy: $p ?? new RunPolicy(), sleep: function (int $s) use (&$slept) { $slept[] = $s; }))->run($a ?? $this->article(), null);

        return [$run, $slept];
    }

    // ---- Điểm ----

    public function test_cong_thuc_diem_va_cac_bien(): void
    {
        $this->assertSame(100, RunScorer::score($this->report(0, 0, 0)));
        $this->assertSame(80, RunScorer::score($this->report(1, 0, 0)));
        $this->assertSame(100 - 20 - 10 - 6, RunScorer::score($this->report(1, 2, 3)));
        $this->assertSame(0, RunScorer::score($this->report(5, 0, 0)), 'đúng 0, không âm');
        $this->assertSame(0, RunScorer::score($this->report(9, 9, 9)), 'kẹp ở 0');
        $this->assertSame(100, RunScorer::score(new QualityReport([])), 'không có mục nào chưa đạt thì 100, không vượt');
    }

    public function test_diem_ghi_vao_buoc_cham_va_dung_chuoi_theo_vong(): void
    {
        [$run] = $this->go($this->provider([$this->bad(), $this->good()]));

        $grades = $run->steps->where('stage', 'grade')->values();
        $this->assertCount(2, $grades);
        $this->assertSame(0, $grades[0]->data['round']);
        $this->assertSame(1, $grades[1]->data['round']);
        $this->assertLessThan(100, $grades[0]->data['score']);
        $this->assertGreaterThan($grades[0]->data['score'], $grades[1]->data['score']);
        $this->assertSame(RunScorer::REFERENCE_THRESHOLD, $grades[0]->data['threshold']);
        $this->assertSame('v1 '.$grades[0]->data['score'].' → sửa 1 '.$grades[1]->data['score'], RunScorer::format(RunScorer::timeline($run->steps)));
    }

    public function test_diem_cao_khong_cuu_bai_con_loi_p0_luat_van_quyet_dinh(): void
    {
        // một lỗi P0 → điểm 80 (bằng mốc tham khảo) nhưng vẫn phải bị giữ nháp
        [$run] = $this->go($this->provider([$this->bad()]));
        $this->assertSame('held', $run->status);
        $this->assertTrue($run->steps->where('stage', 'grade')->first()->data['blocking'] !== []);
    }

    // ---- Thử lại ----

    public function test_loi_tam_thoi_thu_lai_thanh_cong_o_lan_hai_va_goi_backoff(): void
    {
        $ai = $this->provider([$this->timeout(), $this->good()]);
        [$run, $slept] = $this->go($ai);

        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame(2, $ai->writeCalls);
        $this->assertSame([2], $slept, 'backoff được gọi một lần với 2 giây');
        $retry = $run->steps->where('stage', 'retry')->values();
        $this->assertCount(1, $retry);
        $this->assertSame('system', $retry[0]->role);
        $this->assertSame(1, $retry[0]->data['attempt']);
        $this->assertStringContainsString('lỗi tạm thời', $retry[0]->detail);
        $this->assertNotNull($run->final_version_id);
    }

    public function test_backoff_tang_dan_va_het_lan_thi_failed_khong_dung_mock(): void
    {
        $secret = 'https://x.test/v1/m:gen?key=AIzaSECRETKEY1234567890abcdef';
        $ai = $this->provider([$this->timeout("cURL error 28 for {$secret}")]);
        $a = $this->article();
        [$run, $slept] = $this->go($ai, null, $a);

        $this->assertSame('failed', $run->status);
        $this->assertSame(4, $ai->writeCalls, '1 lần gọi + 3 lần thử lại');
        $this->assertSame([2, 5, 12], $slept);
        $this->assertCount(3, $run->steps->where('stage', 'retry'));
        $this->assertNull($run->final_version_id, 'không có bản nháp, không dùng Mock thay thế');
        $this->assertSame('planned', $a->refresh()->workflow_status);
        $this->assertSame(0, $a->versions()->count());
        $all = json_encode($run->steps->toArray(), JSON_UNESCAPED_UNICODE).$run->outcome_reason;
        $this->assertStringNotContainsString('AIzaSECRET', $all, 'lỗi gốc phải qua ErrorMasker');
        $this->assertStringNotContainsString('key=', $all);
        $this->assertSame('failed', $run->steps->last()->status);
    }

    public function test_5xx_va_429_la_tam_thoi_con_401_thi_khong_thu_lai(): void
    {
        $mk = fn (int $code) => new \GuzzleHttp\Exception\ServerException('http', new Request('POST', 'https://x.test'), new Response($code));
        $ai = $this->provider([$mk(503), $this->good()]);
        [$run, $slept] = $this->go($ai);
        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame([2], $slept);

        $unauth = new \GuzzleHttp\Exception\ClientException('401', new Request('POST', 'https://x.test'), new Response(401));
        $ai2 = $this->provider([$unauth, $this->good()]);
        [$run2, $slept2] = $this->go($ai2);
        $this->assertSame('failed', $run2->status);
        $this->assertSame(1, $ai2->writeCalls, 'lỗi không tạm thời không thử lại');
        $this->assertSame([], $slept2);

        $ai3 = $this->provider([new RuntimeException('boom'), $this->good()]);
        [$run3, $slept3] = $this->go($ai3);
        $this->assertSame('failed', $run3->status);
        $this->assertSame([], $slept3);
    }

    public function test_thu_lai_ca_o_buoc_lap_dan_y(): void
    {
        $ai = $this->provider([$this->good()], [$this->timeout(), $this->timeout()]);
        [$run, $slept] = $this->go($ai);

        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame([2, 5], $slept);
        $this->assertCount(2, $run->steps->where('stage', 'retry'));
        $this->assertStringContainsString('lập dàn ý', $run->steps->where('stage', 'retry')->first()->title);
    }

    // ---- Quyết định tự động ----

    public function test_ghi_quyet_dinh_khi_dung_sua_vi_khong_cai_thien(): void
    {
        [$run] = $this->go($this->provider([$this->bad()]));

        $d = $run->steps->where('stage', 'decision')->values();
        $this->assertCount(1, $d);
        $this->assertSame('no_improvement', $d[0]->data['decision']);
        $this->assertArrayHasKey('score_before', $d[0]->data);
        $this->assertArrayHasKey('score_after', $d[0]->data);
        $this->assertSame($d[0]->data['penalty_before'], $d[0]->data['penalty_after']);
        $this->assertStringContainsString('không cải thiện', $d[0]->detail);
    }

    public function test_ghi_quyet_dinh_khi_het_ngan_sach(): void
    {
        [$run] = $this->go($this->provider([$this->bad()]), new RunPolicy(budgetVnd: 500.0));

        $d = $run->steps->firstWhere('stage', 'decision');
        $this->assertSame('budget_exhausted', $d->data['decision']);
        $this->assertEquals(500.0, $d->data['budget_vnd']);
        $this->assertGreaterThanOrEqual(500.0, $d->data['spent_vnd']);
        $this->assertSame('held', $run->status);
    }

    public function test_ghi_quyet_dinh_khi_chu_de_rui_ro_cao_giu_cho_chuyen_gia(): void
    {
        $kw = 'cách điều trị tiểu đường';
        [$run] = $this->go($this->provider([QualityFixtures::goodAttrs($kw)]), null, $this->article($kw));

        $this->assertSame('ready_for_review', $run->status);
        $d = $run->steps->firstWhere('stage', 'decision');
        $this->assertSame('high_risk_expert_review', $d->data['decision']);
        $this->assertSame('high', $d->data['risk_level']);
    }

    public function test_bai_tot_khong_co_quyet_dinh_thua(): void
    {
        [$run] = $this->go($this->provider([$this->good()]));
        $this->assertCount(0, $run->steps->where('stage', 'decision'));
        $this->assertCount(0, $run->steps->where('stage', 'retry'));
    }

    // ---- Giao diện & quyền ----

    public function test_chi_tiet_phien_hien_diem_qua_vong_va_quyet_dinh_tu_dong(): void
    {
        [$run] = $this->go($this->provider([$this->timeout(), $this->bad()]));
        $this->actingAs(User::create(['name' => 'ap', 'email' => 'ap@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));

        $this->get(\App\Filament\Resources\AgentRunResource::getUrl('view', ['record' => $run]))->assertOk()
            ->assertSee('Vòng Chấm ↔ Sửa')->assertSee('v1 ')->assertSee('sửa 1 ')
            ->assertSee('Quyết định tự động')->assertSee('Dừng sửa vì không cải thiện')
            ->assertSee('Thử lại lần 1/3');
    }

    public function test_quyen_xem_phien_khong_doi(): void
    {
        [$run] = $this->go($this->provider([$this->good()]));
        $this->actingAs(User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]));

        $this->get(\App\Filament\Resources\AgentRunResource::getUrl('view', ['record' => $run]))->assertForbidden();
    }
}
