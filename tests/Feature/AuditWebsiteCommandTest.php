<?php

namespace Tests\Feature;

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
use Tests\TestCase;

class AuditWebsiteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_fails_if_website_does_not_exist(): void
    {
        $this->artisan('autoseo:audit-website 9999')
            ->expectsOutputToContain('Không tìm thấy website')
            ->assertExitCode(1);
    }

    public function test_command_audits_specific_url_successfully(): void
    {
        $website = Website::create([
            'name' => 'Demo Pharma',
            'cms_type' => 'wordpress',
            'base_url' => 'https://example.com',
            'status' => 'active',
            'encrypted_credentials' => ['username' => 'test'],
        ]);

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Sản Phẩm Dược Phẩm Đạt Chuẩn GMP Quốc Tế</title>
    <meta name="description" content="Danh mục đầy đủ các dòng sản phẩm viên nén và viên nang tiêu chuẩn GMP.">
    <link rel="canonical" href="https://example.com/san-pham">
    <script type="application/ld+json">{"@type":"Product"}</script>
</head>
<body>
    <h1>Danh Mục Sản Phẩm</h1>
    <h2>Nhóm Thuốc Tim Mạch</h2>
</body>
</html>
HTML;

        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'text/html'], $html),
        ]);
        $mockClient = new HttpClient(['handler' => HandlerStack::create($mockHandler)]);

        $safety = new UrlSafetyService();
        $customAuditService = new SeoAuditService(
            new SitemapCrawlerService($safety, $mockClient),
            new HtmlSeoExtractor(),
            $safety,
            $mockClient
        );

        $this->app->instance(SeoAuditService::class, $customAuditService);

        $this->artisan("autoseo:audit-website {$website->id} --url=https://example.com/san-pham")
            ->expectsOutputToContain('Bắt đầu thu thập & Audit SEO')
            ->expectsOutputToContain('Điểm SEO Health')
            ->assertExitCode(0);
    }
}
