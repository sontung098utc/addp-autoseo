<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PublicationResource\Pages\ListPublications;
use App\Filament\Support\Review\ReviewActions;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Filament\Support\Schedule\VnTime;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Jobs\PublishArticleJob;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Giờ xếp lịch phải đi đúng vòng: nhập giờ VN -> lưu UTC -> hiển thị giờ VN -> đến giờ thì đăng, bất kể app.timezone. */
class ScheduleTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private string $originalTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        date_default_timezone_set($this->originalTz);
        parent::tearDown();
    }

    private function useAppTz(string $tz): void
    {
        config(['app.timezone' => $tz]);
        date_default_timezone_set($tz);
    }

    private function freeze(string $utc): void
    {
        $t = CarbonImmutable::parse($utc, 'UTC');
        CarbonImmutable::setTestNow($t);
        Carbon::setTestNow($t);
    }

    private function approvedVersion(Website $site): ArticleVersion
    {
        static $n = 0;
        $n++;
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => "kw{$n}", 'workflow_status' => 'approved']);

        return ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => "t{$n}", 'slug' => "s{$n}", 'html_content' => '<p>x</p>', 'review_status' => 'approved']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> [múi giờ app, giờ VN nhập, UTC đúng] */
    public static function cases(): array
    {
        $out = [];
        foreach (['UTC', 'Asia/Ho_Chi_Minh'] as $tz) {
            foreach ([
                '00:05' => '2026-11-17 17:05:00', '08:00' => '2026-11-18 01:00:00', '16:59' => '2026-11-18 09:59:00',
                '17:00' => '2026-11-18 10:00:00', '17:01' => '2026-11-18 10:01:00', '23:59' => '2026-11-18 16:59:00',
            ] as $vn => $utc) {
                $out["$tz $vn"] = [$tz, $vn, $utc];
            }
        }

        return $out;
    }

    /** @dataProvider cases */
    public function test_xep_lich_luu_dung_utc_hien_dung_ngay_gio_vn_va_dang_dung_gio(string $tz, string $vn, string $utc): void
    {
        $this->useAppTz($tz);
        $this->freeze('2026-11-17 16:30:00'); // 23:30 ngày 17 giờ VN: mọi giờ 18/11 (kể cả 00:05) đều ở tương lai
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $approver = User::create(['name' => 'ap', 'email' => 'ap@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]);
        $v = $this->approvedVersion($site);

        // Đúng như hộp "Xếp lịch": chuỗi do DateTimePicker trả về, qua parseVn rồi ReviewWorkflow::schedule.
        $day = '2026-11-18';
        $at = ReviewActions::parseVn("$day $vn:00");
        $expectUtc = CarbonImmutable::parse("$day $vn:00", 'Asia/Ho_Chi_Minh')->utc()->format('Y-m-d H:i:s');
        $p = (new ReviewWorkflow)->schedule($approver, $v, $at, $site);

        $this->assertSame($expectUtc, DB::table('publications')->where('id', $p->id)->value('scheduled_at_utc'), 'DB phải lưu UTC');
        $this->assertSame("$day $vn", VnTime::format(VnTime::fromRaw($p->fresh(), 'scheduled_at_utc'), 'Y-m-d H:i'));

        // Lịch tuần: đúng ngày VN.
        $this->actingAs($approver);
        $days = collect(Livewire::test(ListPublications::class)->instance()->weeklyBoardData()['days']);
        $this->assertCount(1, $days->firstWhere('key', $day)['items'], 'Lịch tuần phải xếp đúng ngày VN');

        // Lệnh đăng đến hạn: chưa đăng trước giờ, đăng khi tới giờ.
        Bus::fake();
        $due = CarbonImmutable::parse($expectUtc, 'UTC');
        $this->freeze($due->subMinute()->format('Y-m-d H:i:s'));
        Artisan::call('autoseo:publish-due-posts');
        $this->assertSame('scheduled', $p->fresh()->status, 'Chưa tới giờ thì không được đăng');
        $this->freeze($due->addSeconds(5)->format('Y-m-d H:i:s'));
        Artisan::call('autoseo:publish-due-posts');
        $this->assertSame('publishing', $p->fresh()->status, 'Tới giờ phải đăng');
    }

    /** @dataProvider tzs */
    public function test_cot_utc_ghi_va_doc_dung_utc_bat_ke_app_timezone(string $tz): void
    {
        $this->useAppTz($tz);
        $this->freeze('2026-11-18 10:12:00'); // 17:12 giờ VN
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $v = $this->approvedVersion($site);
        $p = Publication::create(['website_id' => $site->id, 'article_version_id' => $v->id, 'publication_key' => 'k1', 'status' => 'published', 'scheduled_at_utc' => now(), 'published_at_utc' => now()]);

        $raw = DB::table('publications')->where('id', $p->id)->first();
        $this->assertSame('2026-11-18 10:12:00', $raw->scheduled_at_utc);
        $this->assertSame('2026-11-18 10:12:00', $raw->published_at_utc, 'now() không được ghi giờ VN vào cột UTC');
        $p = $p->fresh();
        $this->assertSame('2026-11-18 17:12', VnTime::format($p->scheduled_at_utc, 'Y-m-d H:i'));
        $this->assertSame('2026-11-18 17:12', VnTime::format($p->published_at_utc, 'Y-m-d H:i'));
        $this->assertSame('2026-11-18 17:12', VnTime::format(VnTime::fromRaw($p, 'published_at_utc'), 'Y-m-d H:i'));
    }

    /** @return array<string, array{0: string}> */
    public static function tzs(): array
    {
        return ['UTC' => ['UTC'], 'Asia/Ho_Chi_Minh' => ['Asia/Ho_Chi_Minh']];
    }
}
