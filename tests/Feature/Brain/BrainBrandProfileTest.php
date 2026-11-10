<?php

namespace Tests\Feature\Brain;

use App\Filament\Clusters\AgentTeam\Pages\AgentOverview;
use App\Filament\Pages\BrainLibraryPage;
use App\Filament\Pages\BrainPage;
use App\Filament\Pages\BrandProfilePage;
use App\Models\Article;
use App\Models\Brain;
use App\Models\BrandProfile;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Contracts\AiProviderInterface;
use App\Services\Brain\BrainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Unit\QualityFixtures;

/** AG-B2 phần A: Hồ sơ doanh nghiệp thuộc Trí tuệ Thương hiệu (UI dời, dữ liệu/luật giữ nguyên). Mức: Livewire/feature test giả lập, không AI thật. */
class BrainBrandProfileTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function site(string $name = 'S'): Website
    {
        return Website::create(['name' => $name, 'cms_type' => 'wordpress', 'base_url' => 'https://'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function brain(string $tpl, array $sites, string $name = 'Thương hiệu X'): Brain
    {
        return (new BrainService())->createFromTemplate($name, $tpl, $sites, $this->user('admin'));
    }

    public function test_tri_tue_thuong_hieu_dung_mot_website_tu_co_ho_so_rong(): void
    {
        $w = $this->site();
        $this->brain('thuong_hieu', [$w]);
        $p = BrandProfile::where('website_id', $w->id)->firstOrFail();
        $this->assertNull($p->business_field);
        $this->assertEmpty($p->key_messages);

        // Nhiều website, không website, hoặc mẫu khác: không tự tạo hồ sơ.
        $a = $this->site('A');
        $b = $this->site('B');
        $this->brain('thuong_hieu', [$a, $b], 'Hai site');
        $this->brain('thuong_hieu', [], 'Không site');
        $this->brain('y_khoa_duoc', [$this->site('C')], 'Y khoa');
        $this->assertSame(1, BrandProfile::count());

        // Hồ sơ đã có thì giữ nguyên, không ghi đè.
        $d = $this->site('D');
        BrandProfile::create(['website_id' => $d->id, 'business_field' => 'Giữ nguyên']);
        $this->brain('thuong_hieu', [$d], 'Có sẵn');
        $this->assertSame('Giữ nguyên', $d->fresh()->brandProfile->business_field);
        $this->assertSame(2, BrandProfile::count());
    }

    public function test_sua_ho_so_ngay_trong_tri_tue_thuong_hieu_luu_dung_brand_profiles(): void
    {
        $w = $this->site();
        $other = $this->site('Khác');
        $b = $this->brain('thuong_hieu', [$w, $other]);
        $this->actingAs($this->user('approver'));
        $t = Livewire::test(BrainPage::class, ['brain' => $b->id])->assertOk()->assertSee('Hồ sơ doanh nghiệp')->assertSet('brandWebsiteId', $w->id)
            ->set('brandData.business_field', 'Nội thất gỗ')->set('brandData.audience', 'Văn phòng')->set('brandData.voice', 'chuyen_gia')
            ->set('brandData.forbidden_terms', ['rẻ nhất'])->set('brandData.avoid_competitors', ['CongTyZ'])->set('brandData.key_messages', ['Bền'])
            ->call('saveBrand')->assertHasNoErrors();
        $p = $w->fresh()->brandProfile;
        $this->assertSame('Nội thất gỗ', $p->business_field);
        $this->assertSame(['rẻ nhất'], $p->forbidden_terms);
        $this->assertNotNull($p->updated_by);
        $this->assertNull(BrandProfile::where('website_id', $other->id)->value('business_field'), 'website khác không bị ghi');
        $this->assertSame(1, BrandProfile::where('website_id', $w->id)->count(), 'mỗi website một hồ sơ');

        // Chuyển sang website thứ hai trong cùng Trí tuệ: form tải đúng hồ sơ; website ngoài Trí tuệ bị từ chối.
        $t->set('brandWebsiteId', $other->id)->assertSet('brandData.business_field', null);
        $t->set('brandWebsiteId', $this->site('Lạ')->id)->assertSet('brandWebsiteId', $w->id)->assertSet('brandData.business_field', 'Nội thất gỗ');
    }

    public function test_tab_ho_so_doanh_nghiep_chi_o_tri_tue_thuong_hieu_va_quyen(): void
    {
        $w = $this->site();
        $brand = $this->brain('thuong_hieu', [$w]);
        $med = $this->brain('y_khoa_duoc', [$w], 'Y khoa');
        $this->actingAs($this->user('approver'));
        Livewire::test(BrainPage::class, ['brain' => $med->id])->assertDontSee('Hồ sơ doanh nghiệp')->assertDontSee('data-testid="brand-profile"', false)
            ->call('saveBrand')->assertNotFound();
        Livewire::test(BrainPage::class, ['brain' => $brand->id])->assertSee('data-testid="brand-profile"', false)->assertSee('Độ đầy đủ hồ sơ: 0/4');

        $this->actingAs($this->user('writer'));
        Livewire::test(BrainPage::class, ['brain' => $brand->id])->assertOk()->assertSee('Bạn chỉ có quyền xem hồ sơ này')
            ->set('brandData.audience', 'x')->call('saveBrand')->assertForbidden();
        $this->assertNull($w->fresh()->brandProfile->audience);

        // Chưa gắn website: hướng dẫn gắn, không lưu được.
        $none = $this->brain('thuong_hieu', [], 'Chưa gắn');
        $this->actingAs($this->user('admin'));
        Livewire::test(BrainPage::class, ['brain' => $none->id])->assertSee('chưa gắn website nào')->call('saveBrand')->assertHasErrors(['brandWebsiteId']);
    }

    public function test_menu_rieng_bi_bo_va_url_cu_chuyen_huong(): void
    {
        $this->assertFalse(BrandProfilePage::shouldRegisterNavigation());
        $w = $this->site();
        $this->actingAs($this->user('admin'));

        // Một website, chưa có Trí tuệ thương hiệu: tạo rồi chuyển tới tab hồ sơ.
        $this->assertSame(0, Brain::count());
        $r = $this->get('/admin/ho-so-doanh-nghiep');
        $brain = Brain::where('template_key', 'thuong_hieu')->firstOrFail();
        $r->assertRedirect(BrainPage::getUrl(['brain' => $brain->id]).'#tab-brand');
        $this->assertSame([$w->id], $brain->websites()->pluck('websites.id')->all());
        $this->assertSame(1, BrandProfile::where('website_id', $w->id)->count());

        // Gọi lại: dùng Trí tuệ có sẵn, không tạo thêm.
        $this->get('/admin/ho-so-doanh-nghiep')->assertRedirect(BrainPage::getUrl(['brain' => $brain->id]).'#tab-brand');
        $this->assertSame(1, Brain::count());

        // Nhiều website: về Thư viện, trừ khi chỉ định ?website=.
        $w2 = $this->site('Hai');
        $this->get('/admin/ho-so-doanh-nghiep')->assertRedirect(BrainLibraryPage::getUrl());
        $this->get('/admin/ho-so-doanh-nghiep?website='.$w2->id);
        $this->assertSame(2, Brain::count());
        $this->assertSame([$w2->id], Brain::where('template_key', 'thuong_hieu')->orderByDesc('id')->first()->websites()->pluck('websites.id')->all());
    }

    public function test_url_cu_voi_nguoi_khong_co_quyen_tao_khong_tao_tri_tue(): void
    {
        $w = $this->site();
        $this->actingAs($this->user('writer'));
        $this->get('/admin/ho-so-doanh-nghiep')->assertRedirect(BrainLibraryPage::getUrl());
        $this->assertSame(0, Brain::count());
        $this->brain('thuong_hieu', [$w]);
        $this->get('/admin/ho-so-doanh-nghiep')->assertRedirect(BrainPage::getUrl(['brain' => Brain::firstOrFail()->id]).'#tab-brand');
    }

    public function test_buoc_cai_dat_o_tong_quan_tro_toi_trang_moi(): void
    {
        $w = $this->site();
        $this->actingAs($this->user('admin'));
        $steps = function () {
            $c = Livewire::test(AgentOverview::class)->instance();
            $m = new \ReflectionMethod($c, 'getViewData');
            $m->setAccessible(true);

            return collect($m->invoke($c)['steps'])->firstWhere('title', 'Hồ sơ doanh nghiệp');
        };
        $this->assertSame(BrandProfilePage::getUrl(), $steps()['url'], 'chưa có Trí tuệ: URL cũ tự chuyển hướng và tạo');
        $b = $this->brain('thuong_hieu', [$w]);
        $this->assertSame(BrainPage::getUrl(['brain' => $b->id]).'#tab-brand', $steps()['url']);
    }

    public function test_the_thu_vien_hien_ho_so_doanh_nghiep_n_tren_m_truong_that(): void
    {
        $w = $this->site();
        $b = $this->brain('thuong_hieu', [$w]);
        $this->actingAs($this->user('approver'));
        $card = fn () => Livewire::test(BrainLibraryPage::class)->viewData('cards')->firstWhere(fn ($c) => $c['brain']->id === $b->id);
        Livewire::test(BrainLibraryPage::class)->assertSee('Hồ sơ doanh nghiệp 0/4 trường');
        $this->assertSame(['filled' => 0, 'total' => 4, 'has_site' => true], $card()['brand']);
        $w->brandProfile->update(['business_field' => 'F', 'audience' => 'A', 'voice' => 'than_thien']);
        Livewire::test(BrainLibraryPage::class)->assertSee('Hồ sơ doanh nghiệp 3/4 trường');
        $w->brandProfile->update(['key_messages' => ['M']]);
        Livewire::test(BrainLibraryPage::class)->assertSee('Hồ sơ doanh nghiệp 4/4 trường');
        $y = $this->brain('y_khoa_duoc', [$w], 'Y khoa');
        $this->assertNull(Livewire::test(BrainLibraryPage::class)->viewData('cards')->firstWhere(fn ($c) => $c['brain']->id === $y->id)['brand']);
        $this->brain('thuong_hieu', [], 'Chưa gắn');
        Livewire::test(BrainLibraryPage::class)->assertSee('(chưa gắn website)');
    }

    public function test_doi_ai_van_nhan_ho_so_qua_options_brand_va_ghi_nguon_tri_tue(): void
    {
        $w = $this->site();
        $b = $this->brain('thuong_hieu', [$w], 'Thương hiệu ADDP');
        BrandProfile::where('website_id', $w->id)->update(['business_field' => 'Học bổng du học', 'audience' => 'Học sinh', 'voice' => 'than_thien', 'key_messages' => ['Uy tín']]);
        $a = Article::create(['website_id' => $w->id, 'primary_keyword' => 'học bổng toàn phần', 'workflow_status' => 'planned']);
        $ai = new class implements AiProviderInterface {
            public array $opts = [];

            public function generateOutline(Article $article, array $options = []): array
            {
                $this->opts['outline'] = $options;

                return ['outline' => ['Mở đầu', 'Nội dung'], 'tokens_used' => 10, 'model' => 'fake'];
            }

            public function generateArticle(Article $article, array $outline, array $options = []): array
            {
                $this->opts['write'] = $options;

                return QualityFixtures::goodAttrs('học bổng toàn phần') + ['tokens_used' => 10, 'cost_vnd' => 1.0, 'model' => 'fake', 'prompt_version' => 't'];
            }

            public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
            {
                return 0.0;
            }
        };
        $run = (new AgentRunOrchestrator($ai))->run($a, null);
        $this->assertSame('Học bổng du học', $ai->opts['outline']['brand']['business_field']);
        $this->assertSame('Học bổng du học', $ai->opts['write']['brand']['business_field']);
        foreach (['brief', 'write'] as $stage) {
            $step = $run->steps->firstWhere('stage', $stage);
            $this->assertStringContainsString('Đã dùng hồ sơ thương hiệu (4 trường)', $step->detail);
            $this->assertStringContainsString('Trí tuệ Thương hiệu ADDP · Hồ sơ doanh nghiệp', $step->detail);
            $this->assertSame('Trí tuệ Thương hiệu ADDP · Hồ sơ doanh nghiệp', $step->data['brand_source'] ?? null);
        }
        $this->assertNotNull($b);
    }
}
