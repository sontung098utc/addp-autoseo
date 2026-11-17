<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Hàng đợi `database` cần bảng `jobs`/`failed_jobs` (trước đây thiếu nên server báo "Table 'jobs' doesn't exist" và lượt đăng kẹt ở `publishing`) và có tiến trình xử lý dưới cron. */
class QueueTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_tao_bang_jobs_va_failed_jobs_va_chay_lai_an_toan(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertTrue(Schema::hasColumns('jobs', ['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']));
        $this->assertTrue(Schema::hasColumns('failed_jobs', ['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']));
        $m = require database_path('migrations/2026_11_17_090000_create_queue_tables.php');
        $m->up(); // chạy lại khi bảng đã có: không lỗi
        $this->assertTrue(Schema::hasTable('jobs'));
    }

    public function test_hang_doi_database_gui_duoc_job_khong_loi_thieu_bang(): void
    {
        config(['queue.default' => 'database']);
        \Illuminate\Support\Facades\Queue::push(function () {
        });
        $this->assertSame(1, \DB::table('jobs')->count());
    }

    private function workerEvent(): ?\Illuminate\Console\Scheduling\Event
    {
        foreach (app(Schedule::class)->events() as $e) {
            if (str_contains((string) $e->command, 'queue:work')) {
                return $e;
            }
        }

        return null;
    }

    public function test_lich_xu_ly_hang_doi_chi_chay_khi_khong_phai_sync(): void
    {
        $e = $this->workerEvent();
        $this->assertNotNull($e, 'Phải có lịch queue:work dưới schedule:run.');
        $this->assertStringContainsString('--stop-when-empty', (string) $e->command);
        $this->assertStringContainsString('--max-time=50', (string) $e->command);
        config(['queue.default' => 'sync']);
        $this->assertFalse($e->filtersPass($this->app), 'Hàng đợi sync chạy job ngay nên không cần tiến trình xử lý.');
        config(['queue.default' => 'database']);
        $this->assertTrue($e->filtersPass($this->app));
        $this->assertSame('* * * * *', $e->expression);
    }
}
