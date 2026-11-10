<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
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
            ->assertSee('Nội dung thử nghiệm.')
            ->assertSee('SEO title thử')
            ->assertSee('Mô tả meta thử')
            ->assertSee('data-testid="aw-quality"', false)
            ->assertSee('data-testid="aw-versions"', false)
            ->assertSee('data-testid="aw-runs"', false)
            ->assertSee('data-testid="aw-publications"', false)
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
            ->assertActionHidden('run_agent');
    }

    public function test_duyet_ngay_tren_trang_bai(): void
    {
        $author = $this->user('writer');
        $a = $this->article('needs_review');
        $a->update(['created_by' => $author->id]);
        $this->actingAs($this->user('approver'));

        $quality = \App\Filament\Support\Review\ReviewActions::qualityFor($a->versions()->first());
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

    public function test_chon_phien_ban_ngay_trong_tab_noi_dung(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('needs_review');
        $old = $a->versions()->first();
        $old->update(['review_status' => 'rejected', 'review_notes' => 'Thiếu số liệu']);
        ArticleVersion::create(['article_id' => $a->id, 'version' => 2, 'title' => 'Bản hai', 'slug' => 'ban-hai', 'html_content' => '<p>Nội dung bản hai.</p>', 'review_status' => 'needs_review']);
        $other = $this->article('needs_review')->versions()->first();

        Livewire::test(ViewArticle::class, ['record' => $a->getRouteKey()])
            ->assertSee('Nội dung bản hai.')->assertDontSee('Nội dung thử nghiệm.')
            ->set('versionId', $old->id)
            ->assertSee('Nội dung thử nghiệm.')->assertSee('Thiếu số liệu')->assertSee('Bạn đang xem bản cũ v1')
            // bản của bài khác không được hiện: quay về bản mới nhất
            ->set('versionId', $other->id)
            ->assertSee('Nội dung bản hai.');
    }

    public function test_nut_xem_o_danh_sach_cung_dich_voi_bam_vao_dong(): void
    {
        $this->actingAs($this->user('writer'));
        $a = $this->article('published');

        Livewire::test(ListArticles::class)
            ->assertTableActionHasUrl('view_version', ArticleResource::getUrl('view', ['record' => $a]), $a)
            ->assertTableActionDoesNotExist('edit');
    }
}
