<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Models\Article;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Agent\Readiness\BrainAutoAttach;
use App\Services\Agent\Refresh\AgentRunDispatcher;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Không có cổng dừng: tự gắn Trí tuệ, tự đọc website khi kho trống (chủ đề rủi ro cao), viết tiếp, gắn nhãn cần chuyên gia nếu kho vẫn trống. */
class AgentRunAutoPrepareTest extends TestCase
{
    use RefreshDatabase;

    private const MEDICAL = 'người tiểu đường nên ăn gì';

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

    private function article(string $kw = self::MEDICAL, ?Website $w = null): Article
    {
        $w ??= Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => $kw, 'workflow_status' => 'planned']);
    }

    private function fake(): AiProviderInterface
    {
        return new class implements AiProviderInterface
        {
            public int $calls = 0;

            public function generateOutline(Article $article, array $options = []): array
            {
                $this->calls++;

                return ['outline' => ['Mục 1'], 'tokens_used' => 1, 'cost_vnd' => 0.0, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->calls++;

                return QualityFixtures::goodAttrs((string) $article->primary_keyword) + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
    }

    private function approvedItem(Article $a): void
    {
        $title = 'Chế độ ăn cho người tiểu đường';
        $body = 'Người tiểu đường nên ăn gì: ưu tiên rau xanh, đạm nạc, ngũ cốc nguyên hạt, hạn chế đường.';
        KnowledgeItem::create(['website_id' => $a->website_id, 'type' => 'fact', 'title' => $title, 'body' => $body, 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash($title, $body)]);
    }

    /** @param array{calls:int, opts:array} $spy */
    private function builder(array &$spy, ?\Closure $effect = null): \Closure
    {
        return function ($site, array $opts) use (&$spy, $effect) {
            $spy['calls']++;
            $spy['opts'] = $opts;
            if ($effect) {
                $effect($site);
            }

            return ['run_id' => 7, 'pages_planned' => 5, 'pages_read' => 3, 'items_auto' => 2, 'items_proposed' => 1, 'cost_vnd' => 12.0];
        };
    }

    private function brain(string $name = 'Đái tháo đường (tiểu đường)', string $slug = 'dai_thao_duong'): Brain
    {
        return Brain::create(['name' => $name, 'slug' => $slug, 'domain_key' => $slug, 'language' => 'vi', 'status' => 'active', 'description' => '']);
    }

    public function test_y_te_kho_trong_khong_chan_goi_build_mot_lan_voi_5_trang(): void
    {
        $spy = ['calls' => 0, 'opts' => []];
        $ai = $this->fake();
        $a = $this->article();
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy, knowledgeBuilder: $this->builder($spy)))->run($a, null);

        $this->assertNotSame('blocked', $run->status);
        $this->assertGreaterThan(0, $ai->calls);
        $this->assertNotNull($run->steps->firstWhere('stage', 'write'));
        $this->assertSame(1, $spy['calls']);
        $this->assertSame(5, $spy['opts']['max_pages']);
        $this->assertSame('gap', $spy['opts']['purpose']);
        $this->assertEqualsWithDelta((new RunPolicy)->budgetVnd * 0.1, $spy['opts']['budget_cap_vnd'], 0.01);
        $prep = $run->steps->where('stage', 'research')->first();
        $this->assertSame('ok', $prep->status);
        $this->assertEquals(12.0, (float) $prep->cost_vnd);
    }

    public function test_kho_van_trong_sau_khi_chuan_bi_gan_co_chuyen_gia(): void
    {
        $spy = ['calls' => 0, 'opts' => []];
        $run = (new AgentRunOrchestrator($this->fake(), policy: new RunPolicy, knowledgeBuilder: $this->builder($spy)))->run($this->article(), null);

        $this->assertSame('ready_for_review', $run->status);
        $research = $run->steps->where('stage', 'research')->last();
        $this->assertTrue($research->data['needs_expert']);
        $this->assertSame('high_risk_no_knowledge', $research->data['reason']);
        $d = $run->steps->firstWhere(fn ($s) => ($s->data['decision'] ?? null) === 'high_risk_expert_review');
        $this->assertNotNull($d);
        $this->assertTrue($d->data['needs_expert']);
    }

    public function test_sau_khi_doc_website_co_muc_da_duyet_thi_khong_can_chuyen_gia_them_co(): void
    {
        $spy = ['calls' => 0, 'opts' => []];
        $a = $this->article();
        $run = (new AgentRunOrchestrator($this->fake(), policy: new RunPolicy, knowledgeBuilder: $this->builder($spy, fn () => $this->approvedItem($a))))->run($a, null);

        $this->assertNotSame('blocked', $run->status);
        $this->assertNull($run->steps->where('stage', 'research')->last()->data['needs_expert'] ?? null);
        $d = $run->steps->firstWhere(fn ($s) => ($s->data['decision'] ?? null) === 'high_risk_expert_review');
        $this->assertNull($d->data['needs_expert'] ?? null);
    }

    public function test_khong_co_ai_van_viet_duoc_va_co_step_warn(): void
    {
        $ai = $this->fake();
        $builder = function () {
            throw new KnowledgeError('Chưa kết nối AI.');
        };
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy, knowledgeBuilder: $builder))->run($this->article(), null);

        $this->assertNotSame('blocked', $run->status);
        $this->assertGreaterThan(0, $ai->calls);
        $warn = $run->steps->where('stage', 'research')->firstWhere('status', 'warn');
        $this->assertNotNull($warn);
        $this->assertStringContainsString('Chưa kết nối AI', $warn->detail);
        $this->assertTrue($run->steps->where('stage', 'research')->last()->data['needs_expert']);
    }

    public function test_bai_thuong_kho_trong_khong_goi_build(): void
    {
        $spy = ['calls' => 0, 'opts' => []];
        $run = (new AgentRunOrchestrator($this->fake(), policy: new RunPolicy, knowledgeBuilder: $this->builder($spy)))->run($this->article('học bổng toàn phần'), null);

        $this->assertNotSame('blocked', $run->status);
        $this->assertSame(0, $spy['calls']);
    }

    public function test_co_kien_thuc_san_thi_khong_goi_build(): void
    {
        $spy = ['calls' => 0, 'opts' => []];
        $a = $this->article();
        $this->approvedItem($a);
        (new AgentRunOrchestrator($this->fake(), policy: new RunPolicy, knowledgeBuilder: $this->builder($spy)))->run($a, null);

        $this->assertSame(0, $spy['calls']);
    }

    public function test_tri_tue_khop_duoc_tu_gan_co_nhat_ky_va_hoan_tac(): void
    {
        $spy = ['calls' => 0, 'opts' => []];
        $b = $this->brain();
        $this->brain('Da liễu và chăm sóc da', 'da_lieu');
        $a = $this->article();
        $run = (new AgentRunOrchestrator($this->fake(), policy: new RunPolicy, knowledgeBuilder: $this->builder($spy)))->run($a, null);

        $site = $a->website;
        $this->assertSame([$b->id], $site->brains()->pluck('brains.id')->all());
        $g = $run->steps->where('stage', 'guard')->firstWhere('title', 'Đã tự gắn Trí tuệ cho website');
        $this->assertNotNull($g);
        $this->assertStringContainsString('Đã tự gắn Trí tuệ «Đái tháo đường (tiểu đường)»', $g->detail);
        $recent = BrainAutoAttach::recent($site);
        $this->assertCount(1, $recent);
        $this->assertSame($b->id, $recent[0]['brain_id']);
        $this->assertTrue($recent[0]['auto']);

        BrainAutoAttach::detach($site, $b->fresh(), null);
        $this->assertSame([], $site->brains()->pluck('brains.id')->all());
        $this->assertSame([], BrainAutoAttach::recent($site));
    }

    public function test_tu_gan_idempotent_va_gioi_han(): void
    {
        $b = $this->brain();
        $a = $this->article();
        $first = BrainAutoAttach::ensureFor($a);
        $this->assertCount(1, $first);
        $this->assertSame([], BrainAutoAttach::ensureFor($a));
        $this->assertSame(1, $a->website->brains()->count());

        // Website đã có đủ 3 Trí tuệ thì không gắn thêm.
        $w2 = Website::create(['name' => 'S2', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        foreach (['x1', 'x2', 'x3'] as $k) {
            $w2->brains()->attach($this->brain('Khác '.$k, $k)->id, ['priority' => 10]);
        }
        $this->assertSame([], BrainAutoAttach::ensureFor($this->article(self::MEDICAL, $w2)));
        $this->assertNotNull($b);
    }

    public function test_dispatcher_van_nhan_co_cu_va_khong_chan(): void
    {
        $ai = $this->fake();
        $run = AgentRunDispatcher::run($this->article(), $ai, allowWithoutKnowledge: false);

        $this->assertNotSame('blocked', $run->status);
        $this->assertGreaterThan(0, $ai->calls);
    }
}
