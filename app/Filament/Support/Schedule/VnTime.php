<?php

namespace App\Filament\Support\Schedule;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Quy tắc múi giờ: DB lưu UTC (cột *_utc), giao diện hiển thị Asia/Ho_Chi_Minh.
 * Giá trị đọc từ DB được hiểu là UTC bất kể app.timezone (đọc thuộc tính thô), truy vấn bind chuỗi UTC.
 */
final class VnTime
{
    public const TZ = 'Asia/Ho_Chi_Minh';

    private const DB_FORMAT = 'Y-m-d H:i:s';

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ);
    }

    /** Chuỗi/đối tượng thời gian -> giờ VN. Chuỗi không kèm múi giờ được hiểu là UTC. */
    public static function toVn(CarbonInterface|string|null $time): ?CarbonImmutable
    {
        if ($time === null || $time === '') {
            return null;
        }

        $t = $time instanceof CarbonInterface ? CarbonImmutable::instance($time) : CarbonImmutable::parse($time, 'UTC');

        return $t->setTimezone(self::TZ);
    }

    /** Đọc đúng giá trị UTC thô của một cột thời gian trên model. */
    public static function fromRaw(Model $model, string $attribute): ?CarbonImmutable
    {
        $raw = $model->getRawOriginal($attribute) ?? $model->getAttributes()[$attribute] ?? null;
        if ($raw instanceof CarbonInterface) {
            return CarbonImmutable::instance($raw)->setTimezone('UTC');
        }

        return $raw ? CarbonImmutable::parse($raw, 'UTC') : null;
    }

    public static function format(CarbonInterface|string|null $time, string $format = 'd/m/Y H:i'): string
    {
        return self::toVn($time)?->format($format) ?? '—';
    }

    public static function formatUtc(CarbonInterface|string|null $time, string $format = 'd/m/Y H:i'): string
    {
        if ($time === null || $time === '') {
            return '—';
        }
        $t = $time instanceof CarbonInterface ? CarbonImmutable::instance($time) : CarbonImmutable::parse($time, 'UTC');

        return $t->setTimezone('UTC')->format($format).' UTC';
    }

    /** Ngày giờ VN người dùng nhập -> UTC. */
    public static function fromVn(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, self::TZ)->setTimezone('UTC');
    }

    /** @return array{0: string, 1: string} [bắt đầu (gồm), kết thúc (không gồm)] dạng chuỗi UTC để bind truy vấn */
    public static function dayRangeUtc(CarbonInterface $vnDay): array
    {
        $start = CarbonImmutable::instance($vnDay)->setTimezone(self::TZ)->startOfDay();

        return [$start->utc()->format(self::DB_FORMAT), $start->addDay()->utc()->format(self::DB_FORMAT)];
    }

    /**
     * Khoảng một ngày VN theo MÚI GIỜ ỨNG DỤNG, dùng cho cột Eloquent tự ghi bằng now() (agent_runs.started_at, created_at...).
     * Cột có tên *_utc mới dùng dayRangeUtc. Khi APP_TIMEZONE = UTC hai hàm cho cùng kết quả.
     *
     * @return array{0: string, 1: string}
     */
    public static function dayRangeApp(CarbonInterface $vnDay): array
    {
        $start = CarbonImmutable::instance($vnDay)->setTimezone(self::TZ)->startOfDay();
        $tz = (string) config('app.timezone', 'UTC');

        return [$start->setTimezone($tz)->format(self::DB_FORMAT), $start->addDay()->setTimezone($tz)->format(self::DB_FORMAT)];
    }

    /** @return array{0: string, 1: string} [bắt đầu, kết thúc) của hôm nay (giờ VN) theo múi giờ ứng dụng. */
    public static function todayRangeApp(?CarbonInterface $now = null): array
    {
        return self::dayRangeApp($now ?? self::now());
    }

    /** @return array{0: string, 1: string} */
    public static function todayRangeUtc(?CarbonInterface $now = null): array
    {
        return self::dayRangeUtc($now ?? self::now());
    }

    /** Thứ Hai 00:00 (giờ VN) của tuần chứa $vnDay. */
    public static function weekStart(?CarbonInterface $vnDay = null): CarbonImmutable
    {
        return CarbonImmutable::instance($vnDay ?? self::now())->setTimezone(self::TZ)->startOfWeek(CarbonInterface::MONDAY);
    }

    public static function dbString(CarbonInterface $time): string
    {
        return CarbonImmutable::instance($time)->utc()->format(self::DB_FORMAT);
    }
}
