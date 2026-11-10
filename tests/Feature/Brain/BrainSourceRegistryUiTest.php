<?php

namespace Tests\Feature\Brain;

use App\Filament\Pages\BrainPage;
use App\Models\Brain;
use App\Models\BrainSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-B3: giao diện Sổ nguồn uy tín trên trang Trí tuệ (quyền, thao tác, cách ly giữa các Trí tuệ). */
class BrainSourceRegistryUiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@ui.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function brain(string $reliance = 'must_cite'): Brain
    {
        return Brain::create(['name' => 'Y '.uniqid(), 'slug' => 'y-'.uniqid(), 'status' => 'active', 'settings' => ['ai_reliance' => $reliance, 'risk_class' => 'high']]);
    }

    public function test_tab_so_nguon_hien_va_canh_bao_khi_bat_buoc_nguon_ma_chua_co_nguon_da_xac_nhan(): void
    {
        $b = $this->brain();
        $this->actingAs($this->user('approver'));
        Livewire::test(BrainPage::class, ['brain' => $b->id])->assertOk()
            ->assertSee('Sổ nguồn')->assertSee('Sổ nguồn đang trống')->assertSee('Bắt buộc nguồn');
    }

    public function test_them_nguon_xep_bac_roi_rut_xac_nhan_va_xoa(): void
    {
        $b = $this->brain();
        $this->actingAs($this->user('approver'));
        $t = Livewire::test(BrainPage::class, ['brain' => $b->id])
            ->set('srcName', 'Bộ Y tế')->set('srcDomain', 'https://www.moh.gov.vn/')->set('srcTier', '1')->call('addSource')
            ->assertSee('Bộ Y tế')->assertSee('Đã xác nhận')->assertSet('srcName', '');
        $s = BrainSource::firstOrFail();
        $this->assertSame(['moh.gov.vn', '1', 'confirmed'], [$s->domain, $s->tier, $s->status]);

        $t->call('setSourceTier', $s->id, '')->assertSee('Chưa xác nhận, chưa được tính');
        $this->assertSame('suggested', $s->refresh()->status);
        $t->call('setSourceTier', $s->id, 'blocked');
        $this->assertSame('blocked', $s->refresh()->tier);
        $t->call('unconfirmSource', $s->id)->call('removeSource', $s->id);
        $this->assertSame(0, BrainSource::count());
    }

    public function test_thao_tac_so_nguon_co_thong_bao_va_hien_nguoi_xac_nhan(): void
    {
        $b = $this->brain();
        $u = $this->user('approver');
        $this->actingAs($u);
        $s = BrainSource::create(['brain_id' => $b->id, 'name' => 'WHO', 'domain' => 'who.int', 'status' => 'suggested']);
        $t = Livewire::test(BrainPage::class, ['brain' => $b->id]);
        $t->call('setSourceTier', $s->id, '1')->assertNotified('Đã xác nhận "WHO" ở bậc 1: chính thức');
        $t->assertSeeHtml('data-testid="confirmed-by"')->assertSee('Xác nhận bởi approver, '.now()->format('d/m/Y'));
        $t->call('setSourceTier', $s->id, 'blocked')->assertNotified('Đã chặn "WHO"');
        $t->call('unconfirmSource', $s->id)->assertNotified('Đã rút xác nhận "WHO"');
        $t->assertDontSee('Xác nhận bởi');
        $t->call('removeSource', $s->id)->assertNotified('Đã xóa "WHO" khỏi Sổ nguồn');
    }

    public function test_tien_mien_sai_bi_tu_choi_khong_luu(): void
    {
        $b = $this->brain();
        $this->actingAs($this->user('approver'));
        Livewire::test(BrainPage::class, ['brain' => $b->id])->set('srcName', 'X')->set('srcDomain', 'khong phai ten mien')->set('srcTier', '2')->call('addSource');
        $this->assertSame(0, BrainSource::count());
    }

    public function test_khong_sua_duoc_nguon_cua_tri_tue_khac(): void
    {
        $a = $this->brain();
        $other = $this->brain();
        $foreign = BrainSource::create(['brain_id' => $other->id, 'name' => 'Khác', 'domain' => 'khac.org', 'status' => 'suggested']);
        $this->actingAs($this->user('approver'));
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        try {
            Livewire::test(BrainPage::class, ['brain' => $a->id])->call('setSourceTier', $foreign->id, '1');
        } finally {
            $this->assertSame('suggested', $foreign->refresh()->status);
        }
    }

    public function test_bien_tap_vien_khong_the_them_nguon(): void
    {
        $b = $this->brain();
        $this->actingAs($this->user('writer'));
        try {
            Livewire::test(BrainPage::class, ['brain' => $b->id])->set('srcName', 'X')->set('srcDomain', 'x.org')->set('srcTier', '1')->call('addSource');
        } catch (\Throwable) {
            // bị chặn (403) là đúng
        }
        $this->assertSame(0, BrainSource::count());
    }
}
