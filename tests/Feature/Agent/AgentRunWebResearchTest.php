<?php

namespace Tests\Feature\Agent;

use App\Contracts\AiProviderInterface;
use App\Contracts\StructuredAiInterface;
use App\Models\Article;
use App\Models\KnowledgeSource;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Knowledge\Build\SafePageFetcher;
use App\Services\Knowledge\Discover\SourceDiscoveryService;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Knowledge\Build\FakeSafety;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** Đội AI tự tìm nguồn web khi website không đủ. Mọi thứ giả: Tavily, trang ngoài, AI. */
class AgentRunWebResearchTest extends TestCase
{
    use RefreshDatabase;

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

        return Article::create(['website_id' => $w->id, 'primary_keyword' => 'người tiểu đường nên ăn gì', 'workflow_status' => 'planned']);
    }

    private function writer(): AiProviderInterface
    {
        return new class implements AiProviderInterface
        {
            public function generateOutline(Article $article, array $options = []): array
            {
                return ['outline' => ['Mục 1'], 'tokens_used' => 1, 'cost_vnd' => 0.0, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                return QualityFixtures::goodAttrs((string) $article->primary_keyword) + ['tokens_used' => 1, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
    }

    private function bindDiscovery(): void
    {
        $ai = new class implements StructuredAiInterface
        {
            public int $n = 0;

            public function generateStructured(string $system, string $user): array
            {
                $this->n++;

                return ['data' => ['items' => [['title' => 'Chế độ ăn '.['một', 'hai', 'ba', 'bốn'][$this->n - 1], 'body' => 'Nên ăn nhiều rau xanh, loại '.['cải', 'muống', 'dền', 'ngót'][$this->n - 1].'.', 'type' => 'fact', 'topic' => 'Chung', 'tags' => [], 'confidence' => 90, 'source_span' => 'nên ăn nhiều rau xanh']]], 'tokens_used' => 10, 'cost_vnd' => 5.0, 'model' => 'fake'];
            }
        };
        $this->app->instance(SourceDiscoveryService::class, new SourceDiscoveryService(new SafePageFetcher(new FakeSafety, 0), null, null, null, $ai, null, 'AI giả'));
    }

    private function noSite(): \Closure
    {
        return function () {
            throw new KnowledgeError('Website không đọc được.');
        };
    }

    public function test_kho_trong_website_khong_doc_duoc_co_khoa_tim_va_nap_toi_da_3_nguon(): void
    {
        config(['search.tavily.key' => 'tvly-T']);
        $rows = [];
        foreach (['who.int', 'cdc.gov', 'nih.gov', 'nhs.uk', 'blog.vn'] as $i => $d) {
            $rows[] = ['title' => 'T'.$i, 'url' => 'https://'.$d.'/p'.$i, 'content' => 'c', 'score' => 1 - $i / 10];
        }
        $fake = [
            'api.tavily.com/search' => Http::response(['results' => $rows]),
            '*/robots.txt' => Http::response('', 404),
        ];
        foreach (['who.int', 'cdc.gov', 'nih.gov', 'nhs.uk', 'blog.vn'] as $i => $d) {
            $fake[$d.'/p'.$i] = Http::response('<html><head><title>Trang '.$i.'</title></head><body><p>Người tiểu đường nên ăn nhiều rau xanh mỗi ngày.</p><p>'.str_repeat('Thông tin tham khảo cho cộng đồng '.['alpha', 'bravo', 'charlie', 'delta', 'echo'][$i].' khác nhau. ', 8).'</p></body></html>', 200, ['Content-Type' => 'text/html']);
        }
        Http::fake($fake);
        $this->bindDiscovery();

        $run = (new AgentRunOrchestrator($this->writer(), policy: new RunPolicy, knowledgeBuilder: $this->noSite()))->run($this->article(), null);

        $this->assertNotSame('blocked', $run->status);
        $web = $run->steps->where('stage', 'research')->firstWhere('title', 'Tự tìm nguồn trên web để bổ sung kiến thức');
        $this->assertNotNull($web);
        $this->assertSame('ok', $web->status);
        $this->assertSame(3, $web->data['imported']);
        $this->assertCount(3, $web->data['web_sources']);
        $this->assertSame(3, $web->data['items_auto']);
        $this->assertStringContainsString('nạp 3 nguồn', $web->detail);
        $this->assertLessThanOrEqual((new RunPolicy)->budgetVnd * 0.15 + 0.01, (float) $web->cost_vnd);
        $this->assertSame(0, KnowledgeSource::where('url', 'like', '%blog.vn%')->count());
    }

    public function test_khong_khoa_viet_tiep_va_step_warn_co_cau_chua_cau_hinh(): void
    {
        config(['search.tavily.key' => '']);
        Http::fake();
        $run = (new AgentRunOrchestrator($this->writer(), policy: new RunPolicy, knowledgeBuilder: $this->noSite()))->run($this->article(), null);

        $this->assertNotSame('blocked', $run->status);
        $warn = $run->steps->where('stage', 'research')->firstWhere('status', 'warn');
        $this->assertStringContainsString('Chưa cấu hình tìm kiếm web', $warn->detail);
        $this->assertStringContainsString('TAVILY_API_KEY', $warn->detail);
        Http::assertNothingSent();
    }

    public function test_loi_mang_khong_lam_hong_phien(): void
    {
        config(['search.tavily.key' => 'tvly-T']);
        Http::fake(['api.tavily.com/search' => Http::response('', 500)]);
        $this->bindDiscovery();
        $run = (new AgentRunOrchestrator($this->writer(), policy: new RunPolicy, knowledgeBuilder: $this->noSite()))->run($this->article(), null);

        $this->assertSame('ready_for_review', $run->status);
        $s = $run->steps->where('stage', 'research')->firstWhere('title', 'Chưa tìm được nguồn trên web');
        $this->assertSame('warn', $s->status);
        $this->assertStringNotContainsString('tvly-T', (string) $s->detail);
    }
}
