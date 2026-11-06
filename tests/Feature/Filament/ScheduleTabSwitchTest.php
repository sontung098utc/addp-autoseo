<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PublicationResource\Pages\ListPublications;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Chuyển tab THẬT trên danh sách Lịch đăng (trước đây test chỉ gọi getTabs() nên không bắt được lỗi
 * "newQueryWithoutRelationships() on null" khi bấm tab do closure nhận sai tên tham số).
 */
class ScheduleTabSwitchTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $now = CarbonImmutable::parse('2026-11-06 03:00:00', 'UTC'); // 10:00 ngày 6 giờ VN
        CarbonImmutable::setTestNow($now);
        \Illuminate\Support\Carbon::setTestNow($now);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function pub(string $status, string $atUtc): Publication
    {
        $this->n++;
        $a = Article::create(['website_id' => $this->site->id, 'primary_keyword' => "kw{$this->n}", 'workflow_status' => 'approved']);
        $v = ArticleVersion::create(['article_id' => $a->id, 'version' => 1, 'title' => "t{$this->n}", 'slug' => "s{$this->n}", 'html_content' => '<p>x</p>', 'review_status' => 'approved']);

        return Publication::create([
            'website_id' => $this->site->id, 'article_version_id' => $v->id, 'publication_key' => "k-{$this->n}",
            'scheduled_at_utc' => CarbonImmutable::parse($atUtc, 'UTC'), 'status' => $status,
        ]);
    }

    public function test_bam_tung_tab_khong_loi_va_loc_dung(): void
    {
        $today = $this->pub('scheduled', '2026-11-06 08:00:00');      // 15:00 hôm nay (VN), sắp tới
        $later = $this->pub('scheduled', '2026-11-09 08:00:00');      // sắp tới, không phải hôm nay
        $failed = $this->pub('failed', '2026-11-05 08:00:00');
        $recon = $this->pub('needs_reconcile', '2026-11-04 08:00:00');
        $done = $this->pub('published', '2026-11-03 08:00:00');

        $this->actingAs(User::create(['name' => 'a', 'email' => 'a@x.vn', 'password' => 'secret123', 'role' => 'approver', 'is_active' => true]));

        $expect = [
            'today' => [[$today], [$later, $failed, $recon, $done]],
            'upcoming' => [[$today, $later], [$failed, $recon, $done]],
            'attention' => [[$failed, $recon], [$today, $later, $done]],
            'published' => [[$done], [$today, $later, $failed, $recon]],
            'all' => [[$today, $later, $failed, $recon, $done], []],
        ];

        foreach ($expect as $tab => [$see, $notSee]) {
            Livewire::test(ListPublications::class)
                ->set('activeTab', $tab)
                ->assertSuccessful()
                ->assertCanSeeTableRecords($see)
                ->assertCanNotSeeTableRecords($notSee);
        }
    }
}
