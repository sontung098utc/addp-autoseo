<?php

namespace Tests\Feature\Brain;

use App\Filament\Pages\BrainLibraryPage;
use App\Models\Brain;
use App\Models\BrainSource;
use App\Models\User;
use App\Services\Brain\BrainService;
use App\Services\Brain\BrainTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Preview #3, #5, #15: thẻ Kho trí tuệ, hộp cài mẫu, hộp tạo trí tuệ. */
class BrainLibraryPreviewFixTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'ad', 'email' => uniqid().'@l.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]);
    }

    private function must(): Brain
    {
        return Brain::create(['name' => 'Y khoa', 'slug' => 'y-khoa', 'status' => 'active', 'settings' => ['ai_reliance' => 'must_cite', 'risk_class' => 'high']]);
    }

    public function test_bat_buoc_nguon_chua_co_nguon_xac_nhan_thi_canh_bao_khong_xanh(): void
    {
        $b = $this->must();
        $this->actingAs($this->admin());
        $t = Livewire::test(BrainLibraryPage::class)->assertSee('Chưa có nguồn xác nhận')->assertDontSee('Không cảnh báo')->assertSee('0 tài liệu')->assertDontSee('0 nguồn');
        // nguồn chỉ gợi ý hoặc bị chặn vẫn không tính
        BrainSource::create(['brain_id' => $b->id, 'name' => 'A', 'domain' => 'a.org', 'status' => 'suggested']);
        BrainSource::create(['brain_id' => $b->id, 'name' => 'B', 'domain' => 'b.org', 'status' => 'confirmed', 'tier' => 'blocked']);
        Livewire::test(BrainLibraryPage::class)->assertSee('Chưa có nguồn xác nhận');
        BrainSource::create(['brain_id' => $b->id, 'name' => 'C', 'domain' => 'c.org', 'status' => 'confirmed', 'tier' => '1']);
        Livewire::test(BrainLibraryPage::class)->assertDontSee('Chưa có nguồn xác nhận')->assertSee('Không cảnh báo');
    }

    public function test_tri_tue_khong_bat_buoc_nguon_van_khong_canh_bao(): void
    {
        Brain::create(['name' => 'Tự do', 'slug' => 'tu-do', 'status' => 'active', 'settings' => ['ai_reliance' => 'free']]);
        $this->actingAs($this->admin());
        Livewire::test(BrainLibraryPage::class)->assertSee('Không cảnh báo')->assertDontSee('Chưa có nguồn xác nhận');
    }

    public function test_hop_cai_mau_khong_tick_san_mau_da_co_va_co_chon_nhanh(): void
    {
        $a = $this->admin();
        $this->actingAs($a);
        (new BrainService)->installTemplates(['dai_thao_duong'], null, $a);
        $t = Livewire::test(BrainLibraryPage::class)->call('openInstall');
        $keys = $t->get('installKeys');
        $this->assertNotContains('dai_thao_duong', $keys);
        $this->assertCount(count(BrainTemplates::HEALTH_KEYS) - 1, $keys);
        $t->assertSeeHtml('disabled')->assertSee('Chọn các mục chưa cài');
        $t->set('installKeys', [])->call('selectNotInstalled');
        $this->assertEqualsCanonicalizing($keys, $t->get('installKeys'));
    }

    public function test_hop_tao_mac_dinh_mau_trong_va_ten_trong_bao_loi(): void
    {
        $this->actingAs($this->admin());
        $t = Livewire::test(BrainLibraryPage::class)->call('openCreate')->call('pickKind', 'domain')->assertSet('create.template', 'trong');
        $t->set('create.name', '')->call('createBrain')->assertNotified('Chưa tạo được');
        $this->assertSame(0, Brain::count());
    }
}
