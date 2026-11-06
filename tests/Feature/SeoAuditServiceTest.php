<?php

namespace Tests\Feature;

use App\Models\CrawledUrl;
use App\Models\SeoAudit;
use App\Models\Website;
use App\Services\Crawler\HtmlSeoExtractor;
use App\Services\Crawler\SeoAuditService;
use App\Services\Crawler\SitemapCrawlerService;
use App\Services\Security\UrlSafetyService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SeoAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    protected Website $website;

    protected function setUp(): void
    {
        parent::setUp();

        $this->website = Website::create([
            'name' => 'Test Medical Site',
            'cms_type' => 'wordpress',
            'base_url' => 'https://example.com',
            'status' => 'active',
            'encrypted_credentials' => ['username' => 'test'],
        ]);
    }

    public function test_it_audits_single_url_and_persists_to_database(): void
    {
        $mockHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Tiêu Đề Trang Chuẩn SEO Tối Ưu E-E-A-T</title>
    <meta name="description" content="Mô tả nội dung bài viết kỹ lưỡng đầy đủ thông tin chuẩn Google Search Central.">
    <link rel="canonical" href="https://example.com/page-1">
    <script type="application/ld+json">{"@context":"https://schema.org","@type":"Article"}</script>
</head>
<body>
    <h1>Bài Viết Chuẩn SEO</h1>
    <h2>Mục 1</h2>
    <p>Nội dung</p>
</body>
</html>
HTML;

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $mockHtml),
        ]);
        $mockClient = new HttpClient(['handler' => HandlerStack::create($mockHandler)]);

        $service = new SeoAuditService(
            new SitemapCrawlerService(new UrlSafetyService(), $mockClient),
            new HtmlSeoExtractor(),
            new UrlSafetyService(),
            $mockClient
        );

        $crawled = $service->auditSingleUrl($this->website, 'https://example.com/page-1');

        $this->assertInstanceOf(CrawledUrl::class, $crawled);
        $this->assertEquals('https://example.com/page-1', $crawled->url);
        $this->assertEquals(200, $crawled->http_status);
        $this->assertEquals('Tiêu Đề Trang Chuẩn SEO Tối Ưu E-E-A-T', $crawled->title);
        $this->assertTrue($crawled->has_schema);
        $this->assertGreaterThanOrEqual(80, $crawled->seo_score);

        $this->assertDatabaseHas('crawled_urls', [
            'website_id' => $this->website->id,
            'url' => 'https://example.com/page-1',
        ]);
    }

    public function test_it_blocks_ssrf_attacks_when_auditing_url(): void
    {
        $service = app(SeoAuditService::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('thuộc dải mạng nội bộ/bị chặn');

        $service->auditSingleUrl($this->website, 'http://127.0.0.1/admin');
    }

    public function test_it_runs_full_audit_and_creates_seo_audit_record(): void
    {
        $sitemapXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>https://example.com/bai-1</loc></url>
    <url><loc>https://example.com/bai-2</loc></url>
</urlset>
XML;

        $htmlPage1 = '<html><head><title>Trang 1 Chuẩn Đầy Đủ Thông Tin SEO</title><meta name="description" content="Mô tả chuẩn SEO đầy đủ độ dài theo tiêu chuẩn Google Search."><link rel="canonical" href="https://example.com/bai-1"><script type="application/ld+json">{"@type":"WebPage"}</script></head><body><h1>Trang 1</h1><h2>H2</h2></body></html>';
        $htmlPage2 = '<html><head><title>Trang 2</title></head><body><img src="no-alt.jpg"></body></html>'; // lỗi thiếu desc, canonical, h1, ảnh thiếu alt

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/xml'], $sitemapXml),
            new Response(200, ['Content-Type' => 'text/html'], $htmlPage1),
            new Response(200, ['Content-Type' => 'text/html'], $htmlPage2),
        ]);
        $mockClient = new HttpClient(['handler' => HandlerStack::create($mockHandler)]);

        $safety = new UrlSafetyService();
        $sitemapCrawler = new SitemapCrawlerService($safety, $mockClient);
        $service = new SeoAuditService($sitemapCrawler, new HtmlSeoExtractor(), $safety, $mockClient);

        $audit = $service->runFullAudit($this->website, 'https://example.com/sitemap.xml', 10);

        $this->assertInstanceOf(SeoAudit::class, $audit);
        $this->assertEquals('completed', $audit->status);
        $this->assertEquals(2, $audit->total_urls_scanned);
        $this->assertGreaterThan(0, $audit->average_seo_score);
        $this->assertDatabaseCount('crawled_urls', 2);
    }
}
