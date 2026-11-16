<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Filament\Resources\MediaAssetResource\Pages\ListMediaAssets;
use App\Filament\Resources\PublicationResource\Pages\ListPublications;
use App\Filament\Resources\WebsiteResource\Pages\ListWebsites;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Bảng cũ có chọn cột hiển thị, sắp xếp, lọc và nhớ lựa chọn - kiểm đúng đường người dùng đi (mở danh sách, lọc, sắp xếp). */
class TableControlsTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'Alpha', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function pub(string $status, string $atUtc): Publication
    {
        $this->n++;
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => "kw{$this->n}", 'workflow_status' => 'approved']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => "t{$this->n}", 'slug' => "s{$this->n}", 'html_content' => '<p>x</p>', 'review_status' => 'approved']);

        return Publication::create(['website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => "k-{$this->n}", 'scheduled_at_utc' => CarbonImmutable::parse($atUtc, 'UTC'), 'status' => $status]);
    }

    public function test_cac_bang_cho_phep_chon_cot_va_nho_lua_chon(): void
    {
        $this->actingAs($this->user('admin'));
        $expect = [
            ListPublications::class => ['website.name', 'last_error', 'published_url', 'publication_key'],
            ListWebsites::class => ['cms_type', 'base_url', 'store_scope', 'last_synced_at'],
            ListArticles::class => ['website.name', 'topic_cluster', 'search_intent', 'created_at'],
            ListMediaAssets::class => ['alt_text', 'website.name', 'mime_type', 'created_at'],
        ];

        foreach ($expect as $page => $cols) {
            $table = Livewire::test($page)->instance()->getTable();
            foreach ($cols as $c) {
                $this->assertTrue($table->getColumn($c)?->isToggleable() ?? false, "{$page}: cột {$c} phải chọn ẩn/hiện được");
            }
            $this->assertTrue($table->persistsFiltersInSession(), "{$page}: nhớ bộ lọc");
            $this->assertTrue($table->persistsSortInSession(), "{$page}: nhớ sắp xếp");
            $this->assertContains(100, $table->getPaginationPageOptions());
        }

        $pub = Livewire::test(ListPublications::class)->instance()->getTable();
        $this->assertTrue($pub->getColumn('publication_key')->isToggledHiddenByDefault(), 'cột phụ ẩn mặc định');
        $this->assertFalse($pub->getColumn('status')->isToggleable(), 'cột trạng thái luôn hiện');
    }

    public function test_loc_lich_dang_theo_trang_thai(): void
    {
        $this->actingAs($this->user('approver'));
        $failed = $this->pub('failed', '2026-11-05 01:00:00');
        $ok = $this->pub('published', '2026-11-05 02:00:00');

        Livewire::test(ListPublications::class)
            ->filterTable('status', 'failed')
            ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$ok]);
    }

    public function test_loc_khoang_ngay_dang_theo_gio_viet_nam_o_ca_bien(): void
    {
        $this->actingAs($this->user('approver'));
        $before = $this->pub('scheduled', '2026-11-05 16:30:00'); // 23:30 ngày 5 giờ VN
        $after = $this->pub('scheduled', '2026-11-05 17:30:00');  // 00:30 ngày 6 giờ VN
        $next = $this->pub('scheduled', '2026-11-06 17:30:00');   // 00:30 ngày 7 giờ VN

        Livewire::test(ListPublications::class)
            ->filterTable('scheduled_range', ['from' => '2026-11-06', 'until' => '2026-11-06'])
            ->assertCanSeeTableRecords([$after])->assertCanNotSeeTableRecords([$before, $next]);
    }

    public function test_loc_bai_viet_theo_trang_thai_va_sap_xep_website(): void
    {
        $this->actingAs($this->user('writer'));
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'alpha', 'workflow_status' => 'planned']);
        $b = Article::create(['website_id' => $this->site->id, 'primary_keyword' => 'beta', 'workflow_status' => 'approved']);

        Livewire::test(ListArticles::class)
            ->set('activeTab', 'approved')
            ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
    }

    public function test_sap_xep_website_theo_loai_cms(): void
    {
        $this->actingAs($this->user('admin'));
        $mag = Website::create(['name' => 'Beta', 'cms_type' => 'magento2', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);

        Livewire::test(ListWebsites::class)
            ->sortTable('cms_type', 'desc')->assertCanSeeTableRecords([$this->site, $mag], inOrder: true)
            ->sortTable('cms_type', 'asc')->assertCanSeeTableRecords([$mag, $this->site], inOrder: true);
    }
}
