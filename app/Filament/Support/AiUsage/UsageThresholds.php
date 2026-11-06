<?php

namespace App\Filament\Support\AiUsage;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Ngưỡng cảnh báo do quản lý đặt. LƯU TẠM bằng cache vĩnh viễn; sẽ chuyển sang bảng cấu hình khi Dev1 thêm.
 * Mặc định theo bài = 3.000 ₫ (báo cáo PoC 0.3); theo tháng để trống = không cảnh báo.
 */
final class UsageThresholds
{
    public const KEY = 'studio.ai_usage.thresholds';

    public const DEFAULT_PER_ARTICLE = 3000;

    public const MAX_PER_ARTICLE = 10_000_000;

    public const MAX_PER_MONTH = 1_000_000_000;

    /** @return array{article:int, month:?int} */
    public static function get(): array
    {
        $v = Cache::get(self::KEY);
        $article = is_array($v) && is_int($v['article'] ?? null) && $v['article'] > 0 ? $v['article'] : self::DEFAULT_PER_ARTICLE;
        $month = is_array($v) && is_int($v['month'] ?? null) && $v['month'] > 0 ? $v['month'] : null;

        return ['article' => $article, 'month' => $month];
    }

    /**
     * @return array{article:int, month:?int}
     *
     * @throws InvalidArgumentException với thông điệp tiếng Việt, khóa field => lỗi trong getMessage()
     */
    public static function validate(mixed $article, mixed $month): array
    {
        $a = self::toPositiveInt($article);
        if ($a === null || $a > self::MAX_PER_ARTICLE) {
            throw new InvalidArgumentException('Ngưỡng theo bài phải là số nguyên dương, tối đa '.Vnd::int(self::MAX_PER_ARTICLE).' ₫.');
        }
        $m = null;
        if ($month !== null && trim((string) $month) !== '') {
            $m = self::toPositiveInt($month);
            if ($m === null || $m > self::MAX_PER_MONTH) {
                throw new InvalidArgumentException('Ngưỡng theo tháng phải là số nguyên dương, tối đa '.Vnd::int(self::MAX_PER_MONTH).' ₫, hoặc để trống.');
            }
            if ($m < $a) {
                throw new InvalidArgumentException('Ngưỡng theo tháng phải lớn hơn hoặc bằng ngưỡng theo bài, hoặc để trống.');
            }
        }

        return ['article' => $a, 'month' => $m];
    }

    /** @return array{article:int, month:?int} */
    public static function save(mixed $article, mixed $month): array
    {
        $v = self::validate($article, $month);
        Cache::forever(self::KEY, $v);

        return $v;
    }

    /** Vượt khi chi phí (làm tròn đồng như hiển thị) LỚN HƠN ngưỡng; đúng bằng ngưỡng thì không vượt. */
    public static function exceeds(?float $cost, ?int $threshold): bool
    {
        return $cost !== null && $threshold !== null && (int) round($cost) > $threshold;
    }

    private static function toPositiveInt(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (is_string($v) && preg_match('/^\d{1,12}$/', trim($v))) {
            $i = (int) trim($v);

            return $i > 0 ? $i : null;
        }

        return null;
    }
}
