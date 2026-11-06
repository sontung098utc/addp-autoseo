<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ContentPlanPage;
use App\Filament\Support\ContentPlan\PlanData;
use App\Filament\Support\Keywords\KeywordService;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class ContentPlanPageTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private User $writer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow('2026-11-06 10:00:00'); // Thứ Sáu 17:00 giờ VN
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->writer = $this->user('writer');
        $repo = KeywordService::make()->repo();
        $repo->saveList([]);
        $repo->saveClusters([['name' => 'Học bổng', 'intent' => 'informational', 'keywords' => ['học bổng a', 'học bổng b', 'học bổng c', 'học bổng d']]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    private function article(string $kw, string $status = 'planned', ?string $cluster = null): Article
    {
        return Article::create(['website_id' => $this->site->id, 'created_by' => $this->writer->id, 'primary_keyword' => $kw, 'workflow_status' => $status, 'topic_cluster' => $cluster]);
    }

    private function page()
    {
        return Livewire::actingAs($this->writer)->test(ContentPlanPage::class)->set('clusterName', 'Học bổng');
    }

    public function test_quyen_truy_cap(): void
    {
        $this->actingAs($this->writer);
        $this->assertTrue(ContentPlanPage::canAccess());
        $this->actingAs($this->user('writer', false));
        $this->assertFalse(ContentPlanPage::canAccess());
        $this->get('/admin/ke-hoach-noi-dung')->assertForbidden();
        auth()->logout();
        $this->assertFalse(ContentPlanPage::canAccess());
        $this->actingAs($this->writer)->get('/admin/ke-hoach-noi-dung')->assertOk()->assertSee('Luật nghiệp vụ của màn này');
    }

    public function test_tao_bai_tu_cum_tao_dung_bai_that_va_chan_trung(): void
    {
        $this->article('Học bổng B', 'drafting');
        $this->page()->callAction('createFromCluster', ['keywords' => ['học bổng a', 'học bổng b'], 'day' => null])
            ->assertHasNoActionErrors()->assertSee('Bị chặn')->assertSee('Đã tạo bài');
        $a = Article::where('primary_keyword', 'học bổng a')->first();
        $this->assertNotNull($a);
        $this->assertSame($this->writer->id, $a->created_by);
        $this->assertSame('planned', $a->workflow_status);
        $this->assertSame('Học bổng', $a->topic_cluster);
        $this->assertSame($this->site->id, $a->website_id);
        $this->assertSame(1, Article::whereRaw('lower(primary_keyword) = ?', ['học bổng b'])->count());
        $this->assertSame(2, Article::count());
    }

    public function test_do_phu_tinh_tu_bai_that(): void
    {
        $this->article('học bổng a');
        $this->article('HỌC BỔNG  B', 'published');
        $this->page()->assertSee('2 / 4 từ khóa có bài')->assertSee('Phủ 50%');
    }

    public function test_canh_bao_an_thit_tu_khoa(): void
    {
        $this->article('học bổng a');
        $this->article('Học bổng A', 'drafting');
        $this->page()->assertSee('ăn thịt từ khóa')->assertSee('1 từ khóa bị trùng bài');
    }

    public function test_gioi_han_3_bai_moi_ngay_khi_tao(): void
    {
        $day = '2026-11-09';
        PlanData::setPlannedDate($this->article('x1')->id, "$day 09:00");
        PlanData::setPlannedDate($this->article('x2')->id, "$day 14:00");
        $this->page()->callAction('createFromCluster', ['keywords' => ['học bổng a', 'học bổng b'], 'day' => $day])->assertNotified('Chưa tạo bài');
        $this->assertSame(2, Article::count());
        $this->page()->callAction('createFromCluster', ['keywords' => ['học bổng a'], 'day' => $day]);
        $a = Article::where('primary_keyword', 'học bổng a')->first();
        $this->assertSame("$day 19:00", PlanData::plannedDates()[$a->id]);
    }

    public function test_doi_ngay_chi_bai_planned_tuong_lai_chua_day(): void
    {
        $p = $this->article('p1');
        $approved = $this->article('p2', 'approved');
        $pub = $this->article('p3', 'published');
        PlanData::setPlannedDate($p->id, '2026-11-09 09:00');
        $t = $this->page();
        $t->callAction('reschedule', ['day' => '2026-11-10'], ['article' => $p->id])->assertNotified('Đã đổi ngày dự kiến');
        $this->assertSame('2026-11-10 09:00', PlanData::plannedDates()[$p->id]);

        $t->callAction('reschedule', ['day' => '2026-11-05'], ['article' => $p->id])->assertHasActionErrors(['day']);
        try {
            (new \App\Filament\Support\ContentPlan\PlanActions)->reschedule($this->writer, $p, '2026-11-05');
            $this->fail('Phải từ chối ngày đã qua');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('đã qua', $e->getMessage());
        }
        $this->assertSame('2026-11-10 09:00', PlanData::plannedDates()[$p->id]);

        foreach ([$approved, $pub] as $a) {
            $t->callAction('reschedule', ['day' => '2026-11-11'], ['article' => $a->id])->assertNotified('Không đổi được ngày');
            $this->assertArrayNotHasKey($a->id, PlanData::plannedDates());
        }

        foreach (['f1', 'f2', 'f3'] as $k) {
            PlanData::setPlannedDate($this->article($k)->id, '2026-11-12 14:00');
        }
        $t->callAction('reschedule', ['day' => '2026-11-12'], ['article' => $p->id])->assertNotified('Không đổi được ngày');
        $this->assertSame('2026-11-10 09:00', PlanData::plannedDates()[$p->id]);
    }

    public function test_lich_tuan_dat_the_dung_ngay_gio_vn_ca_bien_va_chuyen_tuan(): void
    {
        // planned 23:30 VN thứ Bảy 7/11 ; publication 17:30 UTC ngày 7/11 = 00:30 VN Chủ nhật 8/11
        $a = $this->article('late');
        PlanData::setPlannedDate($a->id, '2026-11-07 23:30');
        $b = $this->article('edge', 'scheduled');
        $v = ArticleVersion::create(['article_id' => $b->id, 'version' => 1, 'title' => 'Bài biên', 'slug' => 's', 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
        Publication::create(['website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => 'k1', 'scheduled_at_utc' => '2026-11-07 17:30:00', 'timezone' => 'Asia/Ho_Chi_Minh', 'status' => 'scheduled']);
        $c = $this->article('next', 'planned');
        PlanData::setPlannedDate($c->id, '2026-11-10 09:00');

        $data = $this->page()->instance()->getViewData();
        $byKey = array_column($data['days'], null, 'key');
        $this->assertSame(['late'], array_column($byKey['2026-11-07']['items'], 'keyword'));
        $this->assertSame('23:30', $byKey['2026-11-07']['items'][0]['at']->format('H:i'));
        $this->assertSame(['edge'], array_column($byKey['2026-11-08']['items'], 'keyword'));
        $this->assertSame('00:30', $byKey['2026-11-08']['items'][0]['at']->format('H:i'));
        $this->assertTrue($byKey['2026-11-06']['isToday']);
        $this->assertTrue($byKey['2026-11-05']['isPast']);

        $next = $this->page()->call('nextWeek')->instance()->getViewData();
        $this->assertSame(['next'], array_column(array_column($next['days'], null, 'key')['2026-11-10']['items'], 'keyword'));
        $this->page()->call('nextWeek')->call('previousWeek')->call('currentWeek')->assertSet('weekOffset', 0);
    }

    public function test_chuoi_tu_db_duoc_escape(): void
    {
        $this->article('<script>alert(1)</script>', 'drafting', 'Học bổng');
        $this->page()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_user_inactive_bi_chan_o_may_chu(): void
    {
        $u = $this->user('writer');
        $t = Livewire::actingAs($u)->test(ContentPlanPage::class)->set('clusterName', 'Học bổng');
        $u->is_active = false;
        try {
            $t->callAction('createFromCluster', ['keywords' => ['học bổng a'], 'day' => null]);
        } catch (\Throwable) {
        }
        $this->assertSame(0, Article::count());
    }

    public function test_trang_thai_rong_va_production(): void
    {
        Livewire::actingAs($this->writer)->test(ContentPlanPage::class)->assertSee('Dữ liệu minh họa');
        KeywordService::make()->repo()->saveClusters([]);
        Livewire::actingAs($this->writer)->test(ContentPlanPage::class)->assertSee('Chưa có cụm chủ đề');
        $this->app['env'] = 'production';
        Cache::flush();
        Livewire::actingAs($this->writer)->test(ContentPlanPage::class)->assertSee('Chưa có cụm chủ đề')->assertDontSee('Dữ liệu minh họa')->assertDontSee('học bổng du học');
    }
}
