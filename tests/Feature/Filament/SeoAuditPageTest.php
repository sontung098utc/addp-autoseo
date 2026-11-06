<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AuditPage;
use App\Filament\Support\Demo\DemoStore;
use App\Filament\Support\SeoAudit\ScanGuard;
use App\Filament\Support\SeoAudit\SeoAuditService;
use App\Models\User;
use App\Models\Website;
use App\Services\Security\UrlSafetyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class SeoAuditPageTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 03:00:00', 'UTC'));
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-11-06 03:00:00', 'UTC'));
        // DNS giả: domain công khai phân giải ra IP công khai, "noi-bo.test" ra IP nội bộ.
        app()->instance(UrlSafetyService::class, new class extends UrlSafetyService {
            protected function resolveIps(string $host): array
            {
                if (filter_var($host, FILTER_VALIDATE_IP)) {
                    return [$host];
                }

                return str_starts_with($host, 'noi-bo') ? ['10.0.0.5'] : ['93.184.216.34'];
            }
        });
        $this->site = Website::create(['name' => 'blog.abc.vn', 'cms_type' => 'wordpress', 'base_url' => 'https://blog.abc.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function as(string $role, bool $active = true): User
    {
        $u = User::create(['name' => 'U '.$role, 'email' => $role.uniqid().'@x.vn', 'password' => 'secret-pass', 'role' => $role, 'is_active' => $active]);
        $this->actingAs($u);

        return $u;
    }

    public function test_writer_xem_duoc_nhung_khong_quet_va_khong_tao_brief(): void
    {
        $this->as('writer');
        $c = Livewire::test(AuditPage::class)->assertSuccessful()
            ->assertSee('Liên kết gãy (404)')->assertDontSee('Dữ liệu minh họa')->assertDontSee('Lỗi SEO on-page');
        $c->call('rescan');
        $this->assertCount(0, SeoAuditService::make()->briefs());
        $c->call('createBrief')->assertSet('briefOpen', false);
        $this->assertCount(0, SeoAuditService::make()->briefs());
        // lần quét không đổi: vẫn người "Hệ thống"
        $this->assertSame('Hệ thống (dữ liệu mẫu)', DemoStore::get('seoaudit.site.'.$this->site->id.'.by'));
    }

    public function test_truy_cap(): void
    {
        $this->get('/admin/audit-website')->assertRedirect();
        $this->as('writer', false);
        $this->get('/admin/audit-website')->assertForbidden();
    }

    public function test_writer_vao_duoc_trang(): void
    {
        $this->as('writer');
        $this->get('/admin/audit-website')->assertOk()->assertSee('Audit website');
    }

    public function test_chon_nhom_loi_va_bang_bang_chung(): void
    {
        $this->as('approver');
        $c = Livewire::test(AuditPage::class)
            ->call('selectGroup', 'broken_link')->assertSet('groupType', 'broken_link');
        $this->assertCount(7, $c->viewData('evidenceRows'));
        $this->assertStringContainsString('/tai-lieu/mau-don-hoc-bong.pdf', collect($c->viewData('evidenceRows'))->pluck('evidence')->implode(' '));
        $c->call('selectGroup', 'missing_title')->assertSee('Thiếu title');
        $c->call('selectGroup', 'khong_ton_tai')->assertSet('groupType', 'missing_title');
    }

    public function test_giao_dien_tab_bang_kpi_va_nut_primary_duy_nhat(): void
    {
        $this->as('approver');
        $c = Livewire::test(AuditPage::class)->assertSee('Nhóm lỗi')->assertSee('Brief đã tạo')->assertSee('Quy tắc')
            ->assertSee('Tạo brief từ lỗi')->assertDontSee('Luật nghiệp vụ của màn này');
        $rows = $c->viewData('groupRows');
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertIsString($r['id']);
            $this->assertContains($r['severity']['tone'], ['danger', 'warn', 'muted']);
            $this->assertSame($r['severity']['text'] === 'P0' ? 'danger' : ($r['severity']['text'] === 'P1' ? 'warn' : 'muted'), $r['severity']['tone']);
        }
        $html = $c->html();
        $this->assertStringContainsString('good\u0022:\u0022down', str_replace('&quot;', '\u0022', $html));
        $this->assertSame(1, substr_count($html, 'fi-btn-color-primary'), 'đúng một nút primary');
        $stats = $c->viewData('sevStats');
        $this->assertSame(array_sum(array_column($rows, 'urls')), $stats['P0']['n'] + $stats['P1']['n'] + $stats['P2']['n']);
    }

    public function test_quet_lai_thanh_cong_roi_bi_chan_6_gio(): void
    {
        $u = $this->as('approver');
        $c = Livewire::test(AuditPage::class);
        $before = $c->viewData('run')['summary']['total'];
        $c->call('rescan');
        $this->assertSame('U approver', DemoStore::get('seoaudit.site.'.$this->site->id.'.by'));
        $after = $c->viewData('run');
        $this->assertNotSame($before, $after['summary']['total']);
        $this->assertSame($u->name, $after['triggered_by']);
        // ngay sau đó bị chặn
        $c->assertSee('tối đa 1 lần quét mỗi 6 giờ')->assertSee('Quét lại được từ 16:00 06/11/2026');
        $level = DemoStore::get('seoaudit.site.'.$this->site->id.'.level');
        $c->call('rescan');
        $this->assertSame($level, DemoStore::get('seoaudit.site.'.$this->site->id.'.level'));
        // 5h59 vẫn chặn, 6h00 được quét
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 08:59:00', 'UTC'));
        $this->assertFalse(SeoAuditService::make()->canScan($this->site, $u)['ok']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-06 09:00:00', 'UTC'));
        $this->assertTrue(SeoAuditService::make()->canScan($this->site, $u)['ok']);
        Livewire::test(AuditPage::class)->call('rescan');
        $this->assertSame($level + 1, DemoStore::get('seoaudit.site.'.$this->site->id.'.level'));
    }

    public function test_luat_allowlist(): void
    {
        $u = $this->as('admin');
        foreach (['pending', 'revoked', 'error'] as $st) {
            $w = Website::create(['name' => $st, 'cms_type' => 'wordpress', 'base_url' => 'https://'.$st.'.abc.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => $st]);
            $this->assertFalse(ScanGuard::check($w, null, $u)['ok'], $st);
            $this->assertSame('not_allowlisted', ScanGuard::check($w, null, $u)['code']);
        }
        $in = Website::create(['name' => 'nb', 'cms_type' => 'wordpress', 'base_url' => 'https://noi-bo.abc.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $r = ScanGuard::check($in, null, $u);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('nội bộ', $r['reason']);
        $lo = Website::create(['name' => 'lo', 'cms_type' => 'wordpress', 'base_url' => 'http://127.0.0.1', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->assertFalse(ScanGuard::check($lo, null, $u)['ok']);
        $this->assertTrue(ScanGuard::check($this->site, null, $u)['ok']);
        // service chặn dù gọi thẳng
        $this->expectException(\App\Filament\Support\SeoAudit\ScanBlocked::class);
        SeoAuditService::make()->rescan($in, $u);
    }

    public function test_chon_website_khong_active_bi_tu_choi(): void
    {
        $this->as('approver');
        $rev = Website::create(['name' => 'rev', 'cms_type' => 'wordpress', 'base_url' => 'https://rev.abc.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'revoked']);
        Livewire::test(AuditPage::class)->set('websiteId', $rev->id)->assertSet('websiteId', $this->site->id);
    }

    public function test_nut_quet_lai_vo_hieu_voi_website_noi_bo(): void
    {
        $this->as('approver');
        $nb = Website::create(['name' => 'nb', 'cms_type' => 'wordpress', 'base_url' => 'https://noi-bo.abc.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $c = Livewire::test(AuditPage::class)->set('websiteId', $nb->id);
        $this->assertFalse($c->viewData('canRescan'));
        $c->assertSee('Domain không được phép quét');
        $c->call('rescan');
        $this->assertNotSame('U approver', DemoStore::get('seoaudit.site.'.$nb->id.'.by'));
    }

    public function test_tao_brief_dung_noi_dung_va_luu_danh_sach(): void
    {
        $u = $this->as('approver');
        $c = Livewire::test(AuditPage::class)->call('selectGroup', 'broken_link')->call('createBrief')
            ->assertSet('briefOpen', true)->assertSee('Sao chép');
        $body = $c->get('brief')['body'];
        $this->assertStringContainsString('Sửa 7 trang: Liên kết gãy (404) trên blog.abc.vn', $body);
        $this->assertStringContainsString('Mức: P0', $body);
        $this->assertStringContainsString('/tai-lieu/mau-don-hoc-bong.pdf', $body);
        $this->assertStringContainsString('<a href="/tai-lieu/mau-don-hoc-bong.pdf">', $body);
        $this->assertStringContainsString('AI đề xuất', $body);
        $this->assertStringContainsString('không tự sửa', $body);
        $saved = SeoAuditService::make()->briefs($this->site);
        $this->assertCount(1, $saved);
        $this->assertSame($u->id, $saved[0]['created_by']);
        $this->assertSame(7, $saved[0]['urls']);
        $c->call('closeBrief')->assertSet('briefOpen', false)->assertSee('Brief đã tạo');
        $this->assertCount(1, $c->viewData('briefRows'));
    }

    public function test_trang_thai_minh_hoa_va_nhan_cu(): void
    {
        $this->as('approver');
        Livewire::withQueryParams(['demo' => 'dang-quet'])->test(AuditPage::class)
            ->assertSee('Đang quét')->assertSee('Kết quả cũ')->assertSet('briefOpen', false)
            ->call('createBrief')->assertSet('briefOpen', false);
        Livewire::withQueryParams(['demo' => 'quet-loi'])->test(AuditPage::class)->assertSee('robots.txt chặn crawler')->assertSee('Kết quả cũ');
        Livewire::withQueryParams(['demo' => 'chua-quet'])->test(AuditPage::class)->assertSee('Chưa quét website này')->assertSee('Quét lần đầu');
        Livewire::withQueryParams(['demo' => 'chua-ket-noi'])->test(AuditPage::class)->assertSee('Website chưa được kết nối');
        $c = Livewire::withQueryParams(['demo' => 'ngoai-allowlist'])->test(AuditPage::class)->assertSee('không thuộc danh sách domain được phép quét');
        $this->assertFalse($c->viewData('canRescan'));
        Livewire::withQueryParams(['demo' => 'bay-bay'])->test(AuditPage::class)->assertDontSee('Đang quét blog');
    }

    public function test_production_khong_co_so_mau(): void
    {
        $this->as('approver');
        $this->app->detectEnvironment(fn () => 'production');
        $c = Livewire::test(AuditPage::class)->assertSee('Chưa kết nối crawler')->assertDontSee('Dữ liệu minh họa')
            ->assertDontSee('Liên kết gãy (404)')->assertDontSee('/hoc-bong/');
        $this->assertNull($c->viewData('run'));
        $this->assertFalse($c->viewData('canRescan'));
        $c->call('rescan')->call('createBrief');
        $this->assertCount(0, SeoAuditService::make()->briefs());
    }

    public function test_khong_co_website_active(): void
    {
        $this->as('approver');
        $this->site->update(['status' => 'revoked']);
        Livewire::test(AuditPage::class)->assertSee('Website chưa được kết nối')->assertDontSee('Liên kết gãy (404)');
    }
}
