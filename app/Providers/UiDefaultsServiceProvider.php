<?php

namespace App\Providers;

use Filament\Notifications\Notification;
use Illuminate\Support\ServiceProvider;

/**
 * Mặc định giao diện: thông báo hiện lâu hơn mặc định 6 giây của Filament. Việc chạy đồng bộ lâu (giao đội AI, quét audit, xây kho) kết thúc xong người dùng
 * thường đã rời mắt khỏi màn hình; thông báo biến mất nhanh làm họ không biết việc đã xong hay lỗi.
 */
class UiDefaultsServiceProvider extends ServiceProvider
{
    public const SECONDS = 12;

    public function boot(): void
    {
        Notification::configureUsing(function (Notification $n): void {
            $n->duration(self::SECONDS * 1000);
        });
    }
}
