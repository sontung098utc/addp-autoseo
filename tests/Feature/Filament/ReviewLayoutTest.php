<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Resources\ArticleVersionResource\Pages\ViewArticleVersion;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReviewLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'SECRET-PW-123'], 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid() . '@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function article(string $ws = 'needs_review'): Article
    {
        return Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'hoc bong', 'workflow_status' => $ws]);
    }

    private function version(Article $a, int $v, string $status, string $html = '<p>x</p>', array $extra = []): ArticleVersion
    {
        return ArticleVersion::create(array_merge(['article_id' => $a->id, 'version' => $v, 'title' => 't' . $v, 'slug' => 's', 'html_content' => $html, 'review_status' => $status], $extra));
    }

    public function test_renders_full_data(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article();
        $v = $this->version($a, 1, 'needs_review', '<p>Học phí 22 triệu</p>', [
            'seo_title' => 'SEO T', 'meta_description' => 'Mô tả', 'focus_keyword' => 'hb',
            'ai_provider' => 'gemini', 'ai_model' => 'm1', 'prompt_version' => 'v1', 'tokens_used' => 1200, 'cost_vnd' => 345,
            'sources_cited' => ['https://nguon.vn'],
        ]);
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->assertSee('Quyết định')->assertSee('Kiểm chất lượng')->assertSee('Thẻ SEO')
            ->assertSee('AI đã tạo')->assertSee('AI đề xuất')->assertSee('gemini')->assertSee('Duyệt & lịch sử')
            ->assertSee('Bước tiếp theo')->assertSee('audit_logs')->assertSee('Từ khóa chính: hoc bong')
            ->assertSee('Website đích: S')->assertSee('Phiên bản v1')
            ->assertDontSee('SECRET-PW-123');
    }

    public function test_renders_with_missing_data(): void
    {
        $this->actingAs($this->user('approver'));
        $v = $this->version($this->article(), 1, 'needs_review');
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->assertOk()->assertSee('Chưa có bản đã duyệt để so sánh')->assertSee('META01')->assertSee('Chưa có meta description')
            ->assertSee('Bài chưa có ảnh nào để kiểm tra alt');
    }

    public function test_quality_block_is_honest(): void
    {
        $this->actingAs($this->user('approver'));
        $v = $this->version($this->article(), 1, 'needs_review');
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->assertDontSee('Chưa có kiểm tra chất lượng tự động')
            ->assertSee('Tính tại thời điểm xem, chưa lưu lịch sử')
            ->assertSee('P0 chưa đạt: 3')  // LEN01 (1 từ) + HEAD01 (không heading) + KW01 (từ khóa "hoc bong" vắng trong bài)
            ->assertSee('LEN01')->assertSee('Đo được: 1 từ');
    }

    public function test_diff_counts_and_number_changes(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article();
        $this->version($a, 1, 'approved', '<p>Giữ nguyên.</p><p>Học phí là 22 triệu đồng mỗi năm.</p><p>Đoạn bị xóa hoàn toàn khác biệt.</p>');
        $v = $this->version($a, 2, 'needs_review', '<p>Giữ nguyên.</p><p>Học phí là 24 triệu đồng mỗi năm.</p><p>Một đoạn mới thêm vào bài viết.</p>');
        $html = Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->assertSee('So với v1')->assertSee('22 triệu → 24 triệu')->assertSee('sửa 2')->html();
        $this->assertStringContainsString('diff-block', $html);
        $this->assertStringContainsString('Thêm', $html);
    }

    public function test_diff_escapes_script(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->article();
        $this->version($a, 1, 'approved', '<p>Cũ.</p>');
        $v = $this->version($a, 2, 'needs_review', '<p>Cũ.</p><p>&lt;script&gt;alert(1)&lt;/script&gt; &lt;img src=x onerror=alert(1)&gt;</p>');
        $html = Livewire::test(ViewArticleVersion::class, ['record' => $v->id])->html();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_iframe_keeps_sandbox(): void
    {
        $this->actingAs($this->user('approver'));
        $v = $this->version($this->article(), 1, 'needs_review', '<p>a</p><script>alert(1)</script>');
        $html = Livewire::test(ViewArticleVersion::class, ['record' => $v->id])->html();
        $this->assertStringContainsString('sandbox=""', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function test_actions_by_role_and_status(): void
    {
        $this->actingAs($this->user('approver'));
        $pending = $this->version($this->article(), 1, 'needs_review');
        Livewire::test(ViewArticleVersion::class, ['record' => $pending->id])
            ->assertActionVisible('approve')->assertActionVisible('reject')->assertActionHidden('schedule');

        $approved = $this->version($this->article('approved'), 1, 'approved');
        Livewire::test(ViewArticleVersion::class, ['record' => $approved->id])
            ->assertActionHidden('approve')->assertActionHidden('reject')->assertActionVisible('schedule');
    }

    public function test_only_one_primary_action_per_state(): void
    {
        $this->actingAs($this->user('approver'));
        $pending = $this->version($this->article(), 1, 'needs_review');
        $t = Livewire::test(ViewArticleVersion::class, ['record' => $pending->id]);
        $c = [];
        foreach ($t->instance()->getCachedHeaderActions() as $action) {
            $c[$action->getName()] = $action->getColor();
        }
        $this->assertSame('primary', $c['approve']);
        $this->assertNotSame('primary', $c['reject']);
        // Xếp lịch chỉ hiện khi đã duyệt, khi đó Duyệt ẩn nên vẫn chỉ có một primary.
    }

    public function test_writer_sees_no_decision_actions(): void
    {
        $this->actingAs($this->user('writer'));
        $v = $this->version($this->article(), 1, 'needs_review');
        Livewire::test(ViewArticleVersion::class, ['record' => $v->id])
            ->assertActionHidden('approve')->assertActionHidden('reject')->assertActionHidden('schedule');
    }

    public function test_navigation_badge_counts_and_hides(): void
    {
        $this->actingAs($this->user('approver'));
        $this->assertNull(ArticleVersionResource::getNavigationBadge());
        $this->version($this->article(), 1, 'needs_review');
        $this->version($this->article(), 1, 'needs_review');
        $this->version($this->article(), 1, 'approved');
        $this->assertSame('2', ArticleVersionResource::getNavigationBadge());
        $this->assertSame('warning', ArticleVersionResource::getNavigationBadgeColor());
        $this->assertSame('Chiến dịch', ArticleVersionResource::getNavigationGroup());
        $this->assertSame('Duyệt bài', ArticleVersionResource::getNavigationLabel());
    }
}
