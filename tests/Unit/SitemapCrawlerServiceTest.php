<?php

namespace Tests\Unit;

use App\Models\Website;
use App\Services\Crawler\SitemapCrawlerService;
use App\Services\Security\UrlSafetyService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class SitemapCrawlerServiceTest extends TestCase
{
    public function test_it_filters_out_media_and_assets(): void
    {
        $crawler = new SitemapCrawlerService(new UrlSafetyService());

        $this->assertTrue($crawler->isValidPageUrl('https://example.com/bai-viet-1', 'example.com'));
        $this->assertTrue($crawler->isValidPageUrl('https://example.com/danh-muc/san-pham', 'example.com'));

        // Phải loại bỏ file ảnh/pdf/tài nguyên
        $this->assertFalse($crawler->isValidPageUrl('https://example.com/images/banner.jpg', 'example.com'));
        $this->assertFalse($crawler->isValidPageUrl('https://example.com/files/catalog.pdf', 'example.com'));
        $this->assertFalse($crawler->isValidPageUrl('https://example.com/assets/app.js', 'example.com'));

        // Phải loại bỏ domain ngoài
        $this->assertFalse($crawler->isValidPageUrl('https://google.com/search', 'example.com'));
    }

    public function test_it_parses_urlset_xml_properly(): void
    {
        $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>https://example.com/bai-viet-seo-1</loc>
        <lastmod>2026-11-01</lastmod>
        <changefreq>daily</changefreq>
        <priority>0.8</priority>
    </url>
    <url>
        <loc>https://example.com/bai-viet-seo-2</loc>
        <lastmod>2026-11-02</lastmod>
    </url>
    <url>
        <!-- Bị loại bỏ vì là ảnh -->
        <loc>https://example.com/hinh-anh.jpg</loc>
    </url>
</urlset>
XML;

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/xml'], $xmlContent),
        ]);
        $client = new HttpClient(['handler' => HandlerStack::create($mockHandler)]);

        $safetyService = new UrlSafetyService();
        $crawler = new SitemapCrawlerService($safetyService, $client);

        $website = new Website([
            'base_url' => 'https://example.com',
            'name' => 'Example Website',
        ]);

        $urls = $crawler->discoverUrls($website, 'https://example.com/sitemap.xml');

        $this->assertCount(2, $urls);
        $this->assertEquals('https://example.com/bai-viet-seo-1', $urls[0]['url']);
        $this->assertEquals('https://example.com/bai-viet-seo-2', $urls[1]['url']);
    }

    public function test_it_parses_sitemap_index_recursively(): void
    {
        $indexXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap>
        <loc>https://example.com/post-sitemap.xml</loc>
    </sitemap>
</sitemapindex>
XML;

        $childXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>https://example.com/tin-tuc-cong-ty</loc>
    </url>
</urlset>
XML;

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/xml'], $indexXml),
            new Response(200, ['Content-Type' => 'application/xml'], $childXml),
        ]);
        $client = new HttpClient(['handler' => HandlerStack::create($mockHandler)]);

        $crawler = new SitemapCrawlerService(new UrlSafetyService(), $client);
        $website = new Website(['base_url' => 'https://example.com']);

        $urls = $crawler->discoverUrls($website, 'https://example.com/sitemap_index.xml');

        $this->assertCount(1, $urls);
        $this->assertEquals('https://example.com/tin-tuc-cong-ty', $urls[0]['url']);
    }
}
