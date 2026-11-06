<?php

namespace Tests\Unit;

use App\Filament\Support\Audit\AuditRecorder;
use App\Filament\Support\Audit\AuditSink;
use App\Filament\Support\Schedule\ReschedulePublication;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ReschedulePublicationTest extends TestCase
{
    use RefreshDatabase;

    private object $sink;

    private ReschedulePublication $svc;

    private Website $site;

    private User $admin;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeze('2026-11-10 03:00:00'); // 10:00 VN
        $this->sink = new class implements AuditSink {
            public array $entries = [];
            public bool $fail = false;

            public function write(array $entry): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('log lỗi');
                }
                $this->entries[] = $entry;
            }
        };
        $this->svc = new ReschedulePublication(new AuditRecorder($this->sink));
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $this->admin = $this->user('admin');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function freeze(string $utc): void
    {
        $t = CarbonImmutable::parse($utc, 'UTC');
        CarbonImmutable::setTestNow($t);
        \Illuminate\Support\Carbon::setTestNow($t);
    }

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    private function pub(string $status = 'scheduled', string $atUtc = '2026-11-11 02:30:00'): Publication
    {
        $this->n++;
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => "kw{$this->n}", 'workflow_status' => 'scheduled']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => "t{$this->n}", 'slug' => "s{$this->n}", 'html_content' => '<p>x</p>', 'review_status' => 'approved']);

        return Publication::create([
            'website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => "k-{$this->n}",
            'scheduled_at_utc' => CarbonImmutable::parse($atUtc, 'UTC'), 'timezone' => 'Asia/Ho_Chi_Minh', 'status' => $status,
        ]);
    }

    private function raw(Publication $p): string
    {
        return (string) DB::table('publications')->where('id', $p->id)->value('scheduled_at_utc');
    }

    private function vn(string $s): CarbonImmutable
    {
        return CarbonImmutable::parse($s, 'Asia/Ho_Chi_Minh');
    }

    public function test_r1_chi_scheduled_moi_doi_duoc(): void
    {
        foreach (['publishing', 'published', 'failed', 'needs_reconcile'] as $st) {
            $p = $this->pub($st);
            $this->assertNotNull(ReschedulePublication::reasonUnavailable($this->admin, $p), $st);
            try {
                $this->svc->reschedule($this->admin, $p, $this->vn('2026-11-12 09:00'));
                $this->fail("$st phải bị từ chối");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Đã xếp lịch', $e->getMessage());
            }
            $this->assertSame('2026-11-11 02:30:00', $this->raw($p));
        }
        $this->assertNull(ReschedulePublication::reasonUnavailable($this->admin, $this->pub()));
        $this->assertSame([], $this->sink->entries);
    }

    public function test_r2_bien_9_10_11_phut_cho_gio_moi(): void
    {
        $p = $this->pub();
        foreach (['2026-11-10 10:09' => false, '2026-11-10 10:10' => true, '2026-11-10 10:11' => true] as $t => $ok) {
            $p = $this->pub();
            if ($ok) {
                $this->svc->reschedule($this->admin, $p, $this->vn($t));
                $this->assertSame($this->vn($t)->utc()->format('Y-m-d H:i:s'), $this->raw($p));
            } else {
                try {
                    $this->svc->reschedule($this->admin, $p, $this->vn($t));
                    $this->fail('9 phút phải bị từ chối');
                } catch (InvalidArgumentException) {
                    $this->assertSame('2026-11-11 02:30:00', $this->raw($p));
                }
            }
        }
    }

    public function test_r2_luot_hien_tai_trong_10_phut_bi_chot(): void
    {
        $this->assertNotNull(ReschedulePublication::reasonUnavailable($this->admin, $this->pub('scheduled', '2026-11-10 03:09:00')));
        $this->assertNull(ReschedulePublication::reasonUnavailable($this->admin, $this->pub('scheduled', '2026-11-10 03:10:00')));
        $this->assertNull(ReschedulePublication::reasonUnavailable($this->admin, $this->pub('scheduled', '2026-11-10 03:11:00')));
        $this->assertNotNull(ReschedulePublication::reasonUnavailable($this->admin, $this->pub('scheduled', '2026-11-10 01:00:00')));
        $p = $this->pub('scheduled', '2026-11-10 03:05:00');
        $this->expectException(InvalidArgumentException::class);
        $this->svc->reschedule($this->admin, $p, $this->vn('2026-11-12 09:00'));
    }

    public function test_r3_writer_va_inactive_bi_chan_o_server(): void
    {
        foreach ([$this->user('writer'), $this->user('admin', false)] as $u) {
            $p = $this->pub();
            $this->assertNotNull(ReschedulePublication::reasonUnavailable($u, $p));
            try {
                $this->svc->reschedule($u, $p, $this->vn('2026-11-12 09:00'));
                $this->fail('phải bị chặn');
            } catch (InvalidArgumentException) {
            }
            $this->assertSame('2026-11-11 02:30:00', $this->raw($p));
        }
        $this->assertNotNull(ReschedulePublication::reasonUnavailable(null, $this->pub()));
        $this->assertSame([], $this->sink->entries);
    }

    public function test_r4_dua_voi_job_status_doi_giua_chung(): void
    {
        $svc = new ReschedulePublication(new AuditRecorder($this->sink), function (Publication $p) {
            DB::table('publications')->where('id', $p->id)->update(['status' => 'publishing']);
        });
        $p = $this->pub();
        try {
            $svc->reschedule($this->admin, $p, $this->vn('2026-11-12 09:00'));
            $this->fail('phải bị từ chối');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('tải lại trang', $e->getMessage());
        }
        $this->assertSame('2026-11-11 02:30:00', $this->raw($p));
        $this->assertSame([], $this->sink->entries);
    }

    public function test_r5_r6_r7_doi_thanh_cong_chi_doi_gio_va_ghi_dung_1_log(): void
    {
        $p = $this->pub();
        $before = Publication::count();
        $key = $p->publication_key;
        $r = $this->svc->reschedule($this->admin, $p, $this->vn('2026-11-12 09:30'));

        $this->assertSame('2026-11-12 02:30:00', $this->raw($p)); // 09:30 VN = 02:30 UTC
        $this->assertSame($key, $r->publication_key);
        $this->assertSame($p->website_id, $r->website_id);
        $this->assertSame($p->article_version_id, $r->article_version_id);
        $this->assertSame('Asia/Ho_Chi_Minh', $r->timezone);
        $this->assertSame('scheduled', $r->status);
        $this->assertSame($before, Publication::count());
        $this->assertSame('scheduled', $p->articleVersion->article->fresh()->workflow_status);

        $this->assertCount(1, $this->sink->entries);
        $e = $this->sink->entries[0];
        $this->assertSame('reschedule', $e['action']);
        $this->assertSame($p->id, $e['extra']['publication_id']);
        $this->assertSame($key, $e['extra']['publication_key']);
        $this->assertSame('2026-11-11T02:30:00+00:00', $e['extra']['from_utc']);
        $this->assertSame('2026-11-12T02:30:00+00:00', $e['extra']['to_utc']);
    }

    public function test_r6_log_loi_thi_rollback(): void
    {
        $p = $this->pub();
        $this->sink->fail = true;
        try {
            $this->svc->reschedule($this->admin, $p, $this->vn('2026-11-12 09:30'));
            $this->fail('phải ném lỗi');
        } catch (\RuntimeException) {
        }
        $this->assertSame('2026-11-11 02:30:00', $this->raw($p));
    }

    public function test_r8_move_to_day_giu_gio_phut_vn_va_bien(): void
    {
        $p = $this->pub('scheduled', '2026-11-11 16:30:00'); // 23:30 VN ngày 11
        $this->svc->moveToDay($this->admin, $p, '2026-11-13');
        $this->assertSame('2026-11-13 16:30:00', $this->raw($p)); // vẫn 23:30 VN ngày 13

        $q = $this->pub('scheduled', '2026-11-11 17:30:00'); // 00:30 VN ngày 12
        $this->svc->moveToDay($this->admin, $q, '2026-11-14');
        $this->assertSame('2026-11-13 17:30:00', $this->raw($q)); // 00:30 VN ngày 14
    }

    public function test_r8_ngay_qua_khu_hoac_sai_dinh_dang_bi_tu_choi(): void
    {
        $p = $this->pub('scheduled', '2026-11-11 02:30:00');
        foreach (['2026-11-09', '2026-11-10', 'abc', '2026-02-31'] as $d) { // 10/11 09:30 VN < 10:10
            try {
                $this->svc->moveToDay($this->admin, $p, $d);
                $this->fail("$d phải bị từ chối");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame('2026-11-11 02:30:00', $this->raw($p));
        $this->assertSame([], $this->sink->entries);
    }

    public function test_r9_canh_bao_website_khong_active_hoac_thieu_connector(): void
    {
        $p = $this->pub();
        $this->site->update(['status' => 'revoked']);
        $this->assertStringContainsString('đến giờ lượt đăng sẽ báo Lỗi', ReschedulePublication::warning($p->fresh('website')));
        $this->site->update(['status' => 'active']);
        // Website active: cảnh báo chỉ còn khi chưa đăng ký connector (kết quả phụ thuộc PublisherManager, không gọi mạng).
        $w = ReschedulePublication::warning($p->fresh('website'));
        $this->assertTrue($w === null || str_contains($w, 'connector'));
        // Cảnh báo không chặn.
        $this->assertNull(ReschedulePublication::reasonUnavailable($this->admin, $p));
    }
}
