<?php

namespace Tests\Feature\Planning;

use App\Filament\Pages\ContentPlanPage;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Mục "Các kế hoạch" của trang Kế hoạch: nơi xem mọi chiến dịch và bài đã lên kế hoạch của website. */
class PlanOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-11-06 10:00:00');
        $this->owner = $this->user('admin');
        $this->site = Website::create(['name' => 'ADDP', 'cms_type' => 'wordpress', 'base_url' => 'https://addp.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function article(string $kw, string $status = 'planned', ?Campaign $c = null): Article
    {
        return Article::create(['website_id' => $this->site->id, 'created_by' => $this->owner->id, 'primary_keyword' => $kw, 'workflow_status' => $status, 'campaign_id' => $c?->id]);
    }

    private function campaign(string $name): Campaign
    {
        return Campaign::create(['website_id' => $this->site->id, 'created_by' => $this->owner->id, 'name' => $name, 'keywords' => []]);
    }

    public function test_chua_co_gi_thi_goi_y_hai_cach_bat_dau_cho_nguoi_duyet(): void
    {
        Livewire::actingAs($this->user('approver'))->test(ContentPlanPage::class)
            ->assertSee('Chưa có kế hoạch nào')->assertSee('Tôi đã có file kế hoạch')->assertSee('Hãy gợi ý giúp tôi');
    }

    public function test_bien_tap_vien_thay_man_hinh_trong_nhung_khong_thay_nut_nhap_file(): void
    {
        Livewire::actingAs($this->user('writer'))->test(ContentPlanPage::class)
            ->assertSee('Chưa có kế hoạch nào')->assertDontSee('Tôi đã có file kế hoạch')->assertDontSee('Nhập từ file');
    }

    public function test_liet_ke_chien_dich_kem_bai_tien_do_va_bai_le(): void
    {
        $a = $this->campaign('Sức khỏe người già');
        $b = $this->campaign('Chiến dịch rỗng');
        $this->article('bệnh tiểu đường', 'planned', $a);
        $this->article('loãng xương', 'approved', $a);
        $this->article('bài lẻ một');

        $page = Livewire::actingAs($this->user('approver'))->test(ContentPlanPage::class)
            ->assertDontSee('Chưa có kế hoạch nào')
            ->assertSee('Sức khỏe người già')->assertSee('bệnh tiểu đường')->assertSee('loãng xương')
            ->assertSee('2 bài')->assertSee('Chiến dịch này chưa có bài nào')
            ->assertSee('Bài lẻ (không thuộc chiến dịch)')->assertSee('bài lẻ một');
        $this->assertCount(3, $page->viewData('planGroups'));
        $first = collect($page->viewData('planGroups'))->firstWhere('name', 'Sức khỏe người già');
        $this->assertSame(['planned' => 1, 'writing' => 0, 'review' => 0, 'ready' => 1], $first['counts']);

        // ô chiến dịch ở đầu trang lọc cả danh sách này
        $page->set('campaignId', (string) $b->id);
        $this->assertSame(['Chiến dịch rỗng'], array_column($page->viewData('planGroups'), 'name'));
        $page->set('campaignId', 'solo');
        $this->assertSame(['Bài lẻ (không thuộc chiến dịch)'], array_column($page->viewData('planGroups'), 'name'));
    }

    public function test_chi_hien_chien_dich_va_bai_cua_website_dang_chon(): void
    {
        $other = Website::create(['name' => 'B', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        Campaign::create(['website_id' => $other->id, 'created_by' => $this->owner->id, 'name' => 'Của site B', 'keywords' => []]);
        $this->campaign('Của ADDP');

        Livewire::actingAs($this->user('approver'))->test(ContentPlanPage::class)->set('websiteId', $this->site->id)
            ->assertSee('Của ADDP')->assertDontSee('Của site B');
    }

    public function test_lap_ke_hoach_gom_cac_cach_theo_vai_tro(): void
    {
        $html = Livewire::actingAs($this->user('approver'))->test(ContentPlanPage::class)->html();
        foreach (['file', 'chu-de', 'cum', 'goi-y'] as $m) {
            $this->assertStringContainsString('data-method="'.$m.'"', $html, "người duyệt thấy cách $m");
        }
        $this->assertStringContainsString('data-key="lap-ke-hoach"', $html);
        $this->assertStringNotContainsString('data-key="de-xuat"', $html, 'tab cũ đã đổi tên');

        $w = Livewire::actingAs($this->user('writer'))->test(ContentPlanPage::class)->html();
        $this->assertStringContainsString('data-method="cum"', $w);
        $this->assertStringContainsString('data-method="goi-y"', $w);
        $this->assertStringNotContainsString('data-method="file"', $w, 'nhập file cần quyền duyệt');
        $this->assertStringNotContainsString('data-method="chu-de"', $w);
    }
}
