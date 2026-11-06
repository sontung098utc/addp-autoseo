<?php

namespace App\Filament\Support\Competitors;

use App\Filament\Support\Demo\DemoStore;
use Illuminate\Support\Carbon;

/**
 * Chi phí/hạn mức. GIÁ và HẠN MỨC dưới đây là GIẢ ĐỊNH minh họa (chưa phải bảng giá nhà cung cấp nào); hạn mức đã
 * dùng lưu trong DemoStore. Khi có nhà cung cấp thật: đọc từ cấu hình/bảng keyword_provider_usage.
 */
final class CostPolicy
{
    public const VND_PER_1000_ROWS = 3800;

    public const MONTHLY_ROW_LIMIT = 20000;

    private static function key(): string
    {
        return 'competitors.usage.'.Carbon::now()->format('Y-m');
    }

    public static function used(): int
    {
        return (int) DemoStore::get(self::key(), 0);
    }

    public static function remaining(): int
    {
        return max(0, self::MONTHLY_ROW_LIMIT - self::used());
    }

    public static function costVnd(int $rows): int
    {
        return (int) ceil($rows / 1000 * self::VND_PER_1000_ROWS);
    }

    /** @return array{rows:int, cost_vnd:int, remaining:int, blocked:?string} */
    public static function estimate(int $rows): array
    {
        $blocked = $rows > self::remaining()
            ? 'Vượt hạn mức tháng: cần '.number_format($rows, 0, ',', '.').' dòng, còn '.number_format(self::remaining(), 0, ',', '.').'. Hạn mức làm mới đầu tháng sau.'
            : null;

        return ['rows' => $rows, 'cost_vnd' => self::costVnd($rows), 'remaining' => self::remaining(), 'blocked' => $blocked];
    }

    public static function charge(int $rows): void
    {
        DemoStore::put(self::key(), self::used() + $rows);
    }
}
