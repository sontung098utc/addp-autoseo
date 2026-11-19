<?php

namespace App\Services\Knowledge\Discover;

use Illuminate\Support\Facades\Cache;

/**
 * Nhớ TÊN MIỀN nguồn không tải được (DNS, hết giờ, 5xx, mất kết nối) trong 24 giờ để lần gợi ý sau không đưa lại.
 * Lưu bằng Cache sẵn có (không thêm bảng hay cột): hết hạn tự xóa, mất khi xóa cache thì chỉ là thử lại sớm hơn, không mất dữ liệu kho.
 * Lỗi riêng của một trang (404, không phải HTML, quá lớn) KHÔNG làm hỏng cả tên miền nên không ghi nhớ.
 */
final class BadSourceMemory
{
    public const HOURS = 24;

    private const PREFIX = 'knowledge:bad_source_host:';

    private const INDEX = 'knowledge:bad_source_hosts';

    /** Lỗi tải này có phải lỗi của cả tên miền không (đáng nhớ)? */
    public static function hostLevel(string $error, ?int $httpStatus): bool
    {
        return in_array($error, ['dns', 'timeout'], true) || ($error === 'http_error' && ($httpStatus === null || $httpStatus >= 500));
    }

    public static function mark(string $host, string $error, string $message): void
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return;
        }
        $until = now()->addHours(self::HOURS);
        Cache::put(self::PREFIX.$host, ['error' => $error, 'message' => mb_substr($message, 0, 200), 'at' => now()->toIso8601String()], $until);
        $idx = array_values(array_unique(array_merge((array) Cache::get(self::INDEX, []), [$host])));
        Cache::put(self::INDEX, array_slice($idx, -200), $until);
    }

    public static function isBad(?string $host): bool
    {
        return $host !== null && $host !== '' && Cache::has(self::PREFIX.strtolower($host));
    }

    /** @return list<string> tên miền còn trong thời gian ghi nhớ */
    public static function hosts(): array
    {
        return array_values(array_filter((array) Cache::get(self::INDEX, []), fn ($h) => Cache::has(self::PREFIX.$h)));
    }
}
