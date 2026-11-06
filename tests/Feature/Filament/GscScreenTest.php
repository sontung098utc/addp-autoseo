<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\SearchConsolePage;
use App\Filament\Support\Demo\DemoStore;
use App\Filament\Support\Gsc\PageArticleLinker;
use App\Filament\Support\Gsc\UpdateProposals;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GscScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
        Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    public function test_guest_writer_inactive_cannot_enter(): void
    {
        $this->get('/admin/search-console')->assertRedirect();
        $this->actingAs($this->user('writer'))->get('/admin/search-console')->assertForbidden();
        $this->actingAs($this->user('approver', false))->get('/admin/search-console')->assertStatus(403);
    }

    public function test_approver_sees_tabs_tables_and_kpis(): void
    {
        $this->actingAs($this->user('approver'));
        $c = Livewire::test(SearchConsolePage::class)
            ->assertDontSee('Dữ liệu minh họa')->assertDontSee('(minh họa)')
            ->assertSee('Tổng quan')->assertSee('Truy vấn')->assertSee('Thiết bị')->assertSee('Quốc gia')->assertSee('Cơ hội')->assertSee('Đề xuất')->assertSee('Quy tắc')
            ->assertSee('Clicks theo ngày')
            ->call('setDays', 14)->assertSet('days', 14);
        $dv = $c->viewData('dimRows');
        $this->assertSame(['query', 'page', 'device', 'country'], array_keys($dv));
        $this->assertContains('giày chạy bộ nam', array_column($dv['query'], 'key'));
        $this->assertContains('Máy tính bảng', array_column($dv['device'], 'key'));
        $this->assertContains('Việt Nam', array_column($dv['country'], 'key'));
        $row = $dv['query'][0];
        foreach (['id', 'key', 'clicks', 'impressions', 'ctr', 'position', 'd_clicks', 'd_position'] as $k) {
            $this->assertArrayHasKey($k, $row);
        }
        $this->assertStringStartsWith('query|', $row['id']);
        $opps = $c->viewData('oppRows');
        $this->assertNotEmpty($opps);
        $this->assertContains($opps[0]['type']['tone'], ['danger', 'warn', 'info']);
        $html = $c->html();
        $this->assertSame(1, substr_count($html, 'fi-btn-color-primary'), 'đúng một nút primary');
        $this->assertStringContainsString('Tạo đề xuất cập nhật', $html);
        // Vị trí: giảm số là tốt; clicks: tăng là tốt
        $this->assertMatchesRegularExpression('/d_position[^}]*good[^}]*down/', html_entity_decode($html));
        $this->assertMatchesRegularExpression('/d_clicks[^}]*good[^}]*up/', html_entity_decode($html));
    }

    public function test_row_selection_by_table_id(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(SearchConsolePage::class)->call('selectRowId', 'query|review nike pegasus')
            ->assertSet('selDim', 'query')->assertSet('selKey', 'review nike pegasus')->assertSee('AI đề xuất');
    }

    public function test_create_proposal_flow_for_approver(): void
    {
        $u = $this->user('approver');
        $this->actingAs($u);
        $lw = Livewire::test(SearchConsolePage::class);
        $lw->call('selectItem', 'query', 'review nike pegasus')->assertSee('AI đề xuất')->call('createProposal');
        $all = UpdateProposals::all();
        $this->assertCount(1, $all);
        $this->assertSame('Chờ duyệt', $all[0]['status']);
        $this->assertSame($u->id, $all[0]['created_by']);
        $this->assertStringContainsString('Tụt hạng', implode(' ', $all[0]['reasons']));
        $this->assertSame('Chờ duyệt', $lw->viewData('proposalRows')[0]['status']['text']);
    }

    public function test_writer_cannot_create_proposal_server_side(): void
    {
        $this->expectException(AuthorizationException::class);
        UpdateProposals::create($this->user('writer'), ['key' => 'k', 'page' => null, 'cur' => ['clicks' => 1, 'impressions' => 2, 'ctr' => .5, 'position' => 3.0]], 'query', [], 'x');
    }

    public function test_writer_cannot_mount_page(): void
    {
        $this->actingAs($this->user('writer'));
        Livewire::test(SearchConsolePage::class)->assertForbidden();
    }

    public function test_connect_toggles_flag(): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::test(SearchConsolePage::class)->call('disconnect')->assertSee('chưa kết nối Google Search Console')
            ->call('connect')->assertSee('Clicks theo ngày');
        $this->assertTrue(DemoStore::get('gsc.connected'));
    }

    /** @dataProvider states */
    public function test_demo_states(string $state, string $text): void
    {
        $this->actingAs($this->user('approver'));
        Livewire::withQueryParams(['demo' => $state])->test(SearchConsolePage::class)->assertSee($text);
    }

    public static function states(): array
    {
        return [
            ['chua-ket-noi', 'chưa kết nối Google Search Console'],
            ['het-han', 'Kết nối hết hạn'],
            ['dang-dong-bo', 'Đang lấy dữ liệu lần đầu'],
            ['khong-quyen', 'không có quyền với property'],
            ['chua-co-du-lieu', 'Chưa có dữ liệu'],
            ['ket-noi', 'Clicks theo ngày'],
        ];
    }

    public function test_production_has_no_sample_numbers(): void
    {
        $this->actingAs($this->user('approver'));
        $this->app['env'] = 'production';
        Livewire::withQueryParams(['demo' => 'ket-noi'])->test(SearchConsolePage::class)
            ->assertSee('chưa kết nối Google Search Console')->assertDontSee('giày chạy bộ nam')->assertDontSee('Dữ liệu minh họa');
    }

    public function test_page_links_to_published_article(): void
    {
        $site = Website::first();
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => 'kw', 'workflow_status' => 'published']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => 'Bài thật', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
        Publication::create(['website_id' => $site->id, 'article_version_id' => $v->id, 'publication_key' => 'k1', 'scheduled_at_utc' => now(), 'status' => 'published', 'published_url' => 'https://blog.company.vn/giay-chay-bo-nam/']);
        $map = PageArticleLinker::map();
        $this->assertSame('Bài thật', PageArticleLinker::find('https://BLOG.company.vn/giay-chay-bo-nam', $map)['title']);
        $this->assertNull(PageArticleLinker::find('https://blog.company.vn/khac', $map));
        // đường người dùng: trang đầu tiên của dữ liệu mẫu dùng URL thật nên chọn nó hiện link bài
        $this->actingAs($this->user('approver'));
        Livewire::test(SearchConsolePage::class)->call('selectItem', 'page', 'https://blog.company.vn/giay-chay-bo-nam/')->assertSee('Bài thật');
    }
}
