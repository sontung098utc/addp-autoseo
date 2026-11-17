<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Services\Search\GeminiSearchProvider;
use App\Services\Search\WebSearchResolver;
use App\Services\Search\WebSearchUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tìm web bằng Gemini (Google Search grounding), phản hồi GIẢ bằng Http::fake: kiểm cách đọc nguồn, giải mã chuyển hướng, lọc tên miền và tự chọn khi thiếu Tavily/cầu nối. */
class GeminiSearchTest extends TestCase
{
    use RefreshDatabase;

    private const REDIR = 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/';

    protected function setUp(): void
    {
        parent::setUp();
        config(['search.provider' => 'tavily', 'search.tavily.key' => '']);
        AiProvider::create(['name' => 'Google Gemini', 'kind' => 'gemini', 'api_key' => str_repeat('g', 39), 'enabled' => true]);
    }

    private function fakeGemini(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [[
                'content' => ['parts' => [['text' => 'văn của model, không dùng làm tài liệu']]],
                'groundingMetadata' => [
                    'groundingChunks' => [
                        ['web' => ['uri' => self::REDIR.'a', 'title' => 'who.int']],
                        ['web' => ['uri' => self::REDIR.'b', 'title' => 'blog-xau.com']],
                        ['web' => ['uri' => 'javascript:alert(1)', 'title' => 'x']],
                        ['web' => ['uri' => 'https://www.nhs.uk/c', 'title' => 'NHS']],
                    ],
                    'groundingSupports' => [['segment' => ['text' => 'HbA1c từ 6,5% trở lên là đái tháo đường.'], 'groundingChunkIndices' => [0, 3]]],
                ],
            ]]]),
            'vertexaisearch.cloud.google.com/grounding-api-redirect/a' => Http::response('', 302, ['Location' => 'https://www.who.int/news-room/hba1c']),
            'vertexaisearch.cloud.google.com/grounding-api-redirect/b' => Http::response('', 302, ['Location' => 'https://blog-xau.com/p']),
        ]);
    }

    public function test_doc_nguon_giai_ma_chuyen_huong_va_loc_ten_mien(): void
    {
        $this->fakeGemini();
        $r = (new GeminiSearchProvider)->search('HbA1c', ['include_domains' => ['who.int', 'nhs.uk'], 'max_results' => 8]);
        $urls = array_column($r['results'], 'url');
        $this->assertSame(['https://www.who.int/news-room/hba1c', 'https://www.nhs.uk/c'], $urls, 'Giải mã Location, bỏ tên miền ngoài danh sách và liên kết không phải http(s).');
        $this->assertSame('gemini', $r['provider']);
        $this->assertStringContainsString('6,5%', $r['results'][0]['snippet']);
        Http::assertSent(fn ($req) => str_contains($req->url(), ':generateContent') && isset($req['tools'][0]['google_search']) && $req->hasHeader('x-goog-api-key', str_repeat('g', 39)));
    }

    public function test_loai_tru_ten_mien_va_gioi_han_so_ket_qua(): void
    {
        $this->fakeGemini();
        $r = (new GeminiSearchProvider)->search('HbA1c', ['exclude_domains' => ['blog-xau.com'], 'max_results' => 2]);
        $this->assertCount(2, $r['results']);
        $this->assertNotContains('https://blog-xau.com/p', array_column($r['results'], 'url'));
    }

    public function test_het_han_muc_bao_loi_khong_lo_khoa(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'quota '.str_repeat('g', 39)]], 429)]);
        try {
            (new GeminiSearchProvider)->search('x');
            $this->fail('phải ném lỗi');
        } catch (WebSearchUnavailable $e) {
            $this->assertSame(429, $e->getCode());
            $this->assertStringContainsString('hết hạn mức', $e->getMessage());
            $this->assertStringNotContainsString(str_repeat('g', 39), $e->getMessage());
        }
    }

    public function test_khong_co_tavily_va_cau_noi_thi_resolver_dung_gemini_va_tat_khi_khong_co_khoa(): void
    {
        $res = (new WebSearchResolver)->resolve();
        $this->assertInstanceOf(GeminiSearchProvider::class, $res['provider']);
        $this->assertFalse($res['demo']);
        AiProvider::query()->update(['enabled' => false]);
        $this->assertFalse(GeminiSearchProvider::configured());
        $this->assertNotInstanceOf(GeminiSearchProvider::class, (new WebSearchResolver)->resolve()['provider'], 'Không còn Gemini bật thì không tự chọn Gemini (môi trường test rơi về bản mô phỏng có nhãn).');
    }

    public function test_co_khoa_tavily_thi_dung_tavily_truoc_va_khong_goi_ai(): void
    {
        config(['search.tavily.key' => 'tvly-test']);
        Http::fake(['api.tavily.com/*' => Http::response(['results' => [['title' => 'WHO', 'url' => 'https://www.who.int/x', 'content' => 'a']]])]);
        $res = (new WebSearchResolver)->resolve();
        $this->assertInstanceOf(\App\Services\Search\CombinedSearchProvider::class, $res['provider']);
        $r = $res['provider']->search('HbA1c');
        $this->assertSame('tavily', $r['provider']);
        $this->assertArrayNotHasKey('failed', $r);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'generativelanguage'));
    }

    public function test_tavily_ket_qua_rong_hop_le_khong_kich_hoat_du_phong(): void
    {
        config(['search.tavily.key' => 'tvly-test']);
        Http::fake(['api.tavily.com/*' => Http::response(['results' => []])]);
        $r = (new WebSearchResolver)->resolve()['provider']->search('HbA1c', ['include_domains' => ['who.int']]);
        $this->assertSame([], $r['results']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'generativelanguage'));
    }

    public function test_tavily_het_han_muc_thi_chuyen_sang_ai_tu_tim(): void
    {
        config(['search.tavily.key' => 'tvly-test']);
        Http::fake([
            'api.tavily.com/*' => Http::response(['detail' => 'plan limit'], 432),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['groundingMetadata' => ['groundingChunks' => [['web' => ['uri' => 'https://www.nhs.uk/c', 'title' => 'NHS']]]]]]]),
        ]);
        $r = (new WebSearchResolver)->resolve()['provider']->search('HbA1c');
        $this->assertSame('gemini', $r['provider']);
        $this->assertSame(['https://www.nhs.uk/c'], array_column($r['results'], 'url'));
        $this->assertNotEmpty($r['failed'], 'Ghi lại lý do Tavily không dùng được.');
    }

    public function test_ca_hai_ben_loi_thi_bao_loi(): void
    {
        config(['search.tavily.key' => 'tvly-test']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'x']], 500)]);
        $this->expectException(WebSearchUnavailable::class);
        (new WebSearchResolver)->resolve()['provider']->search('HbA1c');
    }
}
