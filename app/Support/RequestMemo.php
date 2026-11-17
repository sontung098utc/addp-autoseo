<?php

namespace App\Support;

use Closure;

/**
 * Bộ nhớ trong MỘT yêu cầu, chỉ BẬT khi nơi gọi chủ động bật (ví dụ danh sách Bài viết). Tắt (mặc định) thì remember() chạy thẳng, không nhớ gì,
 * nên các tiến trình sống lâu (phiên đội AI, hàng đợi) không bao giờ thấy dữ liệu cũ. Dùng cho kết quả chỉ phụ thuộc vài tham số mà một trang
 * lặp lại cho hàng chục dòng (vd. Trí tuệ của website, danh sách cụm cấm).
 */
final class RequestMemo
{
    /** @var array<string, mixed>|null null = đang tắt */
    private static ?array $store = null;

    public static function enable(): void
    {
        self::$store ??= [];
    }

    public static function flush(): void
    {
        self::$store = null;
    }

    public static function remember(string $key, Closure $compute): mixed
    {
        if (self::$store === null) {
            return $compute();
        }

        return array_key_exists($key, self::$store) ? self::$store[$key] : (self::$store[$key] = $compute());
    }
}
