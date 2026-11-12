<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Models\Article;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** CITE01 (đoạn khẳng định chưa có [KB#id]) đi vào vòng sửa dù chỉ là P1: không chặn bàn giao nhưng người viết được yêu cầu sửa. AI GIẢ. */
class CitationRepairLoopTest extends TestCase
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

    /** AI giả: lần đầu viết đoạn khẳng định KHÔNG có dấu trích dẫn; khi nhận phản hồi CITE01 thì viết lại có dấu (nếu $fixes). */
    private function ai(bool $fixes): AiProviderInterface
    {
        $kb = $this->kb->id;

        return new class($kb, $fixes) implements AiProviderInterface
        {
            public array $options = [];

            public function __construct(private int $kb, private bool $fixes) {}

            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['Điều kiện nhận học bổng'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->options[] = $options;
                $cited = $this->fixes && collect((array) ($options['feedback'] ?? []))->contains(fn ($f) => str_contains((string) $f, 'CITE01'));
                $a = QualityFixtures::goodAttrs('học bổng toàn phần');
                $a['html_content'] .= '<p>Theo khuyến cáo, học bổng toàn phần miễn 100% học phí cho sinh viên xuất sắc.'.($cited ? " [KB#{$this->kb}]" : '').'</p>';

                return $a + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
    }

    private function cite01(\App\Models\AgentRun $run): ?array
    {
        $g = $run->steps->where('stage', 'grade')->last();

        return collect((array) (((array) $g->data)['failed'] ?? []))->firstWhere('code', 'CITE01');
    }

    public function test_doan_thieu_trich_dan_duoc_gui_vao_vong_sua_va_bai_sau_sua_dat_cite01(): void
    {
        $ai = $this->ai(true);
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null);

        $this->assertSame('ready_for_review', $run->status);
        $this->assertSame(1, $run->repair_rounds, 'CITE01 chỉ là P1 nhưng vẫn kích hoạt một vòng sửa');
        $this->assertCount(2, $ai->options);
        $fb = (array) $ai->options[1]['feedback'];
        $this->assertTrue(collect($fb)->contains(fn ($f) => str_contains($f, 'CITE01') && str_contains($f, 'học bổng toàn phần miễn 100%')), 'phản hồi nêu đúng đoạn thiếu dấu');
        $this->assertTrue(collect($fb)->contains(fn ($f) => str_contains($f, 'không bịa mã')));
        $this->assertNull($this->cite01($run), 'sau sửa mọi đoạn khẳng định có dấu [KB#id] hợp lệ');
        $this->assertStringContainsString('[KB#'.$this->kb->id.']', (string) $run->finalVersion->html_content);
    }

    public function test_viet_mai_khong_sua_thi_dung_vi_khong_cai_thien_va_khong_chan_ban_giao(): void
    {
        $ai = $this->ai(false);
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null);

        $this->assertSame('ready_for_review', $run->status, 'CITE01 là P1: không chặn, bài vẫn vào hàng chờ người có chuyên môn');
        $this->assertSame(1, $run->repair_rounds, 'sửa không cải thiện thì dừng sớm, không đốt thêm chi phí');
        $this->assertNotNull($this->cite01($run));
        $this->assertTrue($run->steps->where('stage', 'decision')->contains(fn ($s) => ($s->data['decision'] ?? '') === 'no_improvement'));
    }

    public function test_tri_tue_thuong_khong_co_cite01_nen_khong_co_vong_sua_vi_ly_do_nay(): void
    {
        \App\Models\Brain::query()->update(['settings' => ['ai_reliance' => 'reference', 'risk_class' => 'low']]);
        $ai = $this->ai(false);
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null);

        $this->assertSame(0, $run->repair_rounds);
        $this->assertCount(1, $ai->options);
    }

    public function test_khoi_kien_thuc_cho_nguoi_viet_chua_du_12_muc_va_nhac_trich_dan_tung_doan(): void
    {
        foreach (range(1, 12) as $i) {
            KnowledgeItem::create(['brain_id' => $this->kb->brain_id, 'type' => 'fact', 'title' => 'Học bổng toàn phần mục '.$i, 'body' => 'Học bổng toàn phần số '.$i.' dành cho sinh viên.', 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash('t'.$i, 'b'.$i)]);
        }
        $ai = $this->ai(true);
        (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article, null);

        $this->assertCount(12, $ai->options[0]['knowledge'], 'ngữ cảnh lĩnh vực bắt buộc nguồn có 12 mục');
        $p = new \App\Services\Ai\GeminiAiProvider('k', 'gemini-3.5-flash-lite');
        $m = new \ReflectionMethod($p, 'knowledgeBlock');
        $m->setAccessible(true);
        $block = $m->invoke($p, ['knowledge' => $ai->options[0]['knowledge'], 'domain' => ['ai_reliance' => 'must_cite']]);
        $this->assertSame(12, preg_match_all('/^\[KB#\d+\] /m', $block), 'cả 12 mục tới được lời nhắc (trước đây cắt ở 5 mục)');
        $this->assertStringContainsString('BẮT BUỘC NGUỒN', $block);
    }
}
