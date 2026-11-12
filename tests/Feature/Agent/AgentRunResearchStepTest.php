<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Models\Article;
use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** G4: bước research tách khỏi bước viết, kiểm ngân sách trước viết, nhận dàn ý có sẵn. AI giả, chưa chạy AI thật. */
class AgentRunResearchStepTest extends TestCase
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

    private function article(): Article
    {
        $w = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        return Article::create(['website_id' => $w->id, 'primary_keyword' => self::KW, 'workflow_status' => 'planned']);
    }

    private function fake(float $outlineCost = 0.0): AiProviderInterface
    {
        return new class($outlineCost) implements AiProviderInterface
        {
            public int $outlineCalls = 0;

            public int $writeCalls = 0;

            public function __construct(private float $outlineCost) {}

            public function generateOutline(Article $article, array $options = []): array
            {
                $this->outlineCalls++;

                return ['outline' => ['Điều kiện nhận học bổng'], 'tokens_used' => 1, 'cost_vnd' => $this->outlineCost, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->writeCalls++;

                return QualityFixtures::goodAttrs('học bổng toàn phần') + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
    }

    private function kb(Article $a): KnowledgeItem
    {
        $title = 'Điều kiện học bổng';
        $body = 'Học bổng toàn phần dành cho sinh viên có GPA từ 3.6.';

        return KnowledgeItem::create(['website_id' => $a->website_id, 'type' => 'fact', 'title' => $title, 'body' => $body, 'status' => 'approved', 'version' => 1, 'content_hash' => KnowledgeDedupe::hash($title, $body)]);
    }

    public function test_research_nam_giua_brief_va_write_voi_du_lieu(): void
    {
        $a = $this->article();
        $kb = $this->kb($a);
        $run = (new AgentRunOrchestrator($this->fake(), policy: new RunPolicy))->run($a, null);

        $this->assertSame(['guard', 'risk', 'brief', 'images', 'research', 'write', 'grade', 'assemble', 'handoff'], $run->steps->pluck('stage')->all());
        $r = $run->steps->firstWhere('stage', 'research');
        $this->assertSame('ok', $r->status);
        $this->assertSame('system', $r->role);
        $this->assertSame(0, (int) $r->tokens_used);
        $this->assertSame(0.0, (float) $r->cost_vnd);
        $this->assertSame([$kb->id], $r->data['knowledge']['ids']);
        $this->assertFalse($r->data['knowledge']['gap']);
        $this->assertArrayHasKey('selected', $r->data['images']);
        $this->assertArrayNotHasKey('inserted_ids', $r->data['images']);
        $this->assertArrayHasKey('suggested', $r->data['internal_links']);
        $this->assertSame([], $r->data['citable_sources']);
        $this->assertStringContainsString('1 mục kiến thức', $r->detail);
    }

    public function test_kho_thieu_thi_research_warn_va_gap(): void
    {
        $run = (new AgentRunOrchestrator($this->fake(), policy: new RunPolicy))->run($this->article(), null);
        $r = $run->steps->firstWhere('stage', 'research');
        $this->assertSame('warn', $r->status);
        $this->assertTrue($r->data['knowledge']['gap']);
        $this->assertSame(1, KnowledgeGap::count());
    }

    public function test_buoc_write_van_co_du_lieu_cu(): void
    {
        $a = $this->article();
        $kb = $this->kb($a);
        $run = (new AgentRunOrchestrator($this->fake(), policy: new RunPolicy))->run($a, null);
        $w = $run->steps->firstWhere('stage', 'write');
        $this->assertSame([$kb->id], $w->data['knowledge']['ids']);
        $this->assertArrayHasKey('images', $w->data);
        $this->assertArrayHasKey('internal_links', $w->data);
        $this->assertStringContainsString('KB#'.$kb->id, $w->detail);
    }

    public function test_ngan_sach_thap_dung_truoc_khi_viet(): void
    {
        $a = $this->article();
        $ai = $this->fake(5.0);
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy(budgetVnd: 1.0)))->run($a, null);

        $this->assertSame('blocked', $run->status);
        $this->assertSame(0, $ai->writeCalls);
        $stages = $run->steps->pluck('stage')->all();
        $this->assertNotContains('write', $stages);
        $this->assertContains('research', $stages);
        $handoff = $run->steps->firstWhere('stage', 'handoff');
        $this->assertSame('warn', $handoff->status);
        $this->assertContains('decision', $stages);
        $this->assertSame('planned', $a->refresh()->workflow_status);
        $this->assertNull($run->final_version_id);
    }

    public function test_dan_y_co_san_bo_goi_ai_dan_y(): void
    {
        $ai = $this->fake();
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article(), null, 'manual', null, ['Mở đầu', 'Điều kiện']);

        $this->assertSame(0, $ai->outlineCalls);
        $brief = $run->steps->firstWhere('stage', 'brief');
        $this->assertSame('skipped', $brief->status);
        $this->assertSame('Dùng dàn ý có sẵn', $brief->title);
        $this->assertTrue($brief->data['preset']);
        $this->assertSame(['Mở đầu', 'Điều kiện'], $brief->data['outline']);
        $this->assertContains('research', $run->steps->pluck('stage')->all());
        $this->assertSame('ready_for_review', $run->status);
    }

    public function test_khong_truyen_dan_y_thi_nhu_cu(): void
    {
        $ai = $this->fake();
        $run = (new AgentRunOrchestrator($ai, policy: new RunPolicy))->run($this->article(), null);
        $this->assertSame(1, $ai->outlineCalls);
        $brief = $run->steps->firstWhere('stage', 'brief');
        $this->assertSame('ok', $brief->status);
        $this->assertArrayNotHasKey('preset', $brief->data);
    }
}
