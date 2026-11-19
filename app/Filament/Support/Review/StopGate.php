<?php

namespace App\Filament\Support\Review;

use App\Services\Publishing\EmergencyStopService;

/**
 * Khóa NÚT khi đang dừng khẩn cấp (chỉ thêm cảnh báo ở giao diện). Kiểm tra thật vẫn nằm ở máy chủ
 * (orchestrator, PublishArticleJob, ReviewWorkflow): không có gì ở đây thay thế hoặc làm lỏng chúng.
 */
final class StopGate
{
    public const MESSAGE = 'Đang dừng khẩn cấp: không giao việc/đăng được. Mở Lịch xuất bản để khôi phục.';

    public static function stopped(?int $websiteId): bool
    {
        return app(EmergencyStopService::class)->isStopped($websiteId);
    }

    public static function notice(?int $websiteId): ?string
    {
        return self::stopped($websiteId) ? self::MESSAGE : null;
    }

    /** Khóa nút: tắt + tooltip đỏ khi đang dừng khẩn cấp. Chỉ dùng cho nút chưa có disabled riêng. */
    public static function lock(\Filament\Actions\MountableAction $action): \Filament\Actions\MountableAction
    {
        $stopped = fn ($action): bool => self::stopped($action->getRecord()?->website_id);

        return $action
            ->disabled($stopped)
            ->tooltip(fn ($action): ?string => $stopped($action) ? self::MESSAGE : null);
    }

    /**
     * Chốt chặn phía máy chủ cho lối bấm tay: đang dừng khẩn cấp thì KHÔNG tạo phiên/lượt đăng nào, chỉ báo và ghi nhật ký hệ thống.
     * @return bool true nếu bị chặn
     */
    public static function blocks(?int $websiteId, string $what): bool
    {
        if (! self::stopped($websiteId)) {
            return false;
        }
        \Illuminate\Support\Facades\Log::warning('[EmergencyStop] Chặn thao tác tay khi đang dừng khẩn cấp: '.$what, ['website_id' => $websiteId, 'user_id' => auth()->id()]);
        \Filament\Notifications\Notification::make()->title('Đang dừng khẩn cấp')->body(self::MESSAGE)->danger()->send();

        return true;
    }
}
