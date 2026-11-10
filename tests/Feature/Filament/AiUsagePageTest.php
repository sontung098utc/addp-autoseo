<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AiUsagePage;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Support\AiUsage\UsageThresholds;
use App\Filament\Support\Demo\AiUsageDemoData;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class AiUsagePageTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    private function version(string $kw, int $v, float $cost, ?CarbonImmutable $at = null, array $extra = [], ?Website $site = null, ?Article $article = null): ArticleVersion
    {
        $article ??= Article::create(['website_id' => ($site ?? $this->site)->id, 'primary_keyword' => $kw]);
        $row = new ArticleVersion(array_merge(['article_id' => $article->id, 'version' => $v, 'title' => 'Tiêu đề '.$kw, 'slug' => $kw.$v, 'html_content' => '<p>x</p>',
            'ai_model' => 'gemini-1.5-flash', 'prompt_version' => 'v1.0', 'tokens_used' => 3800, 'cost_vnd' => $cost], $extra));
        $row->created_at = $at ?? CarbonImmutable::now('Asia/Ho_Chi_Minh');
        $row->save();

        return $row;
    }

    private function as(string $role)
    {
        $this->actingAs($this->user($role));

        return Livewire::test(AiUsagePage::class);
    }

    public function test_writer_gets_403_on_route(): void
    {
        $this->actingAs($this->user('writer'))->get('/admin/chi-phi-ai')->assertForbidden();
        $this->assertFalse(AiUsagePage::canAccess());
    }

    public function test_inactive_user_blocked(): void
    {
        $this->actingAs($this->user('approver', false))->get('/admin/chi-phi-ai')->assertStatus(403);
    }

    public function test_approver_route_ok(): void
    {
        $this->actingAs($this->user('approver'))->get('/admin/chi-phi-ai')->assertOk()->assertSee('Chi phí AI');
    }

    public function test_admin_route_ok_and_menu_group(): void
    {
        $this->actingAs($this->user('admin'))->get('/admin/chi-phi-ai')->assertOk();
        $this->assertSame('Cài đặt', AiUsagePage::getNavigationGroup());
        $this->assertSame(3, AiUsagePage::getNavigationSort());
    }

    public function test_writer_livewire_mount_forbidden(): void
    {
        $this->actingAs($this->user('writer'));
        Livewire::test(AiUsagePage::class)->assertForbidden();
    }

    public function test_guest_redirected(): void
    {
        $this->get('/admin/chi-phi-ai')->assertRedirect();
    }

    public function test_empty_state_has_no_fake_zero(): void
    {
        $this->as('approver')->assertSee('Chưa có dữ liệu')->assertDontSee('data-ai="summary"', false);
    }

    public function test_summary_shows_real_numbers_and_definition(): void
    {
        $a = $this->version('k1', 1, 1000.0);
        $this->version('k1', 2, 2000.0, null, [], null, Article::find($a->article_id));
        $this->version('k2', 1, 600.0);
        // 2 bài, 3 phiên bản, tổng 3.600, TB/bài 1.800
        $this->as('approver')->assertSee('3.600 ₫')->assertSee('1.800 ₫')->assertSee('MỌI phiên bản')
            ->assertDontSee('▲ Trung bình');
    }

    public function test_avg_over_threshold_banner_and_boundary(): void
    {
        $this->version('k1', 1, 3412.0);
        $this->as('approver')->assertSee('▲ Vượt ngưỡng 3.000 ₫/bài:', false)->assertSee('trung bình 3.412 ₫/bài', false)->assertSee('▲ Vượt ngưỡng');
        $this->version('k2', 1, 2588.0); // TB = 3000 -> đúng bằng ngưỡng: không vượt
        $this->as('approver')->assertDontSee('trung bình 3.000 ₫/bài', false); // chỉ còn dải 'bài vượt' vì k1 một mình 3.412 > 3.000
    }

    /** Gói B #7: trung bình vượt và bài vượt gộp thành MỘT dải cảnh báo, vẫn nêu bài vượt ngưỡng. */
    public function test_avg_and_article_warnings_are_one_banner(): void
    {
        $this->version('k1', 1, 3412.0);
        $html = $this->as('approver')->html();
        $this->assertSame(1, substr_count($html, 'data-ai="over-threshold"'));
        $this->assertStringNotContainsString('data-ai="avg-over"', $html);
        $this->assertStringContainsString('1 bài vượt', $html);
    }

    public function test_month_threshold_banner(): void
    {
        $this->version('k1', 1, 1200.0);
        UsageThresholds::save(1000, 1100);
        $this->as('approver')->assertSee('đã vượt ngưỡng tháng');
        UsageThresholds::save(1000, 1200); // đúng bằng
        $this->as('approver')->assertDontSee('đã vượt ngưỡng tháng');
    }

    public function test_change_period_and_website(): void
    {
        $other = Website::create(['name' => 'Site B', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $now = CarbonImmutable::now('Asia/Ho_Chi_Minh');
        $this->version('now', 1, 111.0);
        $this->version('old', 1, 222.0, $now->startOfMonth()->subDays(3));
        $this->version('other', 1, 333.0, null, [], $other);
        $titles = fn ($c) => array_map(fn ($r) => $r['title']['text'], $c->instance()->getViewData()['topRows']);
        $c = $this->as('approver');
        $this->assertEqualsCanonicalizing(['Tiêu đề now', 'Tiêu đề other'], $titles($c));
        $c->set('period', 'thang-truoc');
        $this->assertSame(['Tiêu đề old'], $titles($c));
        $c->set('period', 'thang-nay')->set('websiteId', (string) $other->id);
        $this->assertSame(['Tiêu đề other'], $titles($c));
        $c->set('websiteId', '');
        $this->assertContains('Tiêu đề now', $titles($c));
        $c->set('period', 'tuy-chon')->set('customFrom', '2026-12-31')->set('customTo', '2026-01-01')->assertSee('Khoảng ngày không hợp lệ');
    }

    public function test_top_articles_sorted_and_link(): void
    {
        $this->version('rẻ', 1, 100.0);
        $dear = $this->version('đắt', 1, 5000.0);
        $rows = $this->as('approver')->instance()->getViewData()['topRows'];
        $this->assertSame(['Tiêu đề đắt', 'Tiêu đề rẻ'], array_map(fn ($r) => $r['title']['text'], $rows));
        $this->assertSame(ArticleVersionResource::getUrl('view', ['record' => $dear->id]), $rows[0]['title']['href']);
    }

    public function test_set_threshold_through_drawer_as_approver_and_admin(): void
    {
        foreach (['approver', 'admin'] as $role) {
            Cache::flush();
            $this->as($role)->callAction('setThreshold', ['article' => 3500, 'month' => 20000])->assertHasNoActionErrors();
            $this->assertSame(['article' => 3500, 'month' => 20000], UsageThresholds::get());
        }
    }

    public function test_set_threshold_rejects_bad_values(): void
    {
        $c = $this->as('approver');
        $c->callAction('setThreshold', ['article' => -1, 'month' => null])->assertHasActionErrors();
        $c->callAction('setThreshold', ['article' => 'abc', 'month' => null])->assertHasActionErrors();
        $this->assertSame(3000, UsageThresholds::get()['article']);
        // tháng < bài: bị từ chối ở lớp validate, không ghi
        $c->callAction('setThreshold', ['article' => 3000, 'month' => 2000]);
        $this->assertSame(['article' => 3000, 'month' => null], UsageThresholds::get());
    }

    public function test_writer_cannot_set_threshold(): void
    {
        $this->actingAs($this->user('writer'));
        Livewire::test(AiUsagePage::class)->assertForbidden();
        $this->assertSame(3000, UsageThresholds::get()['article']);
    }

    public function test_db_strings_are_escaped(): void
    {
        $this->version('x', 1, 100.0, null, ['title' => '<script>alert(1)</script>', 'ai_model' => '<img src=x onerror=1>']);
        $this->as('approver')->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)->assertSee('u003Cscript', false);
    }

    public function test_unrecorded_cost_is_labelled_not_zero(): void
    {
        $this->version('ok', 1, 500.0);
        $this->version('thieu', 1, 0.0, null, ['tokens_used' => 0]);
        $this->as('approver')->assertSee('Phiên bản chưa ghi chi phí')->assertSee('data-ai="summary"', false);
        $this->assertSame(1, $this->as('approver')->instance()->getViewData()['summary']['unrecorded']);
    }

    public function test_no_sample_banner_noise(): void
    {
        AiUsageDemoData::seed();
        $this->as('approver')->assertDontSee('Dữ liệu minh họa')->assertDontSee('minh họa');
    }

    public function test_demo_seed_purge_idempotent_and_guarded(): void
    {
        $real = $this->version('thật', 1, 10.0);
        $n = AiUsageDemoData::seed();
        $this->assertGreaterThanOrEqual(60, $n);
        $this->assertLessThanOrEqual(100, $n);
        $this->assertSame(0, AiUsageDemoData::seed());
        $this->assertSame($n + 1, ArticleVersion::count());
        $this->assertTrue(ArticleVersion::where('tokens_used', 0)->where('cost_vnd', 0)->exists());
        $this->assertGreaterThan(1, ArticleVersion::select('article_id')->groupBy('article_id')->havingRaw('count(*) > 1')->get()->count());
        AiUsageDemoData::purge();
        AiUsageDemoData::purge();
        $this->assertSame(1, ArticleVersion::count());
        $this->assertTrue(ArticleVersion::whereKey($real->id)->exists());
        $this->assertSame(1, Website::count());
        $this->app['env'] = 'production';
        $this->expectException(\LogicException::class);
        AiUsageDemoData::seed();
    }

    public function test_demo_seed_with_sample_data_renders_all_tables(): void
    {
        AiUsageDemoData::seed();
        $this->as('approver')->set('period', '30-ngay')->assertOk()->assertSee('Theo model')->assertSee('Theo prompt')->assertSee('Bài tốn nhất');
    }

    public function test_tabs_single_primary_and_no_lede(): void
    {
        $this->version('k1', 1, 3412.0);
        $html = $this->as('approver')->html();
        foreach (['Tổng quan', 'Theo model', 'Theo prompt', 'Bài tốn nhất', 'Ngưỡng', 'Quy tắc'] as $t) {
            $this->assertStringContainsString($t, $html);
        }
        $this->assertStringNotContainsString('AI đang tốn bao nhiêu', $html);
        $this->assertStringNotContainsString('Luật nghiệp vụ của màn này', $html);
        $this->assertStringContainsString('ss-page', $html);
    }

    public function test_table_rows_and_threshold_badges(): void
    {
        $this->version('rẻ', 1, 100.0);
        $this->version('đắt', 1, 5000.0);
        $d = $this->as('approver')->instance()->getViewData();
        $this->assertSame('danger', $d['topRows'][0]['over']['tone']);
        $this->assertSame('ok', $d['topRows'][1]['over']['tone']);
        $this->assertCount(1, $d['modelRows']);
        $this->assertEquals(5100, $d['modelRows'][0]['cost']);
    }

    public function test_kpi_delta_vs_previous_period(): void
    {
        $now = CarbonImmutable::now('Asia/Ho_Chi_Minh');
        $this->version('moi', 1, 3000.0);
        $this->version('cu', 1, 1000.0, $now->subDays(40));
        $d = $this->as('approver')->set('period', '30-ngay')->instance()->getViewData();
        $this->assertEquals(200.0, $d['deltas']['cost']); // chi phí tăng 200%: giao diện tô đỏ vì good="down"
    }

    public function test_delta_null_without_previous_data(): void
    {
        $this->version('k', 1, 500.0);
        $this->assertNull($this->as('approver')->instance()->getViewData()['deltas']['cost']);
    }
}
