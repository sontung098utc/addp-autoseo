<?php

namespace Tests\Feature;

use App\Filament\Resources\PublicationResource\Pages\ListPublications;
use App\Integrations\Magento\MagentoBlogUrl;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MagentoBlogUrlFixTest extends TestCase
{
    use RefreshDatabase;

    private function site(string $cms = 'magento2', string $base = 'https://addp.vn', ?array $flags = null): Website
    {
        return Website::create(['name' => 'Site '.uniqid(), 'cms_type' => $cms, 'base_url' => $base, 'status' => 'active', 'encrypted_credentials' => [], 'capability_flags' => $flags]);
    }

    private function pub(Website $site, ?string $url, string $status = 'published'): Publication
    {
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => 'k'.uniqid(), 'search_intent' => 'informational', 'workflow_status' => 'approved']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'T', 'slug' => 's'.uniqid(), 'html_content' => '<p>x</p>']);

        return Publication::create(['website_id' => $site->id, 'article_version_id' => $v->id, 'publication_key' => uniqid('pk'), 'scheduled_at_utc' => now(),
            'timezone' => 'Asia/Ho_Chi_Minh', 'status' => $status, 'published_url' => $url]);
    }

    public function test_legacy_fix_only_touches_old_magento_pattern(): void
    {
        $m = $this->site();
        $this->assertSame('https://addp.vn/blog/thuoc-ha-duong-huyet', MagentoBlogUrl::legacyFix('https://addp.vn/blog/post/thuoc-ha-duong-huyet', $m));
        $this->assertSame('https://addp.vn/blog/abc', MagentoBlogUrl::legacyFix('https://addp.vn/blog/post/abc/', $m));
        $this->assertNull(MagentoBlogUrl::legacyFix('https://addp.vn/blog/thuoc-ha-duong-huyet', $m));
        $this->assertNull(MagentoBlogUrl::legacyFix('https://addp.vn/blog/post/view/id/12', $m));
        $this->assertNull(MagentoBlogUrl::legacyFix('https://other.vn/blog/post/abc', $m));
        $this->assertNull(MagentoBlogUrl::legacyFix('https://a.vn/blog/post/abc', $this->site('wordpress', 'https://a.vn')));
        $this->assertNull(MagentoBlogUrl::legacyFix('https://p.vn/blog/post/abc', $this->site('magento2', 'https://p.vn', ['blog_driver' => 'mageplaza'])));
    }

    public function test_display_fixes_link_without_writing_db(): void
    {
        $m = $this->site();
        $p = $this->pub($m, 'https://addp.vn/blog/post/thuoc-ha-duong-huyet');
        $this->assertSame('https://addp.vn/blog/thuoc-ha-duong-huyet', MagentoBlogUrl::display($p));
        $this->assertSame('https://addp.vn/blog/post/thuoc-ha-duong-huyet', $p->fresh()->published_url);
        $this->assertNull(MagentoBlogUrl::display($this->pub($m, null)));
    }

    public function test_command_dry_run_by_default_then_force_writes(): void
    {
        $m = $this->site();
        $bad = $this->pub($m, 'https://addp.vn/blog/post/bai-a');
        $ok = $this->pub($m, 'https://addp.vn/blog/bai-b');
        $draft = $this->pub($m, 'https://addp.vn/blog/post/bai-c', 'scheduled');
        $wp = $this->pub($this->site('wordpress', 'https://w.vn'), 'https://w.vn/blog/post/bai-d');

        $this->artisan('publications:fix-magento-urls')->expectsOutputToContain('Chưa ghi gì')->assertSuccessful();
        $this->assertSame('https://addp.vn/blog/post/bai-a', $bad->fresh()->published_url);

        $this->artisan('publications:fix-magento-urls --force --website='.$m->id)->expectsOutputToContain('Đã sửa 1 link')->assertSuccessful();
        $this->assertSame('https://addp.vn/blog/bai-a', $bad->fresh()->published_url);
        $this->assertSame('https://addp.vn/blog/bai-b', $ok->fresh()->published_url);
        $this->assertSame('https://addp.vn/blog/post/bai-c', $draft->fresh()->published_url);
        $this->assertSame('https://w.vn/blog/post/bai-d', $wp->fresh()->published_url);

        $this->artisan('publications:fix-magento-urls --force')->expectsOutputToContain('Không có link nào cần sửa')->assertSuccessful();
    }

    public function test_publication_list_shows_fixed_link(): void
    {
        $this->actingAs(User::create(['name' => 'admin', 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => 'admin', 'is_active' => true]));
        $p = $this->pub($this->site(), 'https://addp.vn/blog/post/thuoc-ha-duong-huyet');

        Livewire::test(ListPublications::class)
            ->assertTableColumnStateSet('published_url', 'https://addp.vn/blog/thuoc-ha-duong-huyet', $p);
    }
}
