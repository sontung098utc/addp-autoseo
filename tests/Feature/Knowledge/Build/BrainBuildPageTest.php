<?php

namespace Tests\Feature\Knowledge\Build;

use App\Filament\Pages\BrainBuildPage;
use App\Models\AgentRun;
use App\Models\BrandProfile;
use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Models\Website;
use App\Services\Knowledge\Build\BrandProfileDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class BrainBuildPageTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'Ánh Dương', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    public function test_quyen_truy_cap(): void
    {
        $this->actingAs($this->user('writer'));
        $this->assertFalse(BrainBuildPage::canAccess());
        $this->actingAs($this->user('approver', false));
        $this->assertFalse(BrainBuildPage::canAccess());
        $this->actingAs($this->user('approver'));
        $this->assertTrue(BrainBuildPage::canAccess());
        $this->actingAs($this->user('admin'));
        $this->assertTrue(BrainBuildPage::canAccess());
        auth()->logout();
        $this->assertFalse(BrainBuildPage::canAccess());
        $this->get('/admin/xay-kho')->assertRedirect();
    }

    public function test_writer_bi_chan_con_approver_thay_ghi_chu_co_dinh(): void
    {
        $this->actingAs($this->user('writer'))->get('/admin/xay-kho')->assertForbidden();
    }

    public function test_approver_thay_ghi_chu_co_dinh(): void
    {
        $this->actingAs($this->user('approver'))->get('/admin/xay-kho')->assertOk()
            ->assertSee('Đọc tối đa 30 trang trên chính website của bạn, tốn tối đa 50.000 ₫.', false)
            ->assertSee('Giá, chính sách, sản phẩm sức khỏe và mọi nội dung y khoa luôn chờ người duyệt.', false)
            ->assertSee('Chạy ngay trong trang, có thể mất vài chục giây đến vài phút.', false)
            ->assertSee('Chạy nền và chạy theo lịch chưa có', false)
            ->assertSee('Bắt đầu xây kho', false);
    }

    public function test_nut_tat_khi_thieu_ai(): void
    {
        $this->actingAs($this->user('approver'));
        $c = Livewire::test(BrainBuildPage::class);
        $this->assertStringContainsString('Chưa kết nối AI', (string) $c->viewData('off'));
        $c->assertSeeHtml('data-testid="bb-off"');
        $c->call('build')->assertNotified(); // chạy tay vẫn bị service chặn rõ ràng, không tải gì
        $this->assertSame('blocked', AgentRun::where('job_type', 'brain_build')->first()->status);
    }

    public function test_ghi_chu_theo_so_trang_va_tran(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(BrainBuildPage::class)->set('maxPages', 80)->assertSee('Đọc tối đa 50 trang', false)->set('maxPages', 12)->assertSee('Đọc tối đa 12 trang', false);
    }

    public function test_hien_ket_qua_sau_khi_chay_va_danh_sach_luot_gan_day(): void
    {
        $by = $this->user('approver');
        $this->actingAs($by);
        $run = AgentRun::create(['website_id' => $this->site->id, 'job_type' => 'brain_build', 'status' => 'ready_for_review', 'trigger' => 'manual', 'ai_mode' => 'real', 'started_at' => now(), 'cost_vnd' => 25]);
        $run->steps()->create(['seq' => 1, 'stage' => 'page', 'role' => 'system', 'status' => 'completed', 'title' => 'https://a.vn/x', 'data' => ['items' => 3], 'cost_vnd' => 25, 'tokens_used' => 10]);
        $c = Livewire::test(BrainBuildPage::class);
        $c->assertSee('Các lượt xây gần đây')->assertSee('Xong');
        $c->set('result', ['pages_read' => 2, 'pages_skipped' => 1, 'pages_failed' => 0, 'pages_planned' => 3, 'items_auto' => 4, 'items_proposed' => 5, 'cost_vnd' => 20.0, 'stopped_reason' => 'budget', 'message' => 'Chạm trần: đã đọc 2/3 trang.', 'rejected_urls' => [], 'demo' => false]);
        $c->assertSee('Đọc 2 trang')->assertSee('4 mục tự nhận')->assertSee('5 mục chờ duyệt')->assertSee('đã đọc 2/3 trang');
    }

    public function test_hoan_tac_muc_tu_nhan_tren_trang(): void
    {
        $by = $this->user('approver');
        $this->actingAs($by);
        $item = KnowledgeItem::create(['website_id' => $this->site->id, 'type' => 'fact', 'title' => 'Năm thành lập', 'body' => 'Công ty thành lập năm 2010.', 'status' => 'approved', 'review_mode' => 'auto', 'risk_tier' => 'A', 'created_by' => 'ai', 'version' => 1, 'content_hash' => 'h']);
        Livewire::test(BrainBuildPage::class)->assertSee('Năm thành lập')->assertSee('tự nhận')->call('undo', $item->id)->assertNotified();
        $item->refresh();
        $this->assertSame('proposed', $item->status);
        $this->assertNull($item->review_mode);
    }

    public function test_muc_goi_y_cho_kho_hien_va_bo_qua_an_di(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(BrainBuildPage::class)->assertSee('Gợi ý cho kho từ các bài gần đây')->assertSee('Chưa có gợi ý');
        KnowledgeGap::create(['website_id' => $this->site->id, 'query' => 'học bổng Úc', 'count' => 2, 'status' => 'open']);
        $c = Livewire::test(BrainBuildPage::class);
        $c->assertSee('Kho thiếu kiến thức về «học bổng Úc»')->assertSee('Bổ sung từ website')->assertSee('Bỏ qua');
        $key = $c->viewData('suggestions')[0]['key'];
        $c->call('dismissSuggestion', $key)->assertDontSee('Kho thiếu kiến thức về «học bổng Úc»')->assertSee('Chưa có gợi ý');
    }

    public function test_bo_sung_khoang_trong_khong_co_trang_lien_quan_thi_khong_chay(): void
    {
        $this->actingAs($this->user('approver'));
        KnowledgeGap::create(['website_id' => $this->site->id, 'query' => 'học bổng Úc', 'count' => 2, 'status' => 'open']);
        Http::fake(['*' => Http::response('', 404)]);
        $c = Livewire::test(BrainBuildPage::class);
        $key = $c->viewData('suggestions')[0]['key'];
        $c->call('fillGap', $key)->assertNotified();
        $this->assertSame(0, AgentRun::where('job_type', 'brain_build')->count());
    }

    public function test_hien_ho_so_tu_dien_va_hoan_tac(): void
    {
        $by = $this->user('approver');
        $this->actingAs($by);
        BrandProfile::create(['website_id' => $this->site->id, 'business_field' => 'Tư vấn du học']);
        Cache::forever(BrandProfileDrafter::cacheKey($this->site->id), ['at' => now()->toIso8601String(), 'by' => $by->id, 'fields' => ['business_field' => 'Tư vấn du học'], 'label' => 'AI', 'demo' => false]);
        $c = Livewire::test(BrainBuildPage::class);
        $c->assertSee('Đã tự điền hồ sơ doanh nghiệp từ website lúc')->assertSee('Hoàn tác');
        $c->call('undoBrand')->assertNotified();
        $this->assertNull(BrandProfile::first()->business_field);
        $c->assertDontSee('Đã tự điền hồ sơ doanh nghiệp từ website lúc');
    }
}
