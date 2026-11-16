<?php

namespace Tests\Feature\Brain;

use App\Filament\Pages\BrainPage;
use App\Models\AgentRun;
use App\Models\Brain;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Nút "Xây kho chuyên ngành" trên trang Trí tuệ: chỉ người duyệt trở lên, báo cáo lần xây hiện trên trang, không chạy trong yêu cầu web. */
class BrainDomainBuildUiTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function brain(): Brain
    {
        $w = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $b = Brain::create(['name' => 'Đái tháo đường', 'slug' => 'dtd-'.uniqid(), 'status' => 'active', 'settings' => ['ai_reliance' => 'must_cite', 'risk_class' => 'high',
            'profile_review' => ['status' => 'ai_draft'], 'build_report' => ['at' => now()->toIso8601String(), 'metrics' => ['items' => 12, 'approved' => 0, 'proposed' => 12, 'verified_faithful' => 9, 'verified_partial' => 2,
                'verified_unsupported' => 1, 'coverage_potential' => 0.76, 'coverage_usable' => 0.0], 'todo_for_experts' => ['Duyệt 12 mục chờ duyệt']]]]);
        $b->websites()->attach($w->id, ['priority' => 10]);

        return $b;
    }

    public function test_trang_hien_nut_bao_cao_lan_xay_va_nhan_nhap_ai(): void
    {
        $b = $this->brain();
        $this->actingAs($this->user('admin'));

        Livewire::test(BrainPage::class, ['brain' => $b->id])->assertOk()
            ->assertSee('Xây kho chuyên ngành')->assertSee('Rà soát kho')->assertSee('12 mục')->assertSee('76%')->assertSee('Duyệt 12 mục chờ duyệt')->assertSee('nháp do AI');
    }

    public function test_chay_nen_khi_csdl_dung_chung_duoc_con_khong_thi_chi_duong_dan_lenh_va_khong_tao_phien(): void
    {
        \Illuminate\Support\Facades\Process::fake(); // không bao giờ chạy tiến trình thật trong test
        $b = $this->brain();
        $this->actingAs($this->user('admin'));

        Livewire::test(BrainPage::class, ['brain' => $b->id])->call('buildDomain', false);

        $this->assertSame(0, AgentRun::count(), 'không chạy quy trình dài trong yêu cầu web');
        if (\App\Services\Images\ImageJob::dbShareable()) { // CSDL dùng chung được: khởi động tiến trình nền đúng lệnh
            \Illuminate\Support\Facades\Process::assertRan(fn ($p) => str_contains(is_array($p->command) ? implode(' ', $p->command) : (string) $p->command, 'brain:build-domain'));
        } else { // SQLite :memory: không chia sẻ được: không chạy gì, chỉ báo lệnh
            \Illuminate\Support\Facades\Process::assertNothingRan();
        }
    }

    public function test_nguoi_xem_khong_thay_nut_va_khong_co_quyen_xay_kho(): void
    {
        $b = $this->brain();
        $this->actingAs($this->user('viewer'));

        Livewire::test(BrainPage::class, ['brain' => $b->id])->assertDontSee('Rà soát kho');
        $this->assertFalse(BrainPage::canManage(), 'buildDomain() chặn bằng đúng điều kiện này (abort 403)');
        $this->assertSame(0, AgentRun::count());
    }
}
