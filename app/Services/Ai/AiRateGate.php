<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;

/**
 * Cổng giãn cách gọi API theo (nhà cung cấp, model), DÙNG CHUNG giữa các tiến trình qua cache của ứng dụng (Redis/file/database).
 * - Khi nhà cung cấp trả 429/503 kèm gợi ý chờ (Retry-After), mọi tiến trình cùng model đều chờ hết thời gian đó thay vì mỗi nơi tự đập thêm.
 * - Khoảng cách tối thiểu giữa hai lần gọi (config ai_rate.min_interval_seconds, có thể đặt riêng theo model) để không vượt hạn mức theo phút. Mặc định 0 = tắt.
 * Cố ý đơn giản: không khóa nguyên tử, sai số vài trăm ms giữa các tiến trình chấp nhận được; lỗi cache không làm hỏng lần gọi.
 */
final class AiRateGate
{
    /** Hàm ngủ tiêm được (nhận giây dạng float) để test không chờ thật. */
    public static ?\Closure $sleeper = null;

    public static function key(string $kind, string $model): string
    {
        return 'ai-gate:'.$kind.':'.$model;
    }

    private static function nap(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        self::$sleeper ? (self::$sleeper)($seconds) : usleep((int) ($seconds * 1_000_000));
    }

    /** Chờ tới lượt: hết thời gian nguội do 429 và đủ khoảng cách tối thiểu, rồi đặt mốc cho lần gọi kế. */
    public static function wait(string $kind, string $model): void
    {
        try {
            $key = self::key($kind, $model);
            $until = (float) Cache::get($key.':until', 0);
            $min = self::minInterval($model);
            $next = (float) Cache::get($key.':next', 0);
            $now = microtime(true);
            $wait = max($until - $now, $min > 0 ? $next - $now : 0);
            // Trần chờ một lần: không để một gợi ý quá lớn (hoặc cache hỏng) treo tiến trình.
            self::nap(min($wait, 120.0));
            if ($min > 0) {
                Cache::put($key.':next', max(microtime(true), $next) + $min, 300);
            }
        } catch (\Throwable) {
            // cache hỏng: bỏ qua giãn cách
        }
    }

    /** Ghi nhận nhà cung cấp bắt chờ $seconds giây: mọi tiến trình cùng model chờ theo. */
    public static function cooldown(string $kind, string $model, int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        try {
            $key = self::key($kind, $model).':until';
            $until = microtime(true) + min($seconds, 3600);
            if ($until > (float) Cache::get($key, 0)) {
                Cache::put($key, $until, $seconds + 5);
            }
        } catch (\Throwable) {
        }
    }

    public static function minInterval(string $model): float
    {
        $cfg = config('ai_rate.min_interval_seconds', 0);
        $per = (array) config('ai_rate.per_model', []);

        return (float) ($per[$model] ?? $cfg);
    }
}
