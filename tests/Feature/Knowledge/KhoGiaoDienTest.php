<?php

namespace Tests\Feature\Knowledge;

use App\Filament\Pages\BrainBuildPage;
use App\Filament\Pages\BrainLibraryPage;
use App\Filament\Widgets\AgentTodayWidget;
use App\Models\Brain;
use App\Models\BrandProfile;
use App\Models\KnowledgeGap;
use App\Models\User;
use App\Models\Website;
use App\Services\Brand\BrandProfileData;
use App\Services\Knowledge\Build\BrainBuildService;
use App\Services\Knowledge\Suggest\KnowledgeTodo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Giao diện và số liệu các luồng kho: banner dẫn đúng website và khớp số (#43), lý do 0 mục (#44), nhãn tiếng Việt (#45). */
class KhoGiaoDienTest extends TestCase
{
    use RefreshDatabase;

    private Website $a;

    private Website $b;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->a = Website::create(['name' => 'Website Rỗng', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->b = Website::create(['name' => 'Website Nhiều Việc', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        foreach (['học bổng Úc', 'visa Canada', 'du học Đức'] as $q) {
            KnowledgeGap::create(['website_id' => $this->b->id, 'query' => $q, 'count' => 2, 'status' => 'open']);
        }
        $this->actingAs(User::create(['name' => 'ap', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));
    }

    public function test_banner_dan_toi_website_co_viec_va_so_khop_trang_den(): void
    {
        $todo = KnowledgeTodo::busiest();
        $this->assertSame($this->b->id, $todo['site']->id);
        $this->assertSame(3, $todo['suggestions']);

        $url = KnowledgeTodo::url($todo['site']->id);
        $this->assertStringContainsString('site='.$this->b->id, $url);
        $this->assertStringEndsWith('#can-xem', $url);

        // trang đến mặc định đúng website trong URL và liệt kê đúng số gợi ý banner nói
        $page = Livewire::withQueryParams(['site' => $this->b->id])->test(BrainBuildPage::class);
        $this->assertSame($this->b->id, $page->get('websiteId'));
        $page->assertSeeHtml('data-testid="bb-sg"');
        $this->assertSame($todo['suggestions'], substr_count($page->html(), 'data-testid="bb-sg"'));
    }

    public function test_mac_dinh_van_la_website_dau_khi_khong_co_site(): void
    {
        Livewire::test(BrainBuildPage::class)->assertSet('websiteId', $this->a->id);
        Livewire::withQueryParams(['site' => 99999])->test(BrainBuildPage::class)->assertSet('websiteId', $this->a->id);
    }

    public function test_banner_thu_vien_va_widget_cung_so_va_dich(): void
    {
        Brain::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        Livewire::test(BrainLibraryPage::class)
            ->assertSeeHtml('data-testid="bl-todo"')
            ->assertSee('Website Nhiều Việc: 3 gợi ý, 0 chờ duyệt')
            ->assertSeeHtml('site='.$this->b->id);

        $w = new AgentTodayWidget;
        $m = new \ReflectionMethod($w, 'getViewData');
        $m->setAccessible(true);
        $d = $m->invoke($w);
        $this->assertSame(3, $d['suggestions']);
        $this->assertStringContainsString('site='.$this->b->id, (string) $d['urls']['kb']);
        $this->assertSame('Website Nhiều Việc', $d['todoSite']);
    }

    public function test_that_su_khong_co_gi_thi_banner_khong_hien_so(): void
    {
        KnowledgeGap::query()->delete();
        $this->assertNull(KnowledgeTodo::busiest());
        Brain::create(['name' => 'T', 'slug' => 't', 'status' => 'active']);
        $html = Livewire::test(BrainLibraryPage::class)->html();
        $this->assertStringContainsString('Việc cần xem và nạp thêm kiến thức', $html);
        $this->assertStringNotContainsString('gợi ý,', $html);
        $w = new AgentTodayWidget;
        $m = new \ReflectionMethod($w, 'getViewData');
        $m->setAccessible(true);
        $this->assertSame(0, $m->invoke($w)['suggestions']);
    }

    public function test_moi_truong_ho_so_co_nhan_tieng_viet(): void
    {
        foreach (array_merge(['business_field', 'audience', 'voice', 'voice_notes'], BrandProfile::LISTS) as $f) {
            $label = BrandProfileData::label($f);
            $this->assertDoesNotMatchRegularExpression('/[a-z]+_[a-z]+/', $label, $f);
            $this->assertNotSame('Trường khác', $label, $f);
        }
        $this->assertSame('Điều được phép nói, Thông điệp chính', BrandProfileData::labels(['allowed_claims', 'key_messages']));
        $this->assertSame('Trường khác', BrandProfileData::label('khong_biet'));
    }

    public function test_thong_bao_hoan_tac_ho_so_khong_lo_ten_truong(): void
    {
        $p = BrandProfile::create(['website_id' => $this->b->id, 'allowed_claims' => ['Giao hàng 24h'], 'key_messages' => ['Uy tín']]);
        Cache::forever(\App\Services\Knowledge\Build\BrandProfileDrafter::cacheKey($this->b->id), ['at' => now()->toIso8601String(), 'by' => null, 'fields' => ['allowed_claims' => ['Giao hàng 24h'], 'key_messages' => ['Uy tín']], 'label' => null, 'demo' => true]);
        $html = Livewire::withQueryParams(['site' => $this->b->id])->test(BrainBuildPage::class)->assertSee('Điều được phép nói, Thông điệp chính')->html();
        $this->assertDoesNotMatchRegularExpression('/Đã tự điền hồ sơ doanh nghiệp[^<]*[a-z]+_[a-z]+/', $html);

        Livewire::withQueryParams(['site' => $this->b->id])->test(BrainBuildPage::class)->call('undoBrand')
            ->assertNotified(\Filament\Notifications\Notification::make()->title('Đã hoàn tác hồ sơ tự điền')->body('Đã xóa: Điều được phép nói, Thông điệp chính.')->success());
        $this->assertSame([], $p->fresh()->key_messages);
    }

    public function test_giai_thich_vi_sao_khong_co_muc_nao(): void
    {
        $this->assertNull(BrainBuildService::explainNoItems(['items_auto' => 1, 'items_proposed' => 0, 'pages_no_items' => 3]));
        $this->assertNull(BrainBuildService::explainNoItems(['items_auto' => 0, 'items_proposed' => 0]));
        $m = BrainBuildService::explainNoItems(['items_auto' => 0, 'items_proposed' => 0, 'pages_read' => 3, 'pages_no_items' => 3, 'pages_short' => 0, 'pages_unchanged' => 0, 'pages_failed' => 0, 'stopped_reason' => null]);
        $this->assertStringContainsString('3 trang đã đọc nhưng không có câu khẳng định cụ thể nào rút được', $m);
        $m = BrainBuildService::explainNoItems(['items_auto' => 0, 'items_proposed' => 0, 'pages_no_items' => 1, 'pages_short' => 1, 'pages_unchanged' => 2, 'pages_failed' => 1, 'stopped_reason' => 'ai_error']);
        foreach (['AI báo lỗi', '1 trang có quá ít chữ', '2 trang không đổi', '1 trang không tải được'] as $needle) {
            $this->assertStringContainsString($needle, $m);
        }
    }

    public function test_trang_xay_kho_co_trang_thai_dang_chay_va_ten_de_tim(): void
    {
        $html = Livewire::test(BrainBuildPage::class)->html();
        $this->assertStringContainsString('data-testid="bb-running"', $html);
        $this->assertStringContainsString('Đừng đóng hoặc tải lại trang', $html);
        $this->assertStringContainsString('Xây kho từ website', $html);
    }

    /** #42: ba số kho có nhãn rõ nghĩa (đã duyệt khớp chủ đề; mục mới nạp chờ duyệt không tính). Kiểm nhãn trong mã vì cần phiên AI thật để chạy đủ luồng. */
    public function test_nhan_ba_so_kho_noi_ro_nghia(): void
    {
        $orch = file_get_contents(base_path('app/Services/Agent/Run/AgentRunOrchestrator.php'));
        $this->assertStringContainsString("mục kho đã duyệt khớp chủ đề (mục mới nạp đang chờ duyệt chưa tính)", $orch);
        $this->assertStringContainsString("mục kiến thức đã duyệt khớp chủ đề (mục chờ duyệt không tính)", $orch);
        $this->assertStringContainsString('chưa dùng được cho bài này cho tới khi được duyệt', $orch);
        $view = file_get_contents(resource_path('views/filament/article/workspace.blade.php'));
        $this->assertStringContainsString('mục đã duyệt khớp chủ đề</span>', $view);
    }
}
