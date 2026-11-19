<?php

namespace App\Services\Agent\Run;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ngắt mạch cho nhà cung cấp AI theo (provider, model): MỞ sau FAILS lỗi tạm thời liên tiếp trong WINDOW giây; khi mở thì không bắt đầu phiên đắt tiền
 * (tìm nguồn, rút mục, tạo ảnh) vì chắc chắn chết ở bước viết. ĐÓNG sau OPEN_SECONDS giây hoặc sau một lần thành công.
 * Trạng thái lưu trong Cache. Cache lỗi (vd thư mục cache không ghi được) thì ngắt mạch bị bỏ qua, không bao giờ ném lỗi và không chặn việc chạy bài.
 */
final class AiCircuitBreaker
{
    public const FAILS = 3;

    public const WINDOW = 600;

    public const OPEN_SECONDS = 300;

    public static function key(?int $providerId, string $model): string
    {
        return 'ai-breaker:'.($providerId ?? 0).':'.sha1($model);
    }

    /** Ghi một lỗi tạm thời; đủ FAILS lần trong WINDOW giây thì mở mạch. */
    public static function failure(string $key): void
    {
        try {
            $now = \Illuminate\Support\Carbon::now()->timestamp;
            $st = (array) Cache::get($key, []);
            $fails = array_values(array_filter((array) ($st['fails'] ?? []), fn ($t) => is_int($t) && $t > $now - self::WINDOW));
            $fails[] = $now;
            $opened = isset($st['opened_at']) && $st['opened_at'] > $now - self::OPEN_SECONDS ? (int) $st['opened_at'] : null;
            if ($opened === null && count($fails) >= self::FAILS) {
                $opened = $now;
            }
            Cache::put($key, ['fails' => $fails, 'opened_at' => $opened], self::WINDOW + self::OPEN_SECONDS);
        } catch (Throwable) {
            // Cache hỏng: bỏ qua ngắt mạch
        }
    }

    /** Một lần gọi thành công: đóng mạch và xóa chuỗi lỗi. */
    public static function success(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
        }
    }

    /** Số giây còn lại mạch mở; 0 = đóng (hoặc không đọc được Cache). */
    public static function openFor(string $key): int
    {
        try {
            $st = (array) Cache::get($key, []);
            $opened = isset($st['opened_at']) ? (int) $st['opened_at'] : 0;
            $left = $opened + self::OPEN_SECONDS - \Illuminate\Support\Carbon::now()->timestamp;
            if ($opened > 0 && $left <= 0) {
                Cache::forget($key); // hết 5 phút: đóng, cho thử lại
            }

            return $opened > 0 ? max(0, $left) : 0;
        } catch (Throwable) {
            return 0;
        }
    }
}
