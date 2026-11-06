<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Website;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Console\Command;

class EmergencyStopCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'autoseo:emergency-stop
                            {--status : Xem trạng thái dừng khẩn cấp hiện tại}
                            {--resume : Khôi phục hoạt động xuất bản tự động}
                            {--reason= : Lý do kích hoạt dừng khẩn cấp (bắt buộc khi dừng)}
                            {--site= : ID website cụ thể cần dừng/khôi phục (để trống = toàn hệ thống)}
                            {--user= : Email người thực hiện (mặc định lấy admin đầu tiên)}';

    /**
     * The console command description.
     */
    protected $description = 'Kích hoạt, khôi phục hoặc kiểm tra chế độ Dừng khẩn cấp lịch xuất bản tự động';

    public function handle(EmergencyStopService $service): int
    {
        $siteId = $this->option('site') ? (int) $this->option('site') : null;

        // 1. Kiểm tra trạng thái
        if ($this->option('status')) {
            $isStopped = $service->isStopped($siteId);
            $info = $service->getStopInfo($siteId);

            if ($isStopped && $info) {
                $scopeText = $info['scope'] === 'global' ? 'TOÀN HỆ THỐNG' : "Website #{$info['website_id']}";
                $this->warn("=== TRẠNG THÁI: ĐANG DỪNG KHẨN CẤP ({$scopeText}) ===");
                $this->line(" - Thời điểm dừng: {$info['stopped_at']}");
                $this->line(" - Người thực hiện: {$info['stopped_by_name']} (ID: {$info['stopped_by_id']})");
                $this->line(" - Lý do: {$info['reason']}");
            } else {
                $this->info("=== TRẠNG THÁI: BÌNH THƯỜNG (Lịch xuất bản tự động đang hoạt động) ===");
            }
            return Command::SUCCESS;
        }

        // Lấy tài khoản người thực hiện
        $email = $this->option('user');
        $actor = $email
            ? User::where('email', $email)->first()
            : User::whereIn('role', ['admin', 'approver'])->where('is_active', true)->first();

        if (! $actor) {
            $this->error('Không tìm thấy tài khoản quản trị/approver hợp lệ để thực hiện lệnh.');
            return Command::FAILURE;
        }

        // 2. Khôi phục hoạt động
        if ($this->option('resume')) {
            $service->resume($actor, 'Khôi phục qua CLI', $siteId);
            $scopeText = $siteId ? "Website #{$siteId}" : 'toàn hệ thống';
            $this->info("✓ Đã KHÔI PHỤC thành công lịch xuất bản tự động cho {$scopeText}.");
            return Command::SUCCESS;
        }

        // 3. Kích hoạt dừng khẩn cấp
        $reason = $this->option('reason');
        if (! $reason) {
            $reason = $this->ask('Nhập lý do dừng khẩn cấp (bắt buộc)');
        }

        if (empty(trim((string) $reason))) {
            $this->error('Lý do dừng khẩn cấp không được để trống.');
            return Command::FAILURE;
        }

        $info = $service->stop($actor, $reason, $siteId);
        $scopeText = $siteId ? "Website #{$siteId}" : 'toàn hệ thống';
        $this->warn("! Đã KÍCH HOẠT DỪNG KHẨN CẤP lịch xuất bản tự động cho {$scopeText}.");
        $this->line(" - Người thực hiện: {$actor->name} ({$actor->email})");
        $this->line(" - Lý do: {$reason}");

        return Command::SUCCESS;
    }
}
