<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\CampaignWait;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Agent\Refresh\AgentRunDispatcher;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Publishing\EmergencyStopService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Cổng kiến thức: bài rủi ro cao mà kho chưa có mục đã duyệt khớp thì dừng TRƯỚC bước viết (0 lời gọi AI), trừ khi người dùng chọn vẫn viết. */
class AgentRunKnowledgeGateTest extends TestCase
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
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function article(string $kw = self::MEDICAL, ?Website $w = null): Article
    {
        $w ??= $this->site();

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

    private function kb(Article $a): void
    {
        $title = 'Chế độ ăn cho người tiểu đường';
        $body = 'Người tiểu đường nên ăn gì: ưu tiên rau xanh, đạm nạc, ngũ cốc nguyên hạt, hạn chế đường.';
        KnowledgeItem::create(['website_id' => $a->website_id, 'type' => 'fact', 'title' => $title, 'body' => $body, 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash($title, $body)]);
    }

    public function test_y_te_kho_trong_dung_truoc_buoc_viet_khong_goi_ai(): void
    {
        $ai = $this->fake();
        $a = $this->article();
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($a, null);

        $this->assertSame('blocked', $run->status);
        $this->assertSame(0, $ai->calls);
        $this->assertSame(['guard', 'risk', 'guard'], $run->steps->pluck('stage')->all());
        $g = $run->steps->last();
        $this->assertSame('blocked', $g->status);
        $this->assertSame('high_risk_no_knowledge', $g->data['reason']);
        $this->assertSame('high', $g->data['risk_level']);
        $this->assertSame(0, $g->data['knowledge_count']);
        $this->assertNull($run->steps->firstWhere('stage', 'brief'));
        $this->assertNull($run->steps->firstWhere('stage', 'write'));
        $this->assertSame('planned', $a->fresh()->workflow_status);
    }

    public function test_cho_phep_viet_dung_chay_tiep_co_step_guard_warn(): void
    {
        $ai = $this->fake();
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article(), null, 'manual', null, null, true);

        $this->assertNotSame('blocked', $run->status);
        $this->assertGreaterThan(0, $ai->calls);
        $warn = $run->steps->where('stage', 'guard')->firstWhere('status', 'warn');
        $this->assertNotNull($warn);
        $this->assertSame('Viết dù kho chưa có kiến thức (người dùng chọn)', $warn->title);
        $this->assertNotNull($run->steps->firstWhere('stage', 'write'));
    }

    public function test_y_te_co_kien_thuc_da_duyet_khop_khong_chan(): void
    {
        $ai = $this->fake();
        $a = $this->article();
        $this->kb($a);
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($a, null);

        $this->assertNotSame('blocked', $run->status);
        $this->assertGreaterThan(0, $ai->calls);
        $this->assertCount(1, $run->steps->where('stage', 'guard'));
    }

    public function test_bai_thuong_kho_trong_khong_chan(): void
    {
        $ai = $this->fake();
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article('học bổng toàn phần'), null);

        $this->assertNotSame('blocked', $run->status);
        $this->assertGreaterThan(0, $ai->calls);
        $this->assertCount(1, $run->steps->where('stage', 'guard'));
    }

    public function test_dispatcher_truyen_co(): void
    {
        $ai = $this->fake();
        $blocked = AgentRunDispatcher::run($this->article(), $ai);
        $this->assertSame('blocked', $blocked->status);
        $this->assertSame(0, $ai->calls);

        $ok = AgentRunDispatcher::run($this->article(self::MEDICAL, $blocked->article->website), $ai, allowWithoutKnowledge: true);
        $this->assertNotSame('blocked', $ok->status);
        $this->assertGreaterThan(0, $ai->calls);
    }

    public function test_chien_dich_khong_viet_ghi_cho_bo_sung_va_khong_giao_lai(): void
    {
        $ai = $this->fake();
        $w = $this->site();
        $a = $this->article(self::MEDICAL, $w);
        $c = Campaign::create(['website_id' => $w->id, 'name' => 'CD', 'status' => 'active', 'source' => 'plan', 'daily_limit' => 5, 'keywords' => []]);
        $now = CarbonImmutable::parse('2026-11-07 03:00:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
        $runner = new CampaignRunner(fn () => $ai);

        $r = $runner->tick($c, $now);
        $this->assertSame('blocked_gap', $r['result']);
        $this->assertStringContainsString(self::MEDICAL, $r['message']);
        $this->assertSame(0, $ai->calls);
        $this->assertSame('planned', $a->fresh()->workflow_status);
        $wait = CampaignWait::where('code', 'kb_gap')->first();
        $this->assertNotNull($wait);
        $this->assertSame('open', $wait->status);
        $this->assertStringContainsString(self::MEDICAL, $wait->title);

        $this->assertSame('no_keywords', $runner->tick($c, $now->addMinutes(15))['result']);
        $this->assertSame(1, AgentRun::count());
        $this->assertSame(1, CampaignWait::where('code', 'kb_gap')->count());
    }
}
