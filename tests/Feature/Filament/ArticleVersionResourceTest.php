<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Resources\ArticleVersionResource\Pages\ViewArticleVersion;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleVersionResourceTest extends TestCase
{
    use RefreshDatabase;

    private Article $article;

    protected function setUp(): void
    {
        parent::setUp();
        $website = Website::create(['name' => 'Site', 'cms_type' => 'wordpress', 'base_url' => 'https://example.com', 'encrypted_credentials' => ['password' => 'BI-MAT-XYZ']]);
        $this->article = Article::create(['website_id' => $website->id, 'primary_keyword' => 'máy lọc nước']);
    }

    private function user(string $role = 'writer', bool $active = true): User
    {
        return User::create(['name' => 'U', 'email' => uniqid() . '@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    private function version(array $o = []): ArticleVersion
    {
        return ArticleVersion::create($o + [
            'article_id' => $this->article->id, 'version' => 1, 'title' => 'Hướng dẫn chọn máy lọc nước',
            'slug' => 'huong-dan', 'html_content' => '<h2>Mở đầu</h2><p>Nội dung tiếng Việt</p>',
        ]);
    }

    public function test_list_and_view_render_vietnamese_data(): void
    {
        $this->actingAs($this->user());
        $v = $this->version(['cost_vnd' => 2450, 'tokens_used' => 1234, 'seo_title' => 'SEO', 'ai_provider' => 'gemini',
            'sources_cited' => ['https://evil.example/a', ['title' => 'Nguồn <b>x</b>']]]);

        $this->article->update(['workflow_status' => 'needs_review']);
        Livewire::test(\App\Filament\Resources\ArticleResource\Pages\ListArticles::class)->assertSuccessful()
            ->assertSee('máy lọc nước')->assertSee('Hướng dẫn chọn máy lọc nước')->assertSee('Chờ duyệt');

        $html = Livewire::test(ViewArticleVersion::class, ['record' => $v->id])->assertSuccessful()
            ->assertSee('2.450 ₫')->assertSee('AI đề xuất')->assertSee('Chưa có ảnh')->html();
        $this->assertStringContainsString('https://evil.example/a', $html);
        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringNotContainsString('Nguồn <b>x</b>', $html);
        $this->assertStringNotContainsString('BI-MAT-XYZ', $html);
    }

    public function test_null_fields_do_not_error(): void
    {
        $this->actingAs($this->user());
        $v = $this->version();
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])->assertSuccessful();
    }

    public function test_featured_image_shown(): void
    {
        $this->actingAs($this->user());
        $v = $this->version();
        $m = MediaAsset::create(['disk' => 'public', 'file_path' => 'a.webp', 'file_name' => 'a.webp', 'original_name' => 'a.jpg', 'mime_type' => 'image/webp', 'file_size' => 1, 'sha256' => str_repeat('a', 64), 'alt_text' => 'Ảnh máy lọc']);
        $v->mediaAssets()->attach($m->id, ['is_featured' => true]);
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])->assertSuccessful()->assertSee('Ảnh máy lọc', false);
    }

    public function test_history_lists_other_versions_with_links(): void
    {
        $this->actingAs($this->user());
        $v1 = $this->version();
        $v2 = $this->version(['version' => 2, 'review_status' => 'approved']);
        $v3 = $this->version(['version' => 3, 'review_status' => 'rejected']);
        $html = Livewire::test(ViewArticleVersion::class, ['record' => $v2->id])->assertSee('Đã duyệt')->assertSee('Cần sửa')->html();
        $this->assertStringContainsString(ArticleVersionResource::getUrl('view', ['record' => $v1]), $html);
        $this->assertStringContainsString(ArticleVersionResource::getUrl('view', ['record' => $v3]), $html);
    }

    public function test_filter_by_review_status(): void
    {
        $this->actingAs($this->user());
        $this->version(['title' => 'Bản chờ']);
        $other = Article::create(['website_id' => $this->article->website_id, 'primary_keyword' => 'khác', 'workflow_status' => 'approved']);
        $this->article->update(['workflow_status' => 'needs_review']);
        Livewire::test(\App\Filament\Resources\ArticleResource\Pages\ListArticles::class)->set('activeTab', 'approved')
            ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$this->article]);
    }

    public function test_xss_is_neutralised_and_iframe_sandboxed(): void
    {
        $this->actingAs($this->user());
        $v = $this->version(['html_content' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)><iframe src="javascript:alert(1)"></iframe>']);
        $html = Livewire::test(ViewArticleVersion::class, ['record' => $v->id])->html();
        $this->assertMatchesRegularExpression('/<iframe[^>]*sandbox=""/', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
    }

    public function test_authorization(): void
    {
        $this->get('/admin/article-versions')->assertRedirect();
        $this->actingAs($this->user('writer', false))->get('/admin/article-versions')->assertForbidden();
        $this->actingAs($this->user('writer'))->get('/admin/article-versions')->assertRedirect(ArticleResource::getUrl('index'));
        $this->get('/admin/article-versions?tableFilters[review_status][value]=needs_review')->assertRedirect(ArticleResource::getUrl('index', ['activeTab' => 'needs_review']));
        $this->assertFalse(ArticleVersionResource::canCreate());
        $this->assertFalse(ArticleVersionResource::canEdit($this->version()));
        $this->assertFalse(ArticleVersionResource::canDelete($this->version(['version' => 2])));
        $this->assertArrayNotHasKey('create', ArticleVersionResource::getPages());
        $this->assertArrayNotHasKey('edit', ArticleVersionResource::getPages());
    }
}
