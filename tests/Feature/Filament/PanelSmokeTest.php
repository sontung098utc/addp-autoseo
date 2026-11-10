<?php

namespace Tests\Feature\Filament;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Duyệt qua MỌI trang của bảng quản trị bằng request HTTP thật (mount toàn trang, không gọi tắt service)
 * với từng vai trò: không trang nào được trả 500. 200 (được xem) hoặc 403/404 (bị chặn quyền) đều chấp nhận.
 * Bổ sung cho các test Livewire hiện có vốn chỉ gọi trực tiếp phương thức nên không bắt được lỗi dựng trang.
 */
class PanelSmokeTest extends TestCase
{
    use RefreshDatabase;

    private array $urls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $art = Article::create(['website_id' => $site->id, 'primary_keyword' => 'kw', 'workflow_status' => 'needs_review']);
        $v = ArticleVersion::create(['article_id' => $art->id, 'version' => 1, 'title' => 't', 'slug' => 's', 'html_content' => '<h2>a</h2><p>b</p>', 'review_status' => 'needs_review']);
        $pub = Publication::create(['website_id' => $site->id, 'article_version_id' => $v->id, 'publication_key' => 'k-1', 'scheduled_at_utc' => now()->addDay(), 'status' => 'scheduled']);

        $this->urls = [
            '/admin',
            '/admin/articles', '/admin/articles/create', "/admin/articles/{$art->id}", "/admin/articles/{$art->id}/edit",
            "/admin/article-versions/{$v->id}", '/admin/articles?activeTab=needs_review',
            '/admin/websites', '/admin/websites/create', "/admin/websites/{$site->id}/edit",
            '/admin/publications', "/admin/publications/{$pub->id}", '/admin/lich-tuan',
            '/admin/media-assets', '/admin/media-assets/create',
        ];
        foreach (['today', 'upcoming', 'attention', 'published', 'all'] as $tab) {
            $this->urls[] = "/admin/publications?activeTab={$tab}";
        }
    }

    /** @dataProvider roles */
    public function test_khong_trang_nao_tra_500(string $role): void
    {
        $this->actingAs(User::create(['name' => $role, 'email' => "$role@x.vn", 'password' => 'secret123', 'role' => $role, 'is_active' => true]));

        $bad = [];
        foreach ($this->urls as $url) {
            $status = $this->get($url)->getStatusCode();
            if ($status >= 500 || ! in_array($status, [200, 403, 404], true)) {
                $bad[] = "$url => $status";
            }
        }

        $this->assertSame([], $bad, "Các trang lỗi với vai trò {$role}");
    }

    public static function roles(): array
    {
        return [['admin'], ['approver'], ['writer']];
    }
}
