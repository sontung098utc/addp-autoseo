<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Trang bài từng dựng cả 6 tab một lần (994 KB, 1,5-3 s) rồi mới hiện. Nay lần đầu chỉ dựng tab đang hiện, các tab còn lại hiện khung "Đang tải…"
 * và được nạp ngay sau đó ở nền (hoặc ngay khi bấm vào tab).
 */
class ArticleLazyTabsTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ui.lazy_tabs' => true]);
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->article = Article::create(['website_id' => $site->id, 'primary_keyword' => 'huyết áp thấp', 'workflow_status' => 'needs_review']);
        ArticleVersion::create(['article_id' => $this->article->id, 'version' => 1, 'title' => 'Tiêu đề bản 1', 'slug' => 's', 'html_content' => '<h2>A</h2><p>Nội dung.</p>', 'review_status' => 'needs_review']);
        $run = AgentRun::create(['website_id' => $site->id, 'article_id' => $this->article->id, 'job_type' => 'article_write', 'trigger' => 'manual', 'status' => 'ready_for_review', 'ai_mode' => 'real', 'started_at' => now()]);
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => 1, 'stage' => 'research', 'role' => 'system', 'status' => 'ok', 'title' => 'Nạp kho thử', 'detail' => 'd',
            'data' => ['purpose' => 'kb_coordination', 'brain_name' => 'Trí tuệ thử', 'items_auto' => 1, 'items_proposed' => 0, 'sources' => [], 'item_ids' => ['auto' => [], 'proposed' => []]]]);
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
    }

    public function test_lan_dau_chi_dung_tab_mo_dau_con_lai_la_khung_cho_va_co_lenh_nap_nen(): void
    {
        $html = Livewire::test(ViewArticle::class, ['record' => $this->article->id])->html();

        $this->assertStringContainsString('Tiêu đề bản 1', $html, 'Tab mở đầu (Nội dung, vì bài đang chờ duyệt) có dữ liệu ngay.');
        $this->assertStringNotContainsString('data-testid="aw-skel-content"', $html);
        foreach (['overview', 'keyword', 'research', 'activity', 'publish'] as $k) {
            $this->assertStringContainsString('data-testid="aw-skel-'.$k.'"', $html, "Tab $k chưa nạp: khung chờ.");
        }
        $this->assertStringNotContainsString('data-testid="aw-kb-activity"', $html, 'Nội dung tab Nghiên cứu chưa được dựng.');
        $this->assertStringContainsString('wire:init="loadTabs"', $html, 'Trình duyệt nạp phần còn lại ngay sau lần dựng đầu.');
    }

    public function test_nap_nen_dua_ra_du_noi_dung_va_bo_lenh_nap(): void
    {
        $c = Livewire::test(ViewArticle::class, ['record' => $this->article->id])->call('loadTabs');
        $html = $c->html();
        $this->assertStringNotContainsString('aw-skel-', $html);
        $this->assertStringContainsString('data-testid="aw-kb-activity"', $html);
        $this->assertStringNotContainsString('wire:init="loadTabs"', $html, 'Đã nạp đủ thì không gọi nạp nữa.');
    }

    public function test_bam_tab_nao_nap_dung_tab_do_khong_dung_cac_tab_khac(): void
    {
        $html = Livewire::test(ViewArticle::class, ['record' => $this->article->id])->call('loadTabs', ['research'])->html();
        $this->assertStringContainsString('data-testid="aw-kb-activity"', $html);
        $this->assertStringContainsString('data-testid="aw-skel-activity"', $html, 'Chỉ nạp tab được xin.');
        $this->assertStringContainsString('wire:init="loadTabs"', $html);
    }

    public function test_khoa_tab_la_bi_bo_qua(): void
    {
        $c = Livewire::test(ViewArticle::class, ['record' => $this->article->id])->call('loadTabs', ['khong-co', '../x']);
        $this->assertSame([], $c->get('loadedTabs'));
    }

    public function test_dia_chi_co_phien_thi_tab_phien_chay_co_ngay(): void
    {
        $run = AgentRun::where('article_id', $this->article->id)->first();
        $html = Livewire::withQueryParams(['phien' => $run->id])->test(ViewArticle::class, ['record' => $this->article->id])->html();
        $this->assertStringNotContainsString('data-testid="aw-skel-activity"', $html);
    }

    public function test_lan_dau_it_truy_van_hon_dung_du(): void
    {
        DB::enableQueryLog();
        Livewire::test(ViewArticle::class, ['record' => $this->article->id]);
        $lazy = count(DB::getQueryLog());

        config(['ui.lazy_tabs' => false]);
        DB::flushQueryLog();
        Livewire::test(ViewArticle::class, ['record' => $this->article->id]);
        $eager = count(DB::getQueryLog());

        $this->assertLessThan($eager, $lazy, "Lần đầu chỉ tab mở đầu phải ít truy vấn hơn dựng đủ ($lazy so với $eager).");
    }

    public function test_chi_trang_bai_bat_nap_theo_tab_con_cac_trang_tab_khac_khong_goi_loadtabs(): void
    {
        $html = Livewire::test(ViewArticle::class, ['record' => $this->article->id])->html();
        $this->assertStringContainsString('data-lazy-tabs="1"', $html, 'Trang bài bật nạp theo tab.');

        // Trang có thanh tab nhưng KHÔNG có hàm loadTabs: nếu mã trình duyệt gọi nó thì máy chủ trả lỗi 500 (đã xảy ra trên server, trang Lịch xuất bản).
        $other = Livewire::test(\App\Filament\Resources\PublicationResource\Pages\ListPublications::class)->html();
        $this->assertStringContainsString('data-tabs="tabs-lich-xuat-ban"', $other);
        $this->assertStringNotContainsString('data-lazy-tabs', $other, 'Trang khác không được tự xưng nạp theo tab.');
        $this->assertFalse(method_exists(\App\Filament\Resources\PublicationResource\Pages\ListPublications::class, 'loadTabs'));
    }

    public function test_ma_trinh_duyet_khong_do_loadtabs_bang_typeof_vi_wire_tra_ham_cho_moi_ten(): void
    {
        $js = (string) file_get_contents(public_path('js/studio-ui.js'));
        $this->assertStringContainsString("dataset.lazyTabs === '1'", $js);
        $this->assertStringNotContainsString("typeof this.\$wire.loadTabs", $js);
    }
}
