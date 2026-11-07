<?php

namespace Tests\Feature\Filament;

use App\Filament\Support\SeoAudit\CrawlerIssueMap;
use App\Filament\Support\SeoAudit\DbSeoAuditSource;
use App\Filament\Support\SeoAudit\ScanBlocked;
use App\Filament\Support\SeoAudit\SeoAuditService;
use App\Models\CrawledUrl;
use App\Models\SeoAudit;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AG-U05 + AG-K04: Audit đọc dữ liệu THẬT của crawler 1.1 và ánh xạ mã lỗi về danh mục P0/P1/P2.
 */
class DbSeoAuditSourceTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function audit(string $status, string $startUtc, string $endUtc, array $summary = [], ?Website $site = null): SeoAudit
    {
        return SeoAudit::create([
            'website_id' => ($site ?? $this->site)->id, 'audit_type' => 'full_sitemap', 'status' => $status,
            'total_urls_scanned' => 0, 'average_seo_score' => 72.5, 'summary_issues' => $summary,
            'started_at' => CarbonImmutable::parse($startUtc, 'UTC'), 'completed_at' => CarbonImmutable::parse($endUtc, 'UTC'),
        ]);
    }

    private function page(string $url, string $crawledUtc, array $issues, array $x = []): CrawledUrl
    {
        return CrawledUrl::create($x + [
            'website_id' => $this->site->id, 'url' => $url, 'http_status' => 200, 'title' => 'Tiêu đề trang mẫu của '.$url,
            'h1_count' => 1, 'images_count' => 5, 'missing_alt_count' => 0, 'issues' => $issues,
            'last_crawled_at' => CarbonImmutable::parse($crawledUtc, 'UTC'),
        ]);
    }

    public function test_chua_co_ket_qua_thi_status_none(): void
    {
        $this->assertSame(['status' => 'none', 'run' => null], (new DbSeoAuditSource())->latest($this->site));
    }

    public function test_anh_xa_ma_loi_ve_danh_muc_voi_bang_chung_that(): void
    {
        $this->audit('completed', '2026-11-06 01:00:00', '2026-11-06 01:10:00');
        $this->page('https://a.vn/x', '2026-11-06 01:05:00', ['missing_title', 'missing_alt:3', 'title_too_long'], ['title' => str_repeat('T', 70), 'missing_alt_count' => 3]);
        $this->page('https://a.vn/y', '2026-11-06 01:06:00', ['missing_meta_description']);

        $run = (new DbSeoAuditSource())->latest($this->site)['run'];
        $byType = collect($run['groups'])->keyBy('type');

        $this->assertSame('P0', $byType['missing_title']['severity']);
        $this->assertSame('P1', $byType['missing_meta']['severity']);
        $this->assertSame('P2', $byType['image_no_alt']['severity']);
        $this->assertSame('3 ảnh thiếu alt', $byType['image_no_alt']['items'][0]['measured']);
        $this->assertSame('70 ký tự', $byType['title_long']['items'][0]['measured']);
        $this->assertFalse($run['sample']);
        $this->assertSame('url_average_v1', $run['score_method']);
        $this->assertSame(72.5, $run['average_score']);
        $this->assertSame(2, $run['urls_crawled']);
    }

    public function test_ma_chua_co_trong_danh_muc_duoc_dem_rieng_khong_bi_nuot(): void
    {
        $this->audit('completed', '2026-11-06 01:00:00', '2026-11-06 01:10:00');
        $this->page('https://a.vn/x', '2026-11-06 01:05:00', ['http_error_500']);
        $this->page('https://a.vn/y', '2026-11-06 01:06:00', ['meta_description_too_long', 'missing_json_ld_schema']);
        $this->page('https://a.vn/z', '2026-11-06 01:07:00', ['missing_json_ld_schema']);

        $run = (new DbSeoAuditSource())->latest($this->site)['run'];

        $this->assertSame([], $run['groups']);
        $this->assertSame(2, $run['unmapped']['Thiếu dữ liệu có cấu trúc JSON-LD']);
        $this->assertSame(1, $run['unmapped']['Meta description quá dài']);
        $this->assertSame(1, $run['unmapped']['Lỗi HTTP 500']);
    }

    public function test_xu_huong_so_voi_lan_quet_truoc_tu_summary_issues(): void
    {
        $this->audit('completed', '2026-11-05 01:00:00', '2026-11-05 01:10:00', ['missing_title' => 3, 'missing_alt' => 2]);
        $this->audit('completed', '2026-11-06 01:00:00', '2026-11-06 01:10:00');
        $this->page('https://a.vn/x', '2026-11-06 01:05:00', ['missing_title']);

        $run = (new DbSeoAuditSource())->latest($this->site)['run'];
        $g = collect($run['groups'])->keyBy('type');

        $this->assertSame(-2, $g['missing_title']['delta']);   // 3 -> 1
        $this->assertNotNull($run['prev_at']);
        $this->assertSame(5, $run['previous_total']);
    }

    public function test_chi_lay_trang_thuoc_lan_quet_va_bo_qua_lan_loi_hoac_dang_chay(): void
    {
        $this->audit('completed', '2026-11-06 01:00:00', '2026-11-06 01:10:00');
        $this->audit('failed', '2026-11-07 01:00:00', '2026-11-07 01:02:00');
        $this->audit('running', '2026-11-07 02:00:00', '2026-11-07 02:00:00');
        $this->page('https://a.vn/in', '2026-11-06 01:05:00', ['missing_title']);
        $this->page('https://a.vn/old', '2026-10-01 01:05:00', ['missing_title']);

        $run = (new DbSeoAuditSource())->latest($this->site)['run'];

        $this->assertSame(1, $run['urls_crawled']);
        $this->assertSame('https://a.vn/in', $run['groups'][0]['items'][0]['url']);
    }

    public function test_website_khac_khong_lan_du_lieu(): void
    {
        $b = Website::create(['name' => 'Site B', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->audit('completed', '2026-11-06 01:00:00', '2026-11-06 01:10:00', [], $b);

        $this->assertSame('none', (new DbSeoAuditSource())->latest($this->site)['status']);
    }

    public function test_dich_vu_uu_tien_du_lieu_that_va_khong_tron_so_mau(): void
    {
        $this->audit('completed', '2026-11-06 01:00:00', '2026-11-06 01:10:00');
        $this->page('https://a.vn/x', '2026-11-06 01:05:00', ['missing_title']);

        $snap = SeoAuditService::make()->snapshot($this->site);

        $this->assertSame('completed', $snap['status']);
        $this->assertFalse($snap['run']['sample']);
        $this->assertSame(1, $snap['run']['urls_crawled']);
    }

    public function test_website_chua_quet_o_production_khong_hien_so_mau(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $snap = SeoAuditService::make()->snapshot($this->site);

        $this->assertSame('crawler_off', $snap['status']);
        $this->assertNull($snap['run']);
    }

    public function test_quet_lai_tu_giao_dien_bi_chan_khi_co_ket_qua_that(): void
    {
        $this->audit('completed', '2026-11-06 01:00:00', '2026-11-06 01:10:00');
        $user = User::create(['name' => 'ap', 'email' => 'ap@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);

        $this->expectException(ScanBlocked::class);
        (new DbSeoAuditSource())->scan($this->site, $user);
    }

    public function test_bang_anh_xa(): void
    {
        $this->assertSame('image_no_alt', CrawlerIssueMap::type('missing_alt:3'));
        $this->assertSame('title_short', CrawlerIssueMap::type('title_too_short'));
        $this->assertSame('broken_link', CrawlerIssueMap::type('http_error_404'));
        $this->assertNull(CrawlerIssueMap::type('http_error_500'));
        $this->assertNull(CrawlerIssueMap::type('ma_la'));
        $this->assertSame('Lỗi HTTP 500', CrawlerIssueMap::noCatalogLabel('http_error_500'));
        $this->assertSame('url_average_v1', CrawlerIssueMap::SCORE_METHOD);
        // mọi loại được ánh xạ phải tồn tại trong danh mục giao diện
        foreach (CrawlerIssueMap::MAP as $code => $type) {
            $this->assertNotNull(CrawlerIssueMap::type($code), "Mã {$code} -> {$type} phải có trong IssueCatalog");
        }
    }
}
