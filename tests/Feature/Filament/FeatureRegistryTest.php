<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\HowItWorksPage;
use App\Filament\Support\Agent\FeatureRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AG-U02: không ai tô "Đang hoạt động" bằng tay — phải có bằng chứng cụ thể, và bằng chứng không được là lời suông.
 */
class FeatureRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    public function test_muc_hoat_dong_bat_buoc_co_bang_chung(): void
    {
        $active = FeatureRegistry::byReadiness('active');
        $this->assertNotEmpty($active);
        foreach ($active as $e) {
            $this->assertGreaterThan(20, mb_strlen(trim($e['evidence'])), "{$e['id']} đang hoạt động nhưng thiếu bằng chứng");
        }
    }

    public function test_bang_chung_phai_nhac_toi_test_co_that(): void
    {
        $tests = collect(array_merge(glob(base_path('tests/*/*.php')), glob(base_path('tests/*/*/*.php'))))->map(fn ($p) => basename($p, '.php'))->all();

        foreach (FeatureRegistry::byReadiness('active') as $e) {
            preg_match_all('/\b([A-Z][A-Za-z]+Test)\b/', $e['evidence'], $m);
            $this->assertNotEmpty($m[1], "{$e['id']}: bằng chứng phải nêu ít nhất một tên test");
            foreach ($m[1] as $name) {
                $this->assertContains($name, $tests, "{$e['id']}: test '{$name}' trong bằng chứng không tồn tại");
            }
        }
    }

    public function test_muc_chua_hoat_dong_phai_noi_ro_gioi_han(): void
    {
        foreach (FeatureRegistry::all() as $e) {
            if ($e['readiness'] !== 'active') {
                $this->assertNotSame('', trim($e['caveat']), "{$e['id']} chưa hoạt động thì phải ghi rõ vì sao");
            }
        }
    }

    public function test_truong_bat_buoc_va_khong_trung_id(): void
    {
        $ids = [];
        foreach (FeatureRegistry::all() as $e) {
            foreach (['id', 'title', 'what', 'readiness'] as $f) {
                $this->assertNotSame('', trim((string) ($e[$f] ?? '')), "Thiếu {$f}");
            }
            $this->assertContains($e['readiness'], FeatureRegistry::READINESS, $e['id']);
            $ids[] = $e['id'];
        }
        $this->assertSame(array_unique($ids), $ids, 'id tính năng bị trùng');
    }

    public function test_moi_giai_doan_tro_toi_tinh_nang_ton_tai(): void
    {
        $this->assertCount(9, HowItWorksPage::STAGES);
        foreach (HowItWorksPage::STAGES as $s) {
            foreach ($s['features'] as $id) {
                $this->assertNotNull(FeatureRegistry::find($id), "Giai đoạn {$s['n']} trỏ tới tính năng không có: {$id}");
            }
        }
    }

    public function test_tu_dong_hoa_ai_chua_co_thi_khong_duoc_danh_dau_hoat_dong(): void
    {
        foreach (['ai.router', 'pipeline.auto'] as $id) {
            $this->assertSame('idea', FeatureRegistry::find($id)['readiness']);
        }
        // lát dọc AG-S01 chạy được với AI giả lập nhưng chưa có model thật/staging/người nghiệm thu: tối đa "Đang phát triển"
        foreach (['ai.selfrepair', 'run.slice'] as $id) {
            $this->assertSame('in_development', FeatureRegistry::find($id)['readiness']);
            $this->assertStringContainsString('giả lập', FeatureRegistry::find($id)['caveat'].FeatureRegistry::find($id)['what']);
        }
    }

    public function test_trang_hien_thi_cho_moi_vai_tro_va_chan_nguoi_bi_khoa(): void
    {
        foreach (['admin', 'approver', 'writer'] as $role) {
            $this->actingAs($this->user($role));
            Livewire::test(HowItWorksPage::class)->assertOk()
                ->assertSee('Đang hoạt động')->assertSee('Dữ liệu minh họa')->assertSee('Chưa kết nối')->assertSee('Đang phát triển')->assertSee('Ý tưởng')
                ->assertSee('Dừng khẩn cấp xuất bản');
        }

        $this->actingAs($this->user('approver', false));
        $this->assertFalse(HowItWorksPage::canAccess());
    }

    public function test_moi_the_giai_doan_chi_mot_huy_hieu_la_muc_kem_nhat(): void
    {
        $this->actingAs($this->user('admin'));
        $html = Livewire::test(HowItWorksPage::class)->html();
        $this->assertSame(count(HowItWorksPage::STAGES), substr_count($html, 'data-testid="hw-badge"'));
    }
}
