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
        $this->as('approver')->assertSee('3.600 ₫')->assertSee('1.800 ₫/bài')->assertSee('MỌI phiên bản')
            ->assertDontSee('▲ Trung bình');
    }

    public function test_avg_over_threshold_banner_and_boundary(): void
    {
        $this->version('k1', 1, 3412.0);
        $this->as('approver')->assertSee('Trung bình 3.412 ₫/bài, vượt ngưỡng 3.000 ₫/bài', false)->assertSee('▲ Vượt ngưỡng');
        $this->version('k2', 1, 2588.0); // TB = 3000 -> đúng bằng ngưỡng: không vượt
        $this->as('approver')->assertDontSee('vượt ngưỡng 3.000 ₫/bài', false);
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
        $c = $this->as('approver')->assertSee('Tiêu đề now')->assertSee('Tiêu đề other')->assertDontSee('Tiêu đề old');
        $c->set('period', 'thang-truoc')->assertSee('Tiêu đề old')->assertDontSee('Tiêu đề now');
        $c->set('period', 'thang-nay')->set('websiteId', (string) $other->id)->assertSee('Tiêu đề other')->assertDontSee('Tiêu đề now');
        $c->set('websiteId', '')->assertSee('Tiêu đề now');
        $c->set('period', 'tuy-chon')->set('customFrom', '2026-12-31')->set('customTo', '2026-01-01')->assertSee('Khoảng ngày không hợp lệ');
    }

    public function test_top_articles_sorted_and_link(): void
    {
        $this->version('rẻ', 1, 100.0);
        $dear = $this->version('đắt', 1, 5000.0);
        $c = $this->as('approver');
        $c->assertSeeInOrder(['Tiêu đề đắt', 'Tiêu đề rẻ']);
        $c->assertSee(ArticleVersionResource::getUrl('view', ['record' => $dear->id]), false);
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
        $this->as('approver')->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_unrecorded_cost_is_labelled_not_zero(): void
    {
        $this->version('ok', 1, 500.0);
        $this->version('thieu', 1, 0.0, null, ['tokens_used' => 0]);
        $this->as('approver')->assertSee('1 phiên bản chưa ghi chi phí');
    }

    public function test_sample_banner_only_with_sample_rows(): void
    {
        $this->as('approver')->assertDontSee('Dữ liệu minh họa');
        AiUsageDemoData::seed();
        $this->as('approver')->assertSee('Dữ liệu minh họa');
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
        $this->as('approver')->set('period', '30-ngay')->assertOk()->assertSee('Theo model')->assertSee('Theo phiên bản prompt')->assertSee('Bài tốn nhất');
    }
}
