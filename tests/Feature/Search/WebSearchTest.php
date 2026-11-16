<?php

namespace Tests\Feature\Search;

use App\Services\Search\MockWebSearchProvider;
use App\Services\Search\TavilySearchProvider;
use App\Services\Search\WebSearchResolver;
use App\Services\Search\WebSearchUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tìm web: Http GIẢ, không gọi Tavily thật. */
class WebSearchTest extends TestCase
{
    public function test_tavily_gui_dung_va_doc_ket_qua(): void
    {
        config(['search.cost_per_search_vnd' => 250.0]);
        Http::fake(['api.tavily.com/search' => Http::response(['results' => [
            ['title' => 'WHO', 'url' => 'https://www.who.int/a', 'content' => "Nội dung\n  dài", 'score' => 0.9, 'published_date' => '2026-01-01'],
            ['title' => 'Lỗi', 'url' => 'javascript:alert(1)', 'content' => 'x'],
            ['title' => '', 'url' => 'https://b.vn/x', 'content' => 'y'],
        ]])]);
        $r = (new TavilySearchProvider('tvly-KEY', 'https://api.tavily.com/search'))->search('tiểu đường', ['max_results' => 50, 'include_domains' => ['who.int'], 'exclude_domains' => ['facebook.com']]);

        $this->assertSame('tavily', $r['provider']);
        $this->assertSame(250.0, $r['cost_vnd']);
        $this->assertCount(2, $r['results']);
        $this->assertSame('Nội dung dài', $r['results'][0]['snippet']);
        $this->assertSame('2026-01-01', $r['results'][0]['published_at']);
        $this->assertSame('https://b.vn/x', $r['results'][1]['title']);
        Http::assertSent(fn ($q) => $q['api_key'] === 'tvly-KEY' && $q['search_depth'] === 'basic' && $q['max_results'] === 10 && $q['include_domains'] === ['who.int'] && $q['exclude_domains'] === ['facebook.com']);
    }

    public function test_loi_an_khoa(): void
    {
        Http::fake(['api.tavily.com/search' => Http::response('bad', 401)]);
        try {
            (new TavilySearchProvider('tvly-SECRET', 'https://api.tavily.com/search'))->search('x');
            $this->fail('phải ném lỗi');
        } catch (WebSearchUnavailable $e) {
            $this->assertStringContainsString('401', $e->getMessage());
            $this->assertStringNotContainsString('tvly-SECRET', $e->getMessage());
        }
        Http::fake(fn () => throw new ConnectionException('cURL error for {"api_key":"tvly-SECRET"} timed out'));
        try {
            (new TavilySearchProvider('tvly-SECRET', 'https://api.tavily.com/search'))->search('x');
            $this->fail('phải ném lỗi');
        } catch (WebSearchUnavailable $e) {
            $this->assertStringNotContainsString('tvly-SECRET', $e->getMessage());
        }
    }

    public function test_thieu_khoa_khong_goi_mang(): void
    {
        Http::fake();
        $this->expectException(WebSearchUnavailable::class);
        try {
            (new TavilySearchProvider('', 'https://api.tavily.com/search'))->search('x');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_resolver_thieu_khoa_o_production_khong_dung_gia(): void
    {
        config(['search.tavily.key' => '']);
        $av = (new WebSearchResolver)->availability();
        $this->assertFalse($av['ok']);
        $this->assertStringContainsString('TAVILY_API_KEY', $av['reason']);
        $this->expectException(WebSearchUnavailable::class);
        (new WebSearchResolver)->resolve();
    }

    public function test_resolver_local_dung_mock_co_nhan(): void
    {
        config(['search.tavily.key' => '', 'search.allow_mock' => true]);
        $r = (new WebSearchResolver)->resolve();
        $this->assertTrue($r['demo']);
        $this->assertInstanceOf(MockWebSearchProvider::class, $r['provider']);
        $this->assertStringContainsString('mô phỏng', mb_strtolower($r['label']));
        $res = $r['provider']->search('tiểu đường');
        $this->assertSame('mock', $res['provider']);
        $this->assertStringContainsString('[Mô phỏng]', $res['results'][0]['title']);
    }

    public function test_resolver_co_khoa_dung_tavily_va_tat_duoc(): void
    {
        config(['search.tavily.key' => 'tvly-1']);
        $r = (new WebSearchResolver)->resolve();
        $this->assertFalse($r['demo']);
        $this->assertInstanceOf(TavilySearchProvider::class, $r['provider']);
        config(['search.enabled' => false]);
        $this->assertFalse((new WebSearchResolver)->availability()['ok']);
    }
}
