<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Support\Dashboard\DashboardQueries;
use App\Filament\Support\Demo\DashboardDemoData;
use App\Filament\Widgets\DecisionsWidget;
use App\Filament\Widgets\NextPublicationsWidget;
use App\Filament\Widgets\PublicationProblemsWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-06 10:00:00');
        $this->site = Website::create(['name' => 'Site A', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid() . '@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function version(string $kw, string $review, ?User $by = null, $createdAt = null, array $x = []): ArticleVersion
    {
        $a = Article::create(['website_id' => $this->site->id, 'created_by' => $by?->id, 'primary_keyword' => $kw, 'workflow_status' => $review]);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => $kw, 'slug' => uniqid(), 'html_content' => '<p>x</p>', 'review_status' => $review] + $x);
        $v->forceFill(['created_at' => $createdAt ?? now()])->save();

        return $v;
    }

    public function test_bien_qua_han_dung_24h_chua_qua_han_24h01_qua_han(): void
    {
        $ap = $this->user('approver');
        $this->version('dung24', 'needs_review', null, now()->subHours(24));
        $this->version('qua24h01', 'needs_review', null, now()->subHours(24)->subMinute());
        $rows = DashboardQueries::decisions($ap, now())->keyBy('keyword');
        $this->assertFalse($rows['dung24']['overdue']);
        $this->assertTrue($rows['qua24h01']['overdue']);
        $this->assertSame('qua24h01', $rows->keys()->first()); // quá hạn trước
    }

    public function test_sap_xep_qua_han_truoc_roi_lau_nhat(): void
    {
        $ap = $this->user('approver');
        $this->version('moi', 'needs_review', null, now()->subHour());
        $this->version('cu', 'needs_review', null, now()->subHours(10));
        $this->version('qua', 'needs_review', null, now()->subHours(30));
        $this->assertSame(['qua', 'cu', 'moi'], DashboardQueries::decisions($ap, now())->pluck('keyword')->all());
    }

    public function test_writer_chi_thay_bai_cua_minh_va_khong_thay_khoi_loi(): void
    {
        $w = $this->user('writer');
        $other = $this->user('writer');
        $this->version('cua-toi', 'rejected', $w, null, ['review_notes' => 'Sửa đi']);
        $this->version('cua-nguoi-khac', 'rejected', $other);
        $this->version('cho-duyet', 'needs_review', $w);
        $rows = DashboardQueries::decisions($w, now());
        $this->assertSame(['cua-toi'], $rows->pluck('keyword')->all());
        $this->assertSame('Sửa đi', $rows->first()['note']);

        $v = $this->version('x', 'approved');
        Publication::create(['website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => 'k1', 'status' => 'failed', 'scheduled_at_utc' => '2026-11-07 00:00:00']);
        $this->assertCount(0, DashboardQueries::publicationProblems($w));
        $this->assertCount(1, DashboardQueries::publicationProblems($this->user('approver')));
    }

    public function test_tieu_de_khop_tong_that_va_rong(): void
    {
        $ap = $this->user('approver');
        $this->assertSame('Không có việc nào cần bạn quyết', DashboardQueries::heading($ap, now()));
        $v = $this->version('a', 'needs_review');
        Publication::create(['website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => 'k2', 'status' => 'needs_reconcile', 'scheduled_at_utc' => '2026-11-07 00:00:00']);
        $this->assertSame('Hôm nay có 2 việc cần bạn quyết', DashboardQueries::heading($ap, now()));
    }

    public function test_last_error_duoc_che_token(): void
    {
        $out = DashboardQueries::safeError('Lỗi 401 Authorization: Bearer abcdef1234567890abcdef1234567890 token=sk_live_abcdef1234567890');
        $this->assertStringNotContainsString('abcdef1234567890', $out);
        $this->assertStringContainsString('Lỗi 401', $out);
    }

    public function test_gio_dang_ke_tiep_vn_va_chua_co_lich(): void
    {
        $other = Website::create(['name' => 'Site B', 'cms_type' => 'wordpress', 'base_url' => 'https://b.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $v = $this->version('a', 'approved');
        Publication::create(['website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => 'k3', 'status' => 'scheduled', 'scheduled_at_utc' => '2026-11-06 12:00:00']);
        $rows = DashboardQueries::nextPublications(now())->keyBy('website');
        $this->assertSame('06/11/2026 19:00', $rows['Site A']['vn']);
        $this->assertSame('06/11/2026 12:00', $rows['Site A']['utc']);
        $this->assertNull($rows['Site B']['vn']);
    }

    public function test_db_trong_khong_loi_va_render_theo_vai_tro(): void
    {
        Website::query()->delete();
        foreach (['admin', 'approver', 'writer'] as $role) {
            $this->actingAs($this->user($role));
            Livewire::test(Dashboard::class)->assertOk();
            foreach ([DecisionsWidget::class, PublicationProblemsWidget::class, NextPublicationsWidget::class, RecentActivityWidget::class] as $w) {
                Livewire::test($w)->assertOk();
            }
        }
    }

    public function test_trang_render_noi_dung_va_che_loi(): void
    {
        $this->actingAs($this->user('approver'));
        $v = $this->version('tu-khoa-x', 'needs_review', null, now()->subHours(30));
        Publication::create(['website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => 'k4', 'status' => 'failed', 'scheduled_at_utc' => '2026-11-07 00:00:00', 'last_error' => 'token=sk_live_abcdef1234567890']);
        Livewire::test(DecisionsWidget::class)->assertSee('tu-khoa-x')->assertSee('Quá hạn');
        Livewire::test(PublicationProblemsWidget::class)->assertSee('không đăng lại')->assertDontSee('abcdef1234567890');
    }

    public function test_demo_seed_purge_idempotent_va_bi_chan(): void
    {
        $this->user('writer')->update(['email' => 'writer@company.local']);
        DashboardDemoData::seed();
        DashboardDemoData::seed();
        $this->assertSame(5, Article::where('primary_keyword', 'like', '[Mẫu TQ] %')->count());
        $this->assertSame(3, Publication::where('publication_key', 'like', 'demo-tq-%')->count());
        DashboardDemoData::purge();
        $this->assertSame(0, Article::where('primary_keyword', 'like', '[Mẫu TQ] %')->count());
        $this->assertSame(0, Publication::count());

        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        DashboardDemoData::seed();
    }
}
