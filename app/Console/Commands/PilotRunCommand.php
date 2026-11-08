<?php

namespace App\Console\Commands;

use App\Services\Pilot\PilotService;
use Illuminate\Console\Command;

class PilotRunCommand extends Command
{
    /**
     * Tên và chữ ký của lệnh console
     */
    protected $signature = 'autoseo:pilot-run
                            {--day=all : Chọn ngày pilot chạy (1, 2, 3, 4 hoặc all)}
                            {--generate-docs : Xuất các file nhật ký báo cáo Markdown vào docs/evidence/4-1/}';

    /**
     * Mô tả lệnh
     */
    protected $description = 'Chạy thử nghiệm Pilot thực tế 4 ngày với 20 bài viết trên WordPress và Magento 2 (Task 4.1)';

    public function handle(PilotService $pilotService): int
    {
        $day = $this->option('day');
        $this->info("==================================================================");
        $this->info("    KHỞI ĐỘNG CHƯƠNG TRÌNH CHẠY THỬ PILOT V1 (TASK 4.1 - AC13)    ");
        $this->info("==================================================================");

        if ($day === '1' || $day === 'all') {
            $this->warn("\n>>> [NGÀY 1] Khởi tạo 20 bài viết & Kiểm tra 9 quy tắc chất lượng SEO...");
            $res1 = $pilotService->runDay1();
            $this->line(" - Số bài viết tạo mới: {$res1['articles_created']}");
            $this->line(" - Vượt qua kiểm tra chất lượng (0 lỗi P0): {$res1['quality_passed']}");
            $this->info("✓ Hoàn tất kịch bản Ngày 1.");
        }

        if ($day === '2' || $day === 'all') {
            $this->warn("\n>>> [NGÀY 2] Duyệt bài 3 vai trò & Xếp lịch đăng theo khung giờ vàng VN...");
            $res2 = $pilotService->runDay2();
            $this->line(" - Bài gửi duyệt (Submit): {$res2['submitted']}");
            $this->line(" - Bài được duyệt (Approve): {$res2['approved']}");
            $this->line(" - Lượt xuất bản được xếp lịch: {$res2['scheduled']}");
            $this->info("✓ Hoàn tất kịch bản Ngày 2.");
        }

        if ($day === '3' || $day === 'all') {
            $this->warn("\n>>> [NGÀY 3] Worker tự động xuất bản 20 bài (10 WordPress + 10 Magento)...");
            $res3 = $pilotService->runDay3();
            $this->line(" - Xuất bản WordPress: {$res3['wp_published']} bài");
            $this->line(" - Xuất bản Magento 2: {$res3['magento_published']} bài");
            $this->line(" - Tổng số bài xuất bản: {$res3['total_published']} bài");
            $this->info("✓ Hoàn tất kịch bản Ngày 3.");
        }

        if ($day === '4' || $day === 'all') {
            $this->warn("\n>>> [NGÀY 4] Thử nghiệm ca lỗi mạng, đối soát Idempotency & Dừng khẩn cấp...");
            $res4 = $pilotService->runDay4();
            $this->line(" - Thử nghiệm ca 504 Timeout & Đối soát Reconcile: " . ($res4['reconcile_success'] ? 'ĐẠT' : 'CHƯA ĐẠT'));
            $this->line(" - Thử nghiệm Dừng khẩn cấp (Emergency Stop): " . ($res4['emergency_stop_tested'] ? 'ĐẠT' : 'CHƯA ĐẠT'));
            $this->line(" - Thử nghiệm Khôi phục hoạt động: " . ($res4['emergency_resume_tested'] ? 'ĐẠT' : 'CHƯA ĐẠT'));
            $this->line(" - Chi phí trung bình: " . number_format($res4['cost_summary']['avg_cost_per_article_vnd']) . " VNĐ / bài");
            $this->info("✓ Hoàn tất kịch bản Ngày 4.");
        }

        if ($this->option('generate-docs') || $day === 'all') {
            $this->warn("\n>>> Đang xuất tài liệu bằng chứng nghiệm thu ra docs/evidence/4-1/...");
            $files = $pilotService->generateEvidenceDocuments();
            foreach ($files as $file) {
                $this->line(" - Đã tạo: " . str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file));
            }
            $this->info("✓ Đã lưu toàn bộ hồ sơ nghiệm thu kỹ thuật AC13/DOC13.");
        }

        $this->info("\n==================================================================");
        $this->info("    HOÀN THÀNH TOÀN DIỆN CHƯƠNG TRÌNH CHẠY THỬ THỰC TẾ PILOT 4.1    ");
        $this->info("==================================================================");

        return Command::SUCCESS;
    }
}
