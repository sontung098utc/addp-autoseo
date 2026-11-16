<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Models\Article;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\SectionSplice;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/**
 * Vòng sửa THEO ĐOẠN: lỗi trích dẫn chỉ nằm ở một mục H2 giữa bài thì chỉ viết lại mục đó, ghép lại, chấm lại bằng đúng bộ chấm cũ.
 * Không chắc / AI trả sai định dạng / bản sửa không tốt hơn thì quay về viết lại cả bài. AI GIẢ.
 */
class PartialRepairTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    private KnowledgeItem $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        app(EmergencyStopService::class)->resetForTesting();
        $w = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $b = Brain::create(['name' => 'Học bổng', 'slug' => 'hb-'.uniqid(), 'status' => 'active', 'settings' => ['ai_reliance' => 'must_cite', 'risk_class' => 'low']]);
        $b->websites()->attach($w->id, ['priority' => 10]);
        $this->kb = KnowledgeItem::create(['brain_id' => $b->id, 'type' => 'fact', 'title' => 'Học bổng toàn phần', 'body' => 'Học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc.', 'status' => 'approved', 'version' => 1,
            'content_hash' => KnowledgeDedupe::hash('Học bổng toàn phần', 'x')]);
        $this->article = Article::create(['website_id' => $w->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'planned']);
    }

    /**
     * AI giả. Bài có 3 mục H2; đoạn khẳng định thiếu dấu trích nằm ở mục $at. Khi nhận chế độ sửa theo đoạn (only_sections) chỉ trả mục đó.
     * $mode: fix = thêm dấu [KB#id] khi được yêu cầu; noop = không bao giờ thêm; broken = sửa theo đoạn thì trả HTML sai định dạng (sửa cả bài vẫn đúng).
     */
    private function ai(int $at, string $mode = 'fix'): AiProviderInterface
    {
        return new class($this->kb->id, $at, $mode) implements AiProviderInterface
        {
            public array $options = [];

            public function __construct(private int $kb, private int $at, private string $mode) {}

            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['Điều kiện nhận học bổng'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->options[] = $options;
                $base = QualityFixtures::goodAttrs('học bổng toàn phần');
                $sections = SectionSplice::split($base['html_content'])['sections'];
                $cited = collect((array) ($options['feedback'] ?? []))->contains(fn ($f) => str_contains((string) $f, 'CITE01'));
                $mark = ($cited && $this->mode !== 'noop') ? " [KB#{$this->kb}]" : '';
                $sections[$this->at] .= '<p>Theo khuyến cáo, học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc.'.$mark.'</p>';
                $meta = ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
                if (isset($options['only_sections'])) {
                    return ['html_content' => $this->mode === 'broken' ? '<p>Phản hồi hỏng, không phải các mục đã yêu cầu</p>' : $sections[$this->at]] + $meta;
                }
                $base['html_content'] = implode('', $sections);

                return $base + $meta;
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
    }

    private function cite01Failing(\App\Models\AgentRun $run): bool
    {
        $report = (new ContentQualityChecker)->check($run->finalVersion, $run->article, null);

        return collect($report->failed())->contains(fn ($r) => $r->code === 'CITE01');
    }

    private function onlyFlags(AiProviderInterface $ai): array
    {
        return array_map(fn ($o) => isset($o['only_sections']), $ai->options);
    }

    public function test_loi_o_muc_giua_bai_chi_viet_lai_muc_do_va_giu_nguyen_phan_con_lai(): void
    {
        $original = SectionSplice::split(QualityFixtures::goodHtml('học bổng toàn phần', 900, 1))['sections'];
        $ai = $this->ai(1);
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null);

        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame([false, true], array_slice($this->onlyFlags($ai), 0, 2), 'Bản đầu viết cả bài; lượt sửa dùng chế độ theo đoạn.');
        $this->assertSame([['index' => 1, 'heading' => 'Điều kiện xét tuyển']], $ai->options[1]['only_sections']);
        $this->assertFalse($this->cite01Failing($run), 'Sau khi ghép, đoạn có dấu [KB#id] hợp lệ nên CITE01 đạt.');
        $html = (string) $run->finalVersion->html_content;
        $this->assertStringContainsString(mb_substr(strip_tags($original[0]), 0, 60), strip_tags($html), 'Mục 0 giữ nguyên.');
        $this->assertStringContainsString(mb_substr(strip_tags($original[2]), 0, 60), strip_tags($html), 'Mục 2 giữ nguyên.');
        $this->assertTrue($run->steps->contains(fn ($s) => $s->stage === 'repair' && str_contains((string) $s->detail, 'SỬA THEO ĐOẠN')), 'Nhật ký ghi rõ đã sửa theo đoạn.');
    }

    public function test_loi_o_muc_cuoi_thi_viet_lai_ca_bai(): void
    {
        $ai = $this->ai(2);
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null);

        $this->assertSame('ready_for_review', $run->status);
        $this->assertNotContains(true, $this->onlyFlags($ai), 'Mục cuối chứa ghi chú và khung lưu ý: không sửa theo đoạn.');
        $this->assertFalse($this->cite01Failing($run));
    }

    public function test_ai_tra_sai_dinh_dang_thi_tu_quay_ve_viet_lai_ca_bai(): void
    {
        $ai = $this->ai(1, 'broken');
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null);

        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame([false, true, false], array_slice($this->onlyFlags($ai), 0, 3), 'Theo đoạn hỏng thì gọi lại viết cả bài ngay trong lượt đó.');
        $this->assertFalse($this->cite01Failing($run), 'Bản cuối vẫn được sửa đúng nhờ quay về viết cả bài.');
    }

    public function test_ban_sua_theo_doan_khong_tot_hon_thi_cac_vong_sau_viet_lai_ca_bai(): void
    {
        $ai = $this->ai(1, 'noop');
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null);

        $flags = $this->onlyFlags($ai);
        $this->assertTrue($flags[1], 'Lần sửa đầu dùng chế độ theo đoạn.');
        $this->assertNotContains(true, array_slice($flags, 2), 'Một lần theo đoạn không giúp được thì từ đó viết lại cả bài.');
        $this->assertTrue($run->steps->contains(fn ($s) => ($s->data['decision'] ?? '') === 'fix_rejected'));
    }

    public function test_loi_nhac_theo_doan_neu_dung_muc_can_viet_lai_va_khong_co_khi_khong_yeu_cau(): void
    {
        $p = new \App\Services\Ai\GeminiAiProvider('k', 'gemini-3.5-flash-lite');
        $m = new \ReflectionMethod($p, 'partialBlock');
        $m->setAccessible(true);
        $out = (string) $m->invoke($p, ['only_sections' => [['index' => 1, 'heading' => 'Điều kiện xét tuyển']]]);
        $this->assertStringContainsString('Mục 1: «Điều kiện xét tuyển»', $out);
        $this->assertStringContainsString('CHỈ viết lại', $out);
        $this->assertStringContainsString('NGUYÊN VĂN tiêu đề cũ', $out, 'Phải giữ nguyên tiêu đề để ghép lại không đổi mục lục.');
        $this->assertSame('', $m->invoke($p, []), 'Không yêu cầu theo đoạn thì không thêm gì vào lời nhắc.');
    }

    public function test_tat_bang_cau_hinh_thi_luon_viet_lai_ca_bai(): void
    {
        config(['agent.partial_repair' => false]);
        $ai = $this->ai(1);
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null);

        $this->assertNotContains(true, $this->onlyFlags($ai));
        $this->assertFalse($this->cite01Failing($run));
    }
}
