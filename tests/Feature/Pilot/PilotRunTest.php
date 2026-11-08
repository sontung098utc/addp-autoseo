<?php

namespace Tests\Feature\Pilot;

use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Services\Pilot\PilotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PilotRunTest extends TestCase
{
    use RefreshDatabase;

    protected PilotService $pilotService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pilotService = app(PilotService::class);
    }

    protected function tearDown(): void
    {
        // Dọn dẹp thư mục evidence sinh ra trong test nếu cần
        parent::tearDown();
    }

    public function test_pilot_day1_creates_20_articles_and_all_pass_quality_checks(): void
    {
        $res = $this->pilotService->runDay1();

        $this->assertEquals(1, $res['day']);
        $this->assertEquals(20, $res['articles_created']);
        $this->assertEquals(20, $res['quality_passed']);
        $this->assertEquals(0, $res['quality_failed']);

        // Xác minh trong cơ sở dữ liệu
        $this->assertEquals(20, Article::count());
        $this->assertEquals(20, ArticleVersion::count());

        // 10 bài cho WordPress, 10 bài cho Magento
        $wpSite = $this->pilotService->getPilotWebsites()['wp'];
        $m2Site = $this->pilotService->getPilotWebsites()['magento'];

        $this->assertEquals(10, Article::where('website_id', $wpSite->id)->count());
        $this->assertEquals(10, Article::where('website_id', $m2Site->id)->count());
    }

    public function test_pilot_day2_reviews_approves_and_schedules_20_publications(): void
    {
        $this->pilotService->runDay1();
        $res = $this->pilotService->runDay2();

        $this->assertEquals(2, $res['day']);
        $this->assertEquals(20, $res['submitted']);
        $this->assertEquals(20, $res['approved']);
        $this->assertEquals(20, $res['scheduled']);

        // 20 lượt xuất bản được xếp lịch
        $this->assertEquals(20, Publication::where('status', 'scheduled')->count());

        // Kiểm tra publication_key chuẩn tiền tố
        $wpPubs = Publication::where('publication_key', 'like', 'pilot-wp-%')->count();
        $m2Pubs = Publication::where('publication_key', 'like', 'pilot-m2-%')->count();
        $this->assertEquals(10, $wpPubs);
        $this->assertEquals(10, $m2Pubs);
    }

    public function test_pilot_day3_publishes_10_wp_and_10_magento_posts(): void
    {
        $this->pilotService->runDay1();
        $this->pilotService->runDay2();
        $res = $this->pilotService->runDay3();

        $this->assertEquals(3, $res['day']);
        $this->assertEquals(10, $res['wp_published']);
        $this->assertEquals(10, $res['magento_published']);
        $this->assertEquals(20, $res['total_published']);

        // Tất cả 20 bài đều ở trạng thái published và có remote_id, published_url
        $publishedCount = Publication::where('status', 'published')->whereNotNull('cms_post_id')->count();
        $this->assertEquals(20, $publishedCount);
    }

    public function test_pilot_day4_reconciles_timeout_and_tests_emergency_stop(): void
    {
        $this->pilotService->runDay1();
        $this->pilotService->runDay2();
        $this->pilotService->runDay3();
        $res = $this->pilotService->runDay4();

        $this->assertEquals(4, $res['day']);
        $this->assertTrue($res['reconcile_tested']);
        $this->assertTrue($res['reconcile_success']);
        $this->assertTrue($res['emergency_stop_tested']);
        $this->assertTrue($res['emergency_resume_tested']);

        $summary = $res['cost_summary'];
        $this->assertEquals(20, $summary['total_articles']);
        $this->assertEquals(10, $summary['wordpress_articles']);
        $this->assertEquals(10, $summary['magento_articles']);
        $this->assertEquals(100, $summary['success_rate_percent']);
        $this->assertGreaterThan(0, $summary['total_tokens']);
        $this->assertGreaterThan(0, $summary['total_cost_vnd']);
    }

    public function test_pilot_command_runs_full_simulation_and_generates_documents(): void
    {
        $this->artisan('autoseo:pilot-run', ['--day' => 'all', '--generate-docs' => true])
            ->expectsOutputToContain('KHỞI ĐỘNG CHƯƠNG TRÌNH CHẠY THỬ PILOT V1')
            ->expectsOutputToContain('[NGÀY 1] Khởi tạo 20 bài viết')
            ->expectsOutputToContain('[NGÀY 2] Duyệt bài 3 vai trò')
            ->expectsOutputToContain('[NGÀY 3] Worker tự động xuất bản 20 bài')
            ->expectsOutputToContain('[NGÀY 4] Thử nghiệm ca lỗi mạng')
            ->expectsOutputToContain('HOÀN THÀNH TOÀN DIỆN CHƯƠNG TRÌNH CHẠY THỬ THỰC TẾ PILOT 4.1')
            ->assertSuccessful();

        $evidenceDir = base_path(PilotService::EVIDENCE_DIR);
        $this->assertFileExists("{$evidenceDir}/PILOT_PLAN.md");
        $this->assertFileExists("{$evidenceDir}/day-1-log.md");
        $this->assertFileExists("{$evidenceDir}/day-2-log.md");
        $this->assertFileExists("{$evidenceDir}/day-3-log.md");
        $this->assertFileExists("{$evidenceDir}/day-4-log.md");
        $this->assertFileExists("{$evidenceDir}/PILOT_SUMMARY_REPORT.md");
    }
}
