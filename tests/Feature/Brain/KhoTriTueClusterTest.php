<?php

namespace Tests\Feature\Brain;

use App\Filament\Clusters\KhoTriTue;
use App\Filament\Pages\BrainBuildPage;
use App\Filament\Pages\BrainLibraryPage;
use App\Filament\Pages\WebsiteOnboardingPage;
use App\Models\Brain;
use App\Models\User;
use App\Models\Website;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Z2: Kho trí tuệ là MỘT mục menu (cụm) có hai tab; Xây kho và Khởi tạo website đã gộp vào tab Nạp kiến thức. */
class KhoTriTueClusterTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    /** @return list<string> nhãn mục menu cấp 1 của người dùng hiện tại (kể cả mục trong nhóm) */
    private function menuLabels(): array
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $labels = [];
        foreach (Filament::getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                $labels[] = $item->getLabel();
            }
        }

        return $labels;
    }

    public function test_menu_chi_con_mot_muc_kho_tri_tue(): void
    {
        $this->actingAs($this->user('approver'));
        $labels = $this->menuLabels();
        $this->assertSame(1, count(array_keys($labels, 'Kho trí tuệ', true)));
        $this->assertNotContains('Xây kho từ website', $labels);
        $this->assertNotContains('Khởi tạo website', $labels);
        $this->assertNotContains('Hồ sơ doanh nghiệp', $labels);
        $this->assertFalse(WebsiteOnboardingPage::shouldRegisterNavigation());
    }

    public function test_cum_co_tab_theo_quyen(): void
    {
        $this->actingAs($this->user('writer'));
        $this->assertTrue(KhoTriTue::canAccess());
        $this->assertTrue(BrainLibraryPage::canAccess());
        $this->assertFalse(BrainBuildPage::canAccess());
        $this->assertFalse(BrainBuildPage::canOnboard());
        $this->assertContains('Kho trí tuệ', $this->menuLabels());
        $this->actingAs($this->user('approver'));
        $this->assertTrue(BrainLibraryPage::canAccess());
        $this->assertTrue(BrainBuildPage::canAccess());
        $this->assertTrue(BrainBuildPage::canOnboard());
        $this->assertSame('Trí tuệ', BrainLibraryPage::getNavigationLabel());
        $this->assertSame('Nạp kiến thức', BrainBuildPage::getNavigationLabel());
        $this->assertSame(KhoTriTue::class, BrainBuildPage::getCluster());
    }

    public function test_url_cu_chuyen_ve_tab_moi(): void
    {
        $this->actingAs($this->user('approver'));
        $this->get('/admin/kho-tri-tue')->assertRedirect('/admin/kho-tri-tue/tri-tue');
        $this->get('/admin/xay-kho')->assertRedirect('/admin/kho-tri-tue/nap-kien-thuc');
        $this->get('/admin/xay-kho?topic=abc')->assertRedirect('/admin/kho-tri-tue/nap-kien-thuc?topic=abc');
        $this->get('/admin/khoi-tao-website')->assertRedirect('/admin/kho-tri-tue/nap-kien-thuc#khoi-tao-nhanh');
        $this->assertSame('/admin/kho-tri-tue/nap-kien-thuc', parse_url(BrainBuildPage::getUrl(), PHP_URL_PATH));
        $this->assertSame('/admin/kho-tri-tue/tri-tue', parse_url(BrainLibraryPage::getUrl(), PHP_URL_PATH));
    }

    public function test_nap_kien_thuc_co_du_khoi_va_huong_dan_nam_trong_x_help(): void
    {
        Website::create(['name' => 'Ánh Dương', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->actingAs($this->user('approver'));
        $html = Livewire::test(BrainBuildPage::class)->assertOk()
            ->assertSee('Khởi tạo nhanh')->assertSee('Từ website công ty')->assertSee('Khám phá nguồn trên web')->assertSee('Việc cần bạn xem')
            ->html();
        foreach (['bb-quick', 'bb-site', 'bb-discover', 'bb-todo', 'ob-start'] as $id) {
            $this->assertStringContainsString($id, $html);
        }
        // đoạn giải thích dài nằm trong x-help (data-testid="help"), không nằm sẵn dưới tiêu đề
        $this->assertMatchesRegularExpression('/class="hp-pop[^>]*>.*?Chỉ điền trường đang trống/s', $html);
        $this->assertMatchesRegularExpression('/class="hp-pop[^>]*>.*?Một nút làm cả chuỗi/s', $html);
        $this->assertStringContainsString('data-testid="help"', $html);
    }

    public function test_thu_vien_co_so_dem_that_o_dau_trang(): void
    {
        $this->actingAs($this->user('writer'));
        Brain::create(['name' => 'Y khoa', 'slug' => 'y-khoa', 'status' => 'active', 'settings' => []]);
        Livewire::test(BrainLibraryPage::class)->assertSeeHtml('bl-summary')->assertSee('1 Trí tuệ')->assertSee('0 mục kiến thức');
    }
}
