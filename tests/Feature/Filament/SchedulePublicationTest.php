<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\WeeklySchedule;
use App\Filament\Resources\PublicationResource;
use App\Filament\Resources\PublicationResource\Pages\ListPublications;
use App\Filament\Resources\PublicationResource\Pages\ViewPublication;
use App\Filament\Support\Demo\ScheduleDemoData;
use App\Filament\Support\Schedule\ReconcileRequest;
use App\Filament\Support\Schedule\VnTime;
use App\Jobs\ReconcilePublicationJob;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\TestCase;

class SchedulePublicationTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function pub(string $status, string $atUtc, ?string $error = null): Publication
    {
        $this->n++;
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => "kw{$this->n}", 'workflow_status' => 'approved']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => "t{$this->n}", 'slug' => "s{$this->n}", 'html_content' => '<p>x</p>', 'review_status' => 'approved']);

        return Publication::create([
            'website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => "k-{$this->n}",
            'scheduled_at_utc' => CarbonImmutable::parse($atUtc, 'UTC'), 'status' => $status, 'last_error' => $error,
        ]);
    }

    private function freeze(string $utc): void
    {
        $t = CarbonImmutable::parse($utc, 'UTC');
        CarbonImmutable::setTestNow($t);
        \Illuminate\Support\Carbon::setTestNow($t);
    }

    public function test_tab_dem_dung_theo_trang_thai_va_gio_vn(): void
    {
        $this->freeze('2026-11-05 17:10:00'); // 00:10 ngày 6 giờ VN
        $this->pub('scheduled', '2026-11-05 16:30:00'); // 23:30 ngày 5 VN -> không phải hôm nay
        $this->pub('scheduled', '2026-11-05 17:30:00'); // 00:30 ngày 6 VN -> hôm nay
        $this->pub('failed', '2026-11-06 01:00:00');
        $this->pub('needs_reconcile', '2026-11-04 01:00:00');
        $this->pub('published', '2026-11-03 01:00:00');
        $this->actingAs($this->user('approver'));

        $tabs = Livewire::test(ListPublications::class)->instance()->getTabs();
        $this->assertSame(2, $tabs['today']->getBadge()); // 00:30 VN ngày 6 + failed 08:00 VN ngày 6; 23:30 VN ngày 5 bị loại
        $this->assertSame(1, $tabs['upcoming']->getBadge()); // chỉ lượt 17:30 UTC còn ở tương lai
        $this->assertSame(2, $tabs['attention']->getBadge());
        $this->assertSame(1, $tabs['published']->getBadge());
        $this->assertSame(5, $tabs['all']->getBadge());
    }

    public function test_doi_soat_noi_that_ro_khi_chua_co_connector(): void
    {
        Bus::fake();
        // CMS chưa có connector (WordPress đã có từ task 1.2; cms_type là enum nên giả lập bằng manager)
        $this->app->instance(\App\Services\Publishing\PublisherManager::class, new class extends \App\Services\Publishing\PublisherManager {
            public function getPublisher(\App\Models\Website $website): \App\Contracts\PublisherInterface
            {
                throw new \InvalidArgumentException('Nền tảng CMS không được hỗ trợ (giả lập: cột cms_type là enum nên không lưu được giá trị lạ).');
            }
        });
        $p = $this->pub('needs_reconcile', '2026-11-05 01:00:00');
        $approver = $this->user('approver');
        $this->actingAs($approver);

        $r = ReconcileRequest::request($p, $approver);

        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('Chưa hỗ trợ đối soát', $r['message']);
        $this->assertStringContainsString('task 1.2', $r['message']);
        Bus::assertNotDispatched(ReconcilePublicationJob::class);
        $this->assertSame('needs_reconcile', $p->fresh()->status); // không đổi trạng thái, không đăng lại
    }

    public function test_doi_soat_chay_voi_needs_reconcile_va_khong_tao_moi(): void
    {
        Bus::fake();
        $this->app->instance(\App\Contracts\PublisherInterface::class, $this->createMock(\App\Contracts\PublisherInterface::class));
        $p = $this->pub('needs_reconcile', '2026-11-05 01:00:00');
        $this->actingAs($this->user('approver'));
        $pubs = Publication::count();
        $arts = Article::count();

        Livewire::test(ViewPublication::class, ['record' => $p->id])
            ->assertActionVisible('reconcile')
            ->callAction('reconcile');

        Bus::assertDispatched(ReconcilePublicationJob::class, fn ($j) => $j->publication->id === $p->id);
        $this->assertSame($pubs, Publication::count());
        $this->assertSame($arts, Article::count());
    }

    public function test_doi_soat_bi_an_va_bi_tu_choi_voi_trang_thai_khac(): void
    {
        Bus::fake();
        $approver = $this->user('approver');
        $this->actingAs($approver);
        foreach (['scheduled', 'publishing', 'published', 'failed'] as $st) {
            $p = $this->pub($st, '2026-11-05 01:00:00');
            Livewire::test(ViewPublication::class, ['record' => $p->id])
                ->assertActionHidden('reconcile')
                ->assertSee('Chỉ lượt ở trạng thái');
            $r = ReconcileRequest::request($p, $approver);
            $this->assertFalse($r['ok']);
        }
        Bus::assertNotDispatched(ReconcilePublicationJob::class);
    }

    public function test_writer_bi_chan(): void
    {
        Bus::fake();
        $writer = $this->user('writer');
        $p = $this->pub('needs_reconcile', '2026-11-05 01:00:00');
        $this->actingAs($writer);

        Livewire::test(ViewPublication::class, ['record' => $p->id])->assertForbidden();
        Livewire::test(ListPublications::class)->assertForbidden();
        $this->assertFalse(ReconcileRequest::request($p, $writer)['ok']);
        Bus::assertNotDispatched(ReconcilePublicationJob::class);
    }

    public function test_last_error_duoc_che_trong_html(): void
    {
        $this->pub('failed', '2026-11-05 01:00:00', 'lỗi https://x.vn/a?access_token=SECRETTOKEN99 Bearer zzTOPSECRET123');
        $p = Publication::first();
        $this->actingAs($this->user('approver'));

        Livewire::test(ListPublications::class)->assertDontSee('SECRETTOKEN99')->assertDontSee('zzTOPSECRET123');
        Livewire::test(ViewPublication::class, ['record' => $p->id])->assertDontSee('SECRETTOKEN99')->assertDontSee('zzTOPSECRET123')->assertSee('lỗi');
    }

    public function test_chi_xem_khong_co_tao_sua_xoa(): void
    {
        $p = $this->pub('scheduled', '2026-11-05 01:00:00');
        $this->actingAs($this->user('admin'));
        $this->assertFalse(PublicationResource::canCreate());
        $this->assertFalse(PublicationResource::canEdit($p));
        $this->assertFalse(PublicationResource::canDelete($p));
        $this->assertSame(['index', 'view'], array_keys(PublicationResource::getPages()));
    }

    public function test_nut_tam_dung_kich_hoat_dung_khan_cap_va_chan_writer(): void
    {
        $approver = $this->user('approver');
        $writer = $this->user('writer');

        // 1. Writer bị cấm vào trang lịch đăng
        $this->actingAs($writer);
        Livewire::test(ListPublications::class)->assertForbidden();

        // 2. Approver nhìn thấy nút và có thể kích hoạt dừng khẩn cấp kèm lý do
        $this->actingAs($approver);
        Livewire::test(ListPublications::class)
            ->assertActionVisible('pause')
            ->callAction('pause', ['reason' => 'Bảo trì hệ thống CMS'])
            ->assertHasNoActionErrors();

        $this->assertTrue(app(\App\Services\Publishing\EmergencyStopService::class)->isStopped());

        // 3. Sau khi dừng, nút chuyển sang Khôi phục
        Livewire::test(ListPublications::class)
            ->assertActionVisible('resume')
            ->callAction('resume')
            ->assertHasNoActionErrors();

        $this->assertFalse(app(\App\Services\Publishing\EmergencyStopService::class)->isStopped());
    }

    public function test_badge_menu(): void
    {
        $this->actingAs($this->user('approver'));
        $this->assertNull(PublicationResource::getNavigationBadge());
        $this->pub('failed', '2026-11-05 01:00:00');
        $this->pub('needs_reconcile', '2026-11-05 01:00:00');
        $this->pub('published', '2026-11-05 01:00:00');
        $this->assertSame('2', PublicationResource::getNavigationBadge());
        $this->assertSame('danger', PublicationResource::getNavigationBadgeColor());
    }

    public function test_lich_tuan_dat_the_dung_ngay_va_chuyen_tuan(): void
    {
        $this->freeze('2026-11-05 03:00:00'); // Thứ Năm 05/11/2026 giờ VN
        $this->pub('scheduled', '2026-11-04 17:30:00'); // 00:30 thứ Năm 05/11 VN
        $this->pub('scheduled', '2026-11-05 16:30:00'); // 23:30 thứ Năm 05/11 VN
        $this->pub('failed', '2026-11-12 03:00:00'); // tuần sau
        $this->actingAs($this->user('approver'));

        $c = Livewire::test(WeeklySchedule::class)
            ->assertSee('02/11/2026')->assertSee('08/11/2026');
        $days = collect($c->instance()->getViewData()['days'] ?? []);
        $thu = $days->firstWhere('key', '2026-11-05');
        $this->assertTrue($thu['isToday']);
        $this->assertCount(2, $thu['items']);
        $this->assertSame(0, $days->firstWhere('key', '2026-11-06')['items']->count());

        $c->call('nextWeek')->assertSee('09/11/2026');
        $this->assertCount(1, collect($c->instance()->getViewData()['days'])->firstWhere('key', '2026-11-12')['items']);
        $c->call('previousWeek')->call('previousWeek')->assertSee('26/10/2026');
    }

    public function test_demo_seed_purge_idempotent_va_bi_chan_ngoai_local(): void
    {
        $this->assertSame(10, ScheduleDemoData::seed());
        ScheduleDemoData::seed();
        $this->assertSame(10, Publication::where('publication_key', 'like', 'demo-%')->count());
        $this->assertEqualsCanonicalizing(['scheduled', 'publishing', 'published', 'failed', 'needs_reconcile'], Publication::pluck('status')->unique()->values()->all());

        $this->pub('scheduled', '2026-11-05 01:00:00'); // không phải demo
        ScheduleDemoData::purge();
        ScheduleDemoData::purge();
        $this->assertSame(0, Publication::where('publication_key', 'like', 'demo-%')->count());
        $this->assertSame(1, Publication::count());
        $this->assertSame(1, Website::count());

        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        ScheduleDemoData::seed();
    }
}
