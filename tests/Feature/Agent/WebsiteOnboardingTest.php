<?php

namespace Tests\Feature\Agent;

use App\Filament\Pages\BrainBuildPage;
use App\Filament\Pages\WebsiteOnboardingPage;
use App\Models\Article;
use App\Models\Brain;
use App\Models\BrandProfile;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use App\Services\Onboarding\WebsiteOnboarding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Website
    {
        return Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function fakes(array $buildResult, array $researchResult): array
    {
        $build = new class($buildResult)
        {
            public function __construct(private array $r) {}

            public function run($site, $by, $opt = [])
            {
                return $this->r;
            }
        };
        $research = new class($researchResult)
        {
            public function __construct(private array $r) {}

            public function suggest($site)
            {
                return $this->r;
            }
        };

        return [fn () => $build, fn () => $research];
    }

    public function test_chay_het_cac_buoc_va_gan_tri_tue_theo_linh_vuc(): void
    {
        $site = $this->site();
        BrandProfile::create(['website_id' => $site->id, 'business_field' => 'Thực phẩm cho người tiểu đường']);
        Brain::create(['name' => 'Đái tháo đường (tiểu đường)', 'slug' => 'dtd', 'domain_key' => 'dtd', 'language' => 'vi', 'status' => 'active', 'description' => '']);
        Keyword::create(['website_id' => $site->id, 'keyword' => 'ăn gì tốt', 'keyword_key' => 'ăn gì tốt', 'volume' => 900, 'source' => 'csv', 'status' => 'new']);
        [$b, $r] = $this->fakes(['pages_read' => 8, 'items_auto' => 5, 'items_proposed' => 2, 'brand_profile' => ['applied' => true, 'fields' => ['voice']]], ['added' => 6]);

        $res = (new WebsiteOnboarding($b, $r))->run($site);

        $by = collect($res['steps'])->keyBy('key');
        $this->assertSame('ok', $by['kb']['status']);
        $this->assertStringContainsString('8 trang', $by['kb']['message']);
        $this->assertSame('ok', $by['brains']['status']);
        $this->assertStringContainsString('Đái tháo đường', $by['brains']['message']);
        $this->assertTrue($site->brains()->count() >= 1);
        $this->assertStringContainsString('6 từ khóa', $by['keywords']['message']);
        $this->assertSame('ok', $by['plan']['status']);
        $this->assertSame(0, Article::count(), 'không tạo bài');
    }

    public function test_doc_0_trang_hoac_them_0_tu_khoa_khong_duoc_bao_xong(): void
    {
        $site = $this->site();
        [$b, $r] = $this->fakes(['pages_read' => 0, 'items_auto' => 0, 'items_proposed' => 0], ['added' => 0]);

        $by = collect((new WebsiteOnboarding($b, $r))->run($site)['steps'])->keyBy('key');

        $this->assertSame('skipped', $by['kb']['status']);
        $this->assertStringContainsString('Chưa đọc được trang nào', $by['kb']['message']);
        $this->assertSame('skipped', $by['keywords']['status']);
        $this->assertStringContainsString('Chưa thêm được từ khóa nào', $by['keywords']['message']);
    }

    public function test_mot_buoc_loi_khong_dung_cac_buoc_sau(): void
    {
        $site = $this->site();
        $throwing = fn () => new class
        {
            public function run($s, $b, $o = [])
            {
                throw new \RuntimeException('Chưa kết nối AI.');
            }
        };
        [, $r] = $this->fakes([], ['added' => 1]);

        $res = (new WebsiteOnboarding($throwing, $r))->run($site);
        $by = collect($res['steps'])->keyBy('key');

        $this->assertSame('skipped', $by['kb']['status']);
        $this->assertSame('Chưa kết nối AI.', $by['kb']['message']);
        $this->assertSame('skipped', $by['brains']['status'], 'hồ sơ trống nên bỏ qua có lý do');
        $this->assertSame('ok', $by['keywords']['status']);
        $this->assertCount(4, $res['steps']);
    }

    public function test_trang_chi_approver_va_chay_duoc(): void
    {
        $this->site();
        $writer = User::create(['name' => 'w', 'email' => 'w@x.vn', 'password' => 'secret123', 'role' => 'writer', 'is_active' => true]);
        $this->actingAs($writer);
        $this->assertFalse(WebsiteOnboardingPage::canAccess());
        $this->assertFalse(BrainBuildPage::canOnboard());

        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));
        $this->assertTrue(WebsiteOnboardingPage::canAccess());
        // Z2: khởi tạo thành khối "Khởi tạo nhanh" ở tab Nạp kiến thức; URL cũ chuyển hướng về đó.
        $this->get(WebsiteOnboardingPage::getUrl())->assertRedirect(BrainBuildPage::getUrl().'#khoi-tao-nhanh');
        $this->get(BrainBuildPage::getUrl())->assertOk()->assertSee('Khởi tạo nhanh')->assertSee('ob-start', false);
        Livewire::test(BrainBuildPage::class)->call('startOnboarding')->assertSet('onboardResult.steps.0.key', 'kb');
    }
}
