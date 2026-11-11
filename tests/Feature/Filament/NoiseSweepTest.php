<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\BrainLibraryPage;
use App\Models\Brain;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Rà "ghi chú vô giá trị": mỗi dòng bị bỏ có một kiểm tra "không còn dòng X trên trang Y". */
class NoiseSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function as(string $role): self
    {
        return $this->actingAs(User::create(['name' => 'Tên Riêng', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]));
    }

    public function test_tong_quan_khong_con_dong_phu_va_khoi_rong(): void
    {
        $this->as('approver')->get('/admin')->assertOk()
            ->assertDontSee('Tổng quan · Tên Riêng')
            ->assertDontSee('Chưa có hoạt động duyệt nào')
            ->assertDontSee('Không có lượt đăng lỗi hoặc chờ đối soát');
    }

    public function test_ban_tin_khong_con_ten_nguoi_dung_o_dong_phu(): void
    {
        $this->as('approver')->get('/admin/ban-tin')->assertOk()->assertDontSee('· Tên Riêng');
    }

    public function test_tu_khoa_dung_upload_zone_va_khong_con_dai_chain(): void
    {
        $html = $this->as('writer')->get('/admin/tu-khoa')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="csv-zone"', $html);
        $this->assertStringNotContainsString('<input type="file" wire:model="csvFile"', $html);
        $this->assertStringNotContainsString('class="cc-line"', $html);         // không còn dải "Lấy từ … → Kết quả"
        $this->assertStringNotContainsString('Cột: <code>keyword</code>', $html);
    }

    public function test_kho_tri_tue_khong_con_dong_khong_canh_bao(): void
    {
        Brain::create(['name' => 'Tự do', 'slug' => 'tu-do', 'status' => 'active', 'settings' => ['ai_reliance' => 'free']]);
        $this->as('admin')->get(BrainLibraryPage::getUrl())->assertOk()
            ->assertDontSee('Không cảnh báo')->assertDontSee('Độ khỏe')->assertDontSee('mục kiến thức')
            ->assertDontSee('chưa gắn website nào');
    }

    public function test_hop_thoai_tao_tri_tue_co_nut_x_goc_phai(): void
    {
        Livewire::actingAs(User::create(['name' => 'a', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]))
            ->test(BrainLibraryPage::class)->call('openCreate')
            ->assertSeeHtml('aria-label="Đóng"')->assertSee('Hủy')->call('closeCreate')->assertDontSeeHtml('aria-label="Đóng"');
    }
}
