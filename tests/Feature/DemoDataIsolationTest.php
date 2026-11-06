<?php

namespace Tests\Feature;

use App\Filament\Support\Demo\AiUsageDemoData;
use App\Filament\Support\Demo\DashboardDemoData;
use App\Filament\Support\Demo\QualityDemoData;
use App\Filament\Support\Demo\ScheduleDemoData;
use App\Models\Article;
use App\Models\Publication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Các lớp dữ liệu mẫu của nhiều màn phải độc lập: nạp theo thứ tự bất kỳ đều ra đủ dữ liệu của từng màn,
 * và xóa mẫu của một màn không được xóa dữ liệu mẫu của màn khác.
 */
class DemoDataIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function seedAll(): void
    {
        QualityDemoData::seed();
        DashboardDemoData::seed();
        ScheduleDemoData::seed();
        AiUsageDemoData::seed();
    }

    public function test_nap_du_cac_lop_mau_va_xoa_mot_lop_khong_anh_huong_lop_khac(): void
    {
        $this->seedAll();

        $quality = Article::where('primary_keyword', 'like', '[Mẫu] %')->count();
        $dash = Article::where('primary_keyword', 'like', '[Mẫu TQ] %')->count();
        $ai = Article::where('primary_keyword', 'like', '[Mẫu AI] %')->count();
        $sched = Publication::where('publication_key', 'like', 'demo-0%')->count();
        $dashPubs = Publication::where('publication_key', 'like', 'demo-tq-%')->count();

        $this->assertGreaterThan(0, $quality, 'Chất lượng có mẫu');
        $this->assertGreaterThan(0, $dash, 'Tổng quan có mẫu');
        $this->assertGreaterThan(0, $ai, 'Chi phí AI có mẫu');
        $this->assertGreaterThan(0, $sched, 'Lịch đăng có mẫu');
        $this->assertGreaterThan(0, $dashPubs, 'Tổng quan có lượt đăng mẫu');

        DashboardDemoData::purge();

        $this->assertSame(0, Article::where('primary_keyword', 'like', '[Mẫu TQ] %')->count());
        $this->assertSame(0, Publication::where('publication_key', 'like', 'demo-tq-%')->count());
        $this->assertSame($quality, Article::where('primary_keyword', 'like', '[Mẫu] %')->count(), 'purge Tổng quan không được xóa mẫu Chất lượng');
        $this->assertSame($ai, Article::where('primary_keyword', 'like', '[Mẫu AI] %')->count(), 'purge Tổng quan không được xóa mẫu Chi phí AI');
        $this->assertSame($sched, Publication::where('publication_key', 'like', 'demo-0%')->count(), 'purge Tổng quan không được xóa mẫu Lịch đăng');
    }
}
