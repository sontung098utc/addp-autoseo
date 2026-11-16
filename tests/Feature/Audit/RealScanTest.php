<?php

namespace Tests\Feature\Audit;

use App\Filament\Pages\AuditPage;
use App\Models\SeoAudit;
use App\Models\User;
use App\Models\Website;
use App\Services\Audit\RealScanService;
use App\Services\Crawler\HtmlSeoExtractor;
use App\Services\Crawler\SeoAuditService as Crawler;
use App\Services\Crawler\SitemapCrawlerService;
use App\Services\Security\UrlSafetyService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RealScanTest extends TestCase
{
    use RefreshDatabase;

    private function safety(): UrlSafetyService
    {
        return new class extends UrlSafetyService {
            protected function resolveIps(string $host): array
            {
                return str_starts_with($host, 'noi-bo') ? ['10.0.0.5'] : ['93.184.216.34'];
            }
        };
    }

    private function site(string $url = 'https://blog.abc.vn'): Website
    {
        return Website::create(['name' => 'blog', 'cms_type' => 'wordpress', 'base_url' => $url, 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function as(string $role): User
    {
        $u = User::create(['name' => 'U', 'email' => $role.uniqid().'@x.vn', 'password' => 'secret-pass', 'role' => $role, 'is_active' => true]);
        $this->actingAs($u);

        return $u;
    }

    private function fakeCrawler(array $responses): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        $safety = $this->safety();
        app()->instance(UrlSafetyService::class, $safety);
        app()->instance(Crawler::class, new Crawler(new SitemapCrawlerService($safety, $client), new HtmlSeoExtractor(), $safety, $client));
    }

    public function test_tran_so_trang_ep_ve_50(): void
    {
        $this->assertSame(50, RealScanService::clamp(9999));
        $this->assertSame(1, RealScanService::clamp(-3));
        $this->assertSame(20, RealScanService::clamp(20));
    }

    public function test_viewer_va_writer_khong_chay_duoc(): void
    {
        $this->site();
        $this->as('writer');
        $this->fakeCrawler([]);
        Livewire::test(AuditPage::class)->assertDontSee('Quét thật (giới hạn')->call('openRealScan')->assertSet('realScanOpen', false)->call('realScan');
        $this->assertSame(0, SeoAudit::count());
    }

    public function test_ngoai_allowlist_bao_loi_va_khong_ghi_du_lieu(): void
    {
        $this->site('https://noi-bo.test');
        $this->as('approver');
        $this->fakeCrawler([]);
        Livewire::test(AuditPage::class)->call('realScan');
        $this->assertSame(0, SeoAudit::count());
    }

    public function test_thanh_cong_ra_run_that_co_tong_url(): void
    {
        $site = $this->site();
        $this->as('approver');
        $sitemap = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://blog.abc.vn/a</loc></url><url><loc>https://blog.abc.vn/b</loc></url></urlset>';
        $page = '<html><head><title>T</title></head><body><h1>x</h1></body></html>';
        $this->fakeCrawler([new Response(200, [], "Sitemap: https://blog.abc.vn/sitemap.xml"), new Response(200, [], $sitemap), new Response(200, [], $page), new Response(200, [], $page)]);
        $c = Livewire::test(AuditPage::class)->call('openRealScan')->assertSet('realScanOpen', true)->set('realScanLimit', 999)->call('realScan');
        $c->assertSet('realScanLimit', 50)->assertSet('realScanOpen', false);
        $audit = SeoAudit::first();
        $this->assertNotNull($audit);
        $this->assertSame('completed', $audit->status);
        $this->assertSame(2, (int) $audit->total_urls_scanned);
        $snap = \App\Filament\Support\SeoAudit\SeoAuditService::make()->snapshot($site);
        $this->assertFalse($snap['run']['sample']);
    }
}
