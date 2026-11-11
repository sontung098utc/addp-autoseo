<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Support\Review\ReviewActions;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemUsage;
use App\Models\KnowledgeSource;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S27: không gian bài viết - bấm vào bài thì vào trang có đủ dữ liệu và thao tác của bài. */
class ArticleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'Alpha', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function article(string $status, bool $withVersion = true): Article
    {
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'máy lọc nước', 'workflow_status' => $status]);
        if ($withVersion) {
            ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'Chọn máy lọc nước', 'slug' => 'chon-may-loc-nuoc', 'html_content' => '<h2>Mở đầu</h2><p>Nội dung thử nghiệm.</p>', 'seo_title' => 'SEO title thử', 'meta_description' => 'Mô tả meta thử', 'review_status' => in_array($status, ['approved', 'rejected', 'needs_review'], true) ? $status : 'needs_review']);
        }

        return $a;
    }

    public function test_bam_vao_dong_bai_viet_thi_vao_khong_gian_bai(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('needs_review');

        $url = Livewire::test(ListArticles::class)->instance()->getTable()->getRecordUrl($a);

        $this->assertSame(ArticleResource::getUrl('view', ['record' => $a]), $url);
    }

    public function test_trang_bai_hien_du_noi_dung_seo_phien_ban_phien_ai_va_xuat_ban(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('approved');
        $v = $a->versions()->first();
        AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'triggered_by' => null, 'trigger' => 'manual', 'status' => 'needs_review', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
        Publication::create(['website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => 'k-1', 'scheduled_at_utc' => now()->addDay(), 'status' => 'scheduled']);

        $this->get(ArticleResource::getUrl('view', ['record' => $a]))
            ->assertOk()
            ->assertSee('data-testid="article-workspace"', false)
            ->assertSee('Chọn máy lọc nước')
            ->assertSee('Việc tiếp theo')
            ->assertSee('data-testid="aw-chain"', false)
            ->assertSee('data-testid="aw-keyword"', false)
            ->assertSee('data-testid="aw-plan"', false)
            ->assertSee('data-testid="aw-knowledge"', false)
            ->assertSee('data-testid="aw-versions"', false)
            ->assertSee(ArticleVersionResource::getUrl('view', ['record' => $v]), false)
            ->assertDontSee('Nội dung thử nghiệm.')
            ->assertSee('data-testid="aw-runs"', false)
            ->assertSee('data-testid="aw-publications"', false)
            ->assertDontSee('data-testid="aw-live"', false)
            ->assertDontSee('wire:poll', false);
    }

    public function test_bai_chua_co_noi_dung_van_mo_duoc_va_chi_cach_lam_tiep(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article('planned', false);

        $this->get(ArticleResource::getUrl('view', ['record' => $a]))
            ->assertOk()
            ->assertSee('máy lọc nước')
            ->assertSee('Bài chưa có nội dung')
            ->assertSee('Bài này chưa được giao cho đội AI.');
    }

    public function test_nut_thao_tac_hien_theo_trang_thai_va_quyen(): void
    {
        $review = $this->article('needs_review');
        $planned = $this->article('planned', false);

        $this->actingAs($this->user('approver'));
        Livewire::test(ViewArticle::class, ['record' => $review->getRouteKey()])
            ->assertActionVisible('approve')->assertActionVisible('reject')
            ->assertActionHidden('schedule')->assertActionHidden('run_agent');
        Livewire::test(ViewArticle::class, ['record' => $planned->getRouteKey()])
            ->assertActionVisible('run_agent')->assertActionHidden('approve');

        $this->actingAs($this->user('writer'));
        Livewire::test(ViewArticle::class, ['record' => $review->getRouteKey()])
            ->assertActionHidden('approve')->assertActionHidden('reject');
        Livewire::test(ViewArticle::class, ['record' => $planned->getRouteKey()])
            ->assertActionVisible('run_agent');
    }

    public function test_duyet_ngay_tren_trang_bai(): void
    {
        $author = $this->user('writer');
        $a = $this->article('needs_review');
        $a->update(['created_by' => $author->id]);
        $this->actingAs($this->user('approver'));

        $quality = ReviewActions::qualityFor($a->versions()->first());
        $data = $quality->hasBlocking() ? ['override_reason' => 'Bài thử nghiệm, chấp nhận ngoại lệ để kiểm luồng.'] : [];

        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])
            ->callAction('approve', $data)
            ->assertHasNoActionErrors();

        $this->assertSame('approved', $a->fresh()->workflow_status);
    }

    public function test_sua_thong_tin_ngay_trong_trang_bai_va_luu_xong_o_lai(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('needs_review');

        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])
            ->assertSee('data-testid="aw-info-form"', false)
            ->assertSee('Thông tin bài viết')
            ->assertDontSee('Thông tin chiến dịch SEO')
            ->fillForm(['topic_cluster' => 'Lọc nước gia đình'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNoRedirect();

        $this->assertSame('Lọc nước gia đình', $a->fresh()->topic_cluster);
    }

    public function test_duong_dan_sua_cu_cung_mo_khong_gian_bai(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('needs_review');

        $this->get("/admin/articles/{$a->id}/edit")->assertOk()->assertSee('data-testid="article-workspace"', false);
    }

    public function test_tab_noi_dung_la_danh_sach_phien_ban_co_nut_xem_sang_trang_chi_tiet(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('needs_review');
        $old = $a->versions()->first();
        $old->update(['review_status' => 'rejected', 'review_notes' => 'Thiếu số liệu']);
        $new = ArticleVersion::create(['article_id' => $a->id, 'version' => 2, 'title' => 'Bản hai', 'slug' => 'ban-hai', 'html_content' => '<p>Nội dung bản hai.</p>', 'review_status' => 'needs_review']);

        $html = $this->get(ArticleResource::getUrl('view', ['record' => $a]))->assertOk()
            ->assertSee('Bản hai')->assertSee('Chọn máy lọc nước')->assertSee('Thiếu số liệu')
            ->assertSee(ArticleVersionResource::getUrl('view', ['record' => $old]), false)
            ->assertSee(ArticleVersionResource::getUrl('view', ['record' => $new]), false)
            ->assertDontSee('Nội dung bản hai.')
            ->getContent();

        // Tổng quan là tab đầu tiên; bản mới nhất đứng trên.
        $this->assertLessThan(strpos($html, 'data-key="keyword"'), strpos($html, 'data-key="overview"'));
        $this->assertLessThan(strpos($html, 'data-version="1"'), strpos($html, 'data-version="2"'));
    }

    public function test_tu_khoa_cum_va_ke_hoach_hien_dung_lien_ket_cua_bai(): void
    {
        $this->actingAs($this->user('writer'));
        $cluster = KeywordCluster::create(['website_id' => $this->site->id, 'name' => 'Lọc nước gia đình']);
        $kw = Keyword::create(['website_id' => $this->site->id, 'keyword' => 'máy lọc nước', 'keyword_key' => 'máy lọc nước', 'volume' => 12400, 'difficulty' => 35, 'intent' => 'commercial', 'source' => 'csv', 'cluster_id' => $cluster->id, 'status' => 'has_article']);
        Keyword::create(['website_id' => $this->site->id, 'keyword' => 'lõi lọc nước', 'keyword_key' => 'lõi lọc nước', 'volume' => 900, 'source' => 'csv', 'cluster_id' => $cluster->id, 'status' => 'new']);
        $a = $this->article('needs_review');
        $a->update(['keyword_id' => $kw->id, 'cluster_id' => $cluster->id, 'origin' => 'plan', 'priority' => 80]);

        $this->get(ArticleResource::getUrl('view', ['record' => $a]))->assertOk()
            ->assertSee('12.400')->assertSee('35/100')->assertSee('Thương mại')
            ->assertSee('Kế hoạch nội dung')
            ->assertSee('data-testid="aw-cluster"', false)
            ->assertSee('lõi lọc nước')->assertSee('Chưa có bài')
            ->assertDontSee('dò theo tên');

        // Bài cũ không gắn keyword_id (tạo trước khi có liên kết tự động): dò theo tên và nói rõ.
        $b = $this->article('needs_review');
        $b->update(['keyword_id' => null]);
        $this->get(ArticleResource::getUrl('view', ['record' => $b]))->assertOk()->assertSee('12.400')->assertSee('dò theo tên');
    }

    public function test_bai_khong_co_trong_kho_tu_khoa_thi_noi_ro(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('needs_review');
        // Từ khóa bị xóa khỏi kho sau khi bài đã tạo: keyword_id về null và không còn bản ghi nào để dò.
        Keyword::where('website_id', $this->site->id)->delete();
        $a->refresh();

        $this->get(ArticleResource::getUrl('view', ['record' => $a]))->assertOk()
            ->assertSee('chưa có trong kho từ khóa')
            ->assertSee('chưa có hồ sơ doanh nghiệp')
            ->assertDontSee('data-testid="aw-cluster"', false);
    }

    public function test_mo_hop_giao_doi_ai_khong_loi_500_khi_trang_render_lai(): void
    {
        // Hồi quy: nút "Giao đội AI" gắn quan hệ giả lastRun lên bài, rồi getViewData gọi refresh() và ném RelationNotFoundException (500).
        $this->actingAs($this->user('admin'));
        $a = $this->article('planned', false);

        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])
            ->mountAction('run_agent')
            ->assertHasNoActionErrors()
            ->assertActionMounted('run_agent')
            ->callMountedAction()   // chạy xong nút (gọi $this->record->refresh()) cũng không được lỗi
            ->assertHasNoActionErrors();
    }

    public function test_phien_dang_chay_thi_tab_hoat_dong_co_hieu_ung_va_tu_lam_moi(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('planned', false);
        $run = AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'triggered_by' => null, 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()->subMinute()]);
        foreach (['guard' => 'Được phép chạy', 'risk' => 'Hạng rủi ro: thấp', 'brief' => 'Lập dàn ý (3 mục)'] as $stage => $title) {
            AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => AgentRunStep::count() + 1, 'stage' => $stage, 'role' => 'system', 'status' => 'ok', 'title' => $title, 'data' => $stage === 'brief' ? ['outline' => ['Tổng quan', 'Cách chọn']] : []]);
        }

        $this->get(ArticleResource::getUrl('view', ['record' => $a]))->assertOk()
            ->assertSee('wire:poll', false)
            ->assertSee('data-testid="aw-live"', false)
            ->assertSee('data-live-stage="write"', false)
            ->assertSee('ss-stage-live', false)
            ->assertSee('data-link="write" data-state="run"', false)
            ->assertSee('Lập dàn ý (3 mục)')
            ->assertSee('Cách chọn');
    }

    public function test_nut_xem_o_danh_sach_cung_dich_voi_bam_vao_dong(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('published');

        Livewire::test(ListArticles::class)
            ->assertTableActionHasUrl('view_version', ArticleResource::getUrl('view', ['record' => $a]), $a)
            ->assertTableActionDoesNotExist('edit');
    }

    public function test_trang_chi_tiet_phien_ban_co_nut_ve_bai_viet(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('needs_review');
        $v = $a->versions()->first();

        $this->get(ArticleVersionResource::getUrl('view', ['record' => $v]))->assertOk()
            ->assertSee('Về bài viết')
            ->assertSee(ArticleResource::getUrl('view', ['record' => $a]).'#tab-content', false);
    }

    public function test_the_san_pham_noi_that_khi_chua_co(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('needs_review');
        $this->get(ArticleResource::getUrl('view', ['record' => $a]))
            ->assertOk()->assertSee('data-testid="aw-products"', false)->assertSee('Chưa có sản phẩm nào trong kho');
    }

    public function test_the_san_pham_liet_ke_muc_product_da_dung(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('needs_review');
        $src = KnowledgeSource::create(['website_id' => $this->site->id, 'kind' => 'url', 'title' => 'Trang SP', 'url' => 'https://a.vn/sp/rox', 'status' => 'done']);
        $it = KnowledgeItem::create(['website_id' => $this->site->id, 'source_id' => $src->id, 'type' => 'product', 'title' => 'Máy ROX', 'entity' => 'ROX 9', 'body' => 'b', 'status' => 'approved', 'version' => 1, 'content_hash' => 'h1']);
        KnowledgeItemUsage::create(['article_version_id' => $a->versions()->first()->id, 'item_id' => $it->id, 'item_version' => 1]);
        $this->get(ArticleResource::getUrl('view', ['record' => $a]))
            ->assertOk()->assertSee('ROX 9')->assertSee('https://a.vn/sp/rox', false)->assertDontSee('Chưa có sản phẩm nào trong kho');
    }

    public function test_nghien_cuu_doc_tu_buoc_research_roi_ve_buoc_write(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('needs_review');
        $run = AgentRun::create(['website_id' => $this->site->id, 'article_id' => $a->id, 'triggered_by' => null, 'trigger' => 'manual', 'status' => 'needs_review', 'started_at' => now(), 'finished_at' => now()]);
        $step = fn (int $seq, string $stage, array $data) => AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => $seq, 'stage' => $stage, 'role' => 'system', 'status' => 'ok', 'title' => $stage, 'data' => $data]);
        $step(1, 'write', ['knowledge' => ['gap' => true], 'images' => ['warning' => 'ẢNH-CŨ']]);
        $url = ArticleResource::getUrl('view', ['record' => $a]);
        $this->get($url)->assertSee('ẢNH-CŨ')->assertDontSee('Nguồn được phép trích');

        $step(2, 'research', ['knowledge' => ['gap' => false], 'images' => ['warning' => 'ẢNH-MỚI'], 'citable_sources' => [['title' => 'Bộ Y tế'], ['title' => 'WHO']]]);
        $this->get($url)->assertSee('ẢNH-MỚI')->assertDontSee('ẢNH-CŨ')->assertDontSee('data-testid="aw-gap"', false)
            ->assertSee('Nguồn được phép trích: 2')->assertSee('Bộ Y tế');
    }

    public function test_trang_bai_hien_banner_khi_he_thong_dang_dung_khan_cap(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);
        $a = $this->article('planned', false);

        $this->get(ArticleResource::getUrl('view', ['record' => $a]))->assertOk()->assertDontSee('aw-stopped', false);

        $svc = app(EmergencyStopService::class);
        $svc->resetForTesting();
        $svc->stop($admin, 'Sự cố kiểm thử');
        $this->get(ArticleResource::getUrl('view', ['record' => $a]))->assertOk()->assertSee('aw-stopped', false)->assertSee('DỪNG KHẨN CẤP')->assertSee('Sự cố kiểm thử');
        $svc->resetForTesting();
    }
}
