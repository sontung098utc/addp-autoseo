<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiUsagePage;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\PublicationResource;
use App\Filament\Resources\PublicationResource\Pages\ListPublications;
use App\Filament\Support\Gsc\PageArticleLinker;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Mọi chỗ nói tới "một bài viết" phải trỏ về không gian bài viết (ArticleResource view), không phải trang cũ. */
class ArticleLinksTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    private ArticleVersion $version;

    private Publication $pub;

    protected function setUp(): void
    {
        parent::setUp();
        $t = CarbonImmutable::parse('2026-11-10 03:00:00', 'UTC');
        CarbonImmutable::setTestNow($t);
        Carbon::setTestNow($t);
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->article = Article::create(['website_id' => $site->id, 'primary_keyword' => 'kw-link', 'workflow_status' => 'scheduled']);
        $this->version = ArticleVersion::create(['article_id' => $this->article->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
        $this->pub = Publication::create([
            'website_id' => $site->id, 'article_version_id' => $this->version->id, 'publication_key' => 'k-1',
            'scheduled_at_utc' => CarbonImmutable::parse('2026-11-11 02:30:00', 'UTC'), 'timezone' => 'Asia/Ho_Chi_Minh', 'status' => 'needs_reconcile',
            'published_url' => 'https://a.vn/s',
        ]);
        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function articleUrl(): string
    {
        return ArticleResource::getUrl('view', ['record' => $this->article->id]);
    }

    private function oldEdit(): string
    {
        return ArticleResource::getUrl('edit', ['record' => $this->article->id]);
    }

    public function test_lich_tuan_tro_ve_bai_viet_va_giu_link_doi_soat(): void
    {
        $html = Livewire::test(ListPublications::class)->html();
        $this->assertStringContainsString($this->articleUrl().'#tab-publish', $html);
        $this->assertStringNotContainsString($this->oldEdit(), $html);
        $this->assertStringContainsString(PublicationResource::getUrl('view', ['record' => $this->pub]), $html); // nút Đối soát lượt đăng
    }

    public function test_lich_xuat_ban_cot_bai_tro_ve_bai_viet(): void
    {
        $html = Livewire::test(ListPublications::class)->html();
        $this->assertStringContainsString($this->articleUrl().'#tab-publish', $html);
        $this->assertStringNotContainsString($this->oldEdit(), $html);
    }

    public function test_chi_tiet_luot_dang_co_link_ve_bai_viet(): void
    {
        $this->get(PublicationResource::getUrl('view', ['record' => $this->pub]))->assertOk()->assertSee($this->articleUrl().'#tab-publish', false);
    }

    public function test_dashboard_widget_tro_ve_bai_viet(): void
    {
        $html = $this->get(Dashboard::getUrl())->assertOk()->getContent();
        $this->assertStringContainsString($this->articleUrl().'#tab-publish', $html); // Lượt đăng lỗi/đối soát
        $this->assertStringNotContainsString($this->oldEdit(), $html);
    }

    public function test_search_console_linker_tro_ve_bai_viet(): void
    {
        $found = PageArticleLinker::find('https://a.vn/s');
        $this->assertSame($this->articleUrl(), $found['url']);
    }

    public function test_chi_phi_ai_tro_ve_bai_viet(): void
    {
        $this->assertSame(1, AiUsagePage::getNavigationSort());
        $src = file_get_contents(app_path('Filament/Pages/AiUsagePage.php'));
        $this->assertStringContainsString("ArticleResource::getUrl('view', ['record' => \$a['article_id']])", $src);
        $this->assertStringNotContainsString('ArticleVersionResource::getUrl', $src);
    }
}
