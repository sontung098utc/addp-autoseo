<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\WeeklySchedule;
use App\Filament\Resources\PublicationResource\Pages\ListPublications;
use App\Filament\Resources\PublicationResource\Pages\ViewPublication;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ReschedulePublicationUiTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $t = CarbonImmutable::parse('2026-11-10 03:00:00', 'UTC'); // 10:00 VN thứ Ba
        CarbonImmutable::setTestNow($t);
        \Illuminate\Support\Carbon::setTestNow($t);
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

    private function pub(string $status = 'scheduled', string $atUtc = '2026-11-11 02:30:00', string $kw = null): Publication
    {
        $this->n++;
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => $kw ?? "kw{$this->n}", 'workflow_status' => 'scheduled']);
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

    public function test_table_action_doi_lich_qua_form(): void
    {
        $this->actingAs($this->user('approver'));
        $p = $this->pub();
        Livewire::test(ListPublications::class)
            ->callTableAction('reschedule', $p, ['scheduled_at_vn' => '2026-11-13 08:00:00'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('2026-11-13 01:00:00', $this->raw($p));
    }

    public function test_table_action_gio_qua_khu_bi_tu_choi_khong_doi(): void
    {
        $this->actingAs($this->user('approver'));
        $p = $this->pub();
        Livewire::test(ListPublications::class)->callTableAction('reschedule', $p, ['scheduled_at_vn' => '2026-11-10 10:05:00']);
        $this->assertSame('2026-11-11 02:30:00', $this->raw($p));
    }

    public function test_action_vo_hieu_khi_khong_phai_scheduled_va_hien_khi_scheduled(): void
    {
        $this->actingAs($this->user('approver'));
        $ok = $this->pub();
        $bad = $this->pub('published');
        Livewire::test(ListPublications::class)
            ->assertTableActionEnabled('reschedule', $ok)
            ->assertTableActionDisabled('reschedule', $bad);
        // Gọi trực tiếp action bị vô hiệu cũng không đổi giờ.
        Livewire::test(ListPublications::class)->callTableAction('reschedule', $bad, ['scheduled_at_vn' => '2026-11-13 08:00:00']);
        $this->assertSame('2026-11-11 02:30:00', $this->raw($bad));
    }

    public function test_header_action_trang_xem(): void
    {
        $this->actingAs($this->user('approver'));
        $p = $this->pub();
        Livewire::test(ViewPublication::class, ['record' => $p->getKey()])
            ->callAction('reschedule', ['scheduled_at_vn' => '2026-11-14 09:00:00'])
            ->assertHasNoActionErrors();
        $this->assertSame('2026-11-14 02:00:00', $this->raw($p));
    }

    public function test_weekly_move_to_day_thanh_cong_that_bai(): void
    {
        $this->actingAs($this->user('approver'));
        $p = $this->pub('scheduled', '2026-11-11 02:30:00'); // 09:30 VN 11/11
        Livewire::test(WeeklySchedule::class)->call('moveToDay', $p->id, '2026-11-12');
        $this->assertSame('2026-11-12 02:30:00', $this->raw($p));

        Livewire::test(WeeklySchedule::class)->call('moveToDay', $p->id, '2026-11-09'); // quá khứ
        $this->assertSame('2026-11-12 02:30:00', $this->raw($p));

        $pub = $this->pub('published');
        Livewire::test(WeeklySchedule::class)->call('moveToDay', $pub->id, '2026-11-13');
        $this->assertSame('2026-11-11 02:30:00', $this->raw($pub));
    }

    public function test_writer_khong_vao_duoc_lich_tuan_va_khong_goi_duoc_move(): void
    {
        $this->actingAs($this->user('writer'));
        $p = $this->pub();
        Livewire::test(WeeklySchedule::class)->assertForbidden();
        $this->assertSame('2026-11-11 02:30:00', $this->raw($p));
    }

    public function test_html_draggable_chi_tren_the_hop_le_va_escape(): void
    {
        $this->actingAs($this->user('approver'));
        $ok = $this->pub('scheduled', '2026-11-11 02:30:00', 'ok-kw');
        $this->pub('published', '2026-11-11 03:30:00', 'done-kw');
        $this->pub('scheduled', '2026-11-10 03:05:00', '<script>alert(1)</script>'); // chốt
        $html = Livewire::test(WeeklySchedule::class)->html();

        $this->assertSame(1, substr_count($html, 'draggable="true"'));
        $this->assertStringContainsString('data-publication="'.$ok->id.'"', $html);
        $this->assertStringContainsString('Kéo thẻ', $html);
        $this->assertStringContainsString('không đổi giờ được', $html);   // tooltip R1
        $this->assertStringContainsString('bị chốt', $html);              // tooltip R2
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function test_weekly_nut_doi_lich_mo_modal_va_luu(): void
    {
        $this->actingAs($this->user('approver'));
        $p = $this->pub();
        Livewire::test(WeeklySchedule::class)
            ->callAction('reschedulePublication', ['scheduled_at_vn' => '2026-11-15 07:15:00'], ['publication' => $p->id])
            ->assertHasNoActionErrors();
        $this->assertSame('2026-11-15 00:15:00', $this->raw($p));
    }
}
