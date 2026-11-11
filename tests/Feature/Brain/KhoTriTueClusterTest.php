<?php

namespace Tests\Feature\Brain;

use App\Filament\Clusters\KhoTriTue;
use App\Filament\Pages\BrainBuildPage;
use App\Filament\Pages\BrainLibraryPage;
use App\Filament\Pages\WebsiteOnboardingPage;
use App\Models\Brain;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\BrainKinds;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Z2: Kho trí tuệ là MỘT mục menu, MỘT trang chính; Xây kho và Khởi tạo website là luồng "Trí tuệ doanh nghiệp" trong hộp Tạo trí tuệ, không còn tab Nạp kiến thức. */
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
        $this->assertFalse(BrainBuildPage::shouldRegisterNavigation());
        $this->assertSame('Tạo trí tuệ doanh nghiệp', BrainBuildPage::getNavigationLabel());
        $this->assertSame(KhoTriTue::class, BrainBuildPage::getCluster());
    }

    public function test_url_cu_chuyen_ve_tab_moi(): void
    {
        $this->actingAs($this->user('approver'));
        $this->get('/admin/kho-tri-tue')->assertRedirect('/admin/kho-tri-tue/tri-tue');
        $this->get('/admin/xay-kho')->assertRedirect('/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep');
        $this->get('/admin/xay-kho?topic=abc')->assertRedirect('/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep?topic=abc');
        $this->get('/admin/kho-tri-tue/nap-kien-thuc')->assertRedirect('/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep');
        $this->get('/admin/kho-tri-tue/nap-kien-thuc?topic=abc')->assertRedirect('/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep?topic=abc');
        $this->get('/admin/khoi-tao-website')->assertRedirect('/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep#khoi-tao-nhanh');
        $this->assertSame('/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep', parse_url(BrainBuildPage::getUrl(), PHP_URL_PATH));
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

    public function test_cum_khong_con_tab_nap_kien_thuc(): void
    {
        $this->actingAs($this->user('approver'));
        $this->assertSame([], (new BrainLibraryPage)->getSubNavigation());
        $this->assertSame([], (new BrainBuildPage)->getSubNavigation());
        $html = $this->get(BrainLibraryPage::getUrl())->assertOk()->getContent();
        $this->assertStringNotContainsString('Nạp kiến thức', $html);
    }

    public function test_tao_tri_tue_hoi_loai_voi_hai_the(): void
    {
        $this->actingAs($this->user('approver'));
        $t = Livewire::test(BrainLibraryPage::class)->call('openCreate')->assertSet('createKind', '')
            ->assertSee('Trí tuệ theo lĩnh vực')->assertSee('Trí tuệ doanh nghiệp')->assertSeeHtml('kind-domain')->assertSeeHtml('kind-enterprise');
        $this->assertSame(['domain', 'enterprise'], array_keys(BrainKinds::all()));
        $t->call('pickKind', 'domain')->assertSet('createKind', 'domain')->assertSee('Tên')->call('backToKinds')->assertSet('createKind', '');
    }

    public function test_chon_doanh_nghiep_vao_luong_xay_kho(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(BrainLibraryPage::class)->call('openCreate')->call('pickKind', 'enterprise')->assertRedirect(BrainBuildPage::getUrl());
    }

    public function test_quyen_giu_nguyen_va_phai_chon_loai_truoc_khi_tao(): void
    {
        $this->actingAs($this->user('writer'));
        $this->assertNull(BrainKinds::flowUrl('enterprise'));
        Livewire::test(BrainLibraryPage::class)->call('pickKind', 'enterprise')->assertForbidden();
        $this->actingAs($this->user('approver'));
        $this->assertSame(BrainBuildPage::getUrl(), BrainKinds::flowUrl('enterprise'));
        Livewire::test(BrainLibraryPage::class)->call('openCreate')->set('create.name', 'X')->call('createBrain')->assertStatus(422);
        $this->assertSame(0, Brain::count());
    }
}
