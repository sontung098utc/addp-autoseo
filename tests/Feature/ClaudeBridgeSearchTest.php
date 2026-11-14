<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Services\Search\ClaudeBridgeSearchProvider;
use App\Services\Search\WebSearchResolver;
use App\Services\Search\WebSearchUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tìm web qua cầu nối Claude (cầu nối GIẢ bằng Http::fake): không có khóa Tavily vẫn có tài liệu thật. */
class ClaudeBridgeSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai_models.private_base_url_allowlist' => ['172.17.0.1:8771'], 'search.provider' => 'tavily', 'search.tavily.key' => '']);
    }

    private function provider(bool $enabled = true): void
    {
        AiProvider::create(['name' => ClaudeBridgeSearchProvider::PROVIDER_NAME, 'kind' => 'openai_compat', 'base_url' => 'http://172.17.0.1:8771/v1', 'api_key' => str_repeat('k', 40), 'enabled' => $enabled]);
    }

    public function test_tim_web_qua_cau_noi_chi_nhan_lien_ket_http(): void
    {
        $this->provider();
        Http::fake(['172.17.0.1:8771/v1/search' => Http::response(['results' => [
            ['title' => 'NHS', 'url' => 'https://www.nhs.uk/x', 'content' => str_repeat('Huyết áp thấp là dưới 90/60. ', 8)],
            ['title' => 'Xấu', 'url' => 'javascript:alert(1)', 'content' => 'x'],
        ]])]);
        $r = (new ClaudeBridgeSearchProvider)->search('huyết áp thấp', ['max_results' => 4, 'include_domains' => ['nhs.uk']]);
        $this->assertCount(1, $r['results']);
        $this->assertSame('claude_bridge', $r['provider']);
        Http::assertSent(fn ($req) => $req->hasHeader('Authorization', 'Bearer '.str_repeat('k', 40)) && $req['include_domains'] === ['nhs.uk']);
    }

    public function test_khong_co_khoa_tavily_thi_resolver_dung_cau_noi_neu_da_cai(): void
    {
        $this->assertFalse(ClaudeBridgeSearchProvider::configured());
        $this->provider();
        $res = (new WebSearchResolver)->resolve();
        $this->assertInstanceOf(ClaudeBridgeSearchProvider::class, $res['provider']);
        $this->assertFalse($res['demo']);
    }

    public function test_cau_noi_loi_hoac_dia_chi_khong_duoc_phep_thi_bao_khong_dung_duoc(): void
    {
        $this->provider();
        Http::fake(['*' => Http::response(['error' => ['message' => 'x']], 429)]);
        try {
            (new ClaudeBridgeSearchProvider)->search('x');
            $this->fail('phải ném lỗi');
        } catch (WebSearchUnavailable $e) {
            $this->assertStringContainsString('hết hạn mức', $e->getMessage());
            $this->assertSame(429, $e->getCode());
        }
        config(['ai_models.private_base_url_allowlist' => []]);
        $this->expectException(WebSearchUnavailable::class);
        (new ClaudeBridgeSearchProvider)->search('x');
    }

    public function test_het_han_muc_claude_thi_phien_dung_ro_rang_thay_vi_viet_tiep_thieu_nguon(): void
    {
        $this->provider();
        Http::fake(['172.17.0.1:8771/v1/search' => Http::response(['error' => ['message' => "Claude CLI lỗi: You've hit your session limit · resets 10:50am"]], 429)]);
        $this->app->detectEnvironment(fn () => 'local');
        $site = \App\Models\Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $r = (new \App\Services\Content\ReferenceDossier)->gather($site, 'máy dập viên');
        $this->assertSame('quota', $r['status']);
        $this->assertStringContainsString('resets 10:50am', (string) $r['reason'], 'Thông báo nêu giờ hạn mức đặt lại.');

        $article = \App\Models\Article::create(['website_id' => $site->id, 'primary_keyword' => 'máy dập viên', 'workflow_status' => 'planned']);
        $ai = new class implements \App\Contracts\AiProviderInterface
        {
            public function generateOutline(\App\Models\Article $article, array $options = []): array
            {
                return ['outline' => ['A'], 'tokens_used' => 1, 'model' => 'fake'];
            }

            public function generateArticle(\App\Models\Article $article, array $outline, array $options = []): array
            {
                throw new \RuntimeException('Không được viết khi hết hạn mức.');
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
        $run = (new \App\Services\Agent\Run\AgentRunOrchestrator($ai, sleep: fn () => null))->run($article);
        $this->assertSame('failed', $run->status);
        $this->assertSame('planned', $article->fresh()->workflow_status, 'Bài về trạng thái cũ, không có bản nháp thiếu nguồn.');
        $this->assertTrue($run->steps->contains(fn ($s) => str_contains($s->title, 'Hết hạn mức')));
    }
}
