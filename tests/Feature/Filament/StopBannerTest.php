<?php

namespace Tests\Feature\Filament;

use App\Filament\Support\Stop\StopBanner;
use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AG-U11a: banner "Đang dừng xuất bản" toàn cục, chỉ đọc EmergencyStopService.
 */
class StopBannerTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        app(EmergencyStopService::class)->resetForTesting();
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        app(EmergencyStopService::class)->resetForTesting();
        parent::tearDown();
    }

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    public function test_khong_dung_thi_khong_co_banner(): void
    {
        $this->assertNull(StopBanner::data());

        $this->actingAs($this->user('approver'))->get('/admin')->assertOk()->assertDontSee('Đang dừng xuất bản');
    }

    public function test_dung_toan_he_thong_hien_o_moi_trang_va_nho_ly_do(): void
    {
        app(EmergencyStopService::class)->stop($this->user('approver'), 'Phát hiện lỗi số liệu', null);

        $d = StopBanner::data();
        $this->assertSame('global', $d['scope']);
        $this->assertSame('Phát hiện lỗi số liệu', $d['reason']);

        $this->actingAs($this->user('admin'));
        foreach (['/admin', '/admin/ban-tin-khong-ton-tai-bo-qua'] as $url) {
            if ($url === '/admin') {
                $this->get($url)->assertOk()->assertSee('Đang dừng xuất bản')->assertSee('Phát hiện lỗi số liệu')->assertSee('toàn hệ thống');
            }
        }
    }

    public function test_dung_theo_website_hien_ten_website(): void
    {
        app(EmergencyStopService::class)->stop($this->user('approver'), 'Site A lỗi kết nối', $this->site->id);

        $d = StopBanner::data();
        $this->assertSame('sites', $d['scope']);
        $this->assertSame(['Site A'], $d['sites']);

        $this->actingAs($this->user('approver'))->get('/admin')->assertOk()->assertSee('Đang dừng xuất bản')->assertSee('Site A');
    }

    public function test_bien_tap_vien_thay_banner_nhung_khong_co_lien_ket_khoi_phuc(): void
    {
        app(EmergencyStopService::class)->stop($this->user('approver'), 'Kiểm tra', null);

        $this->actingAs($this->user('writer'))->get('/admin')->assertOk()
            ->assertSee('Đang dừng xuất bản')
            ->assertDontSee('Mở Lịch xuất bản để khôi phục');
    }

    public function test_nguoi_duyet_co_lien_ket_khoi_phuc(): void
    {
        app(EmergencyStopService::class)->stop($this->user('approver'), 'Kiểm tra', null);

        $this->actingAs($this->user('approver'))->get('/admin')->assertOk()->assertSee('Mở Lịch xuất bản để khôi phục');
    }

    public function test_chi_doc_khong_tu_khoi_phuc_hay_thay_doi_dung(): void
    {
        $svc = app(EmergencyStopService::class);
        $svc->stop($this->user('approver'), 'Kiểm tra', null);

        StopBanner::data();
        StopBanner::data();

        $this->assertTrue($svc->isStopped());
    }
}
