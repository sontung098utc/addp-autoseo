<?php

namespace App\Filament\Support\Demo;

use Illuminate\Support\Facades\Cache;

/**
 * Kho trạng thái tạm cho dữ liệu minh họa (dùng cache, KHÔNG phải DB). Khi Dev1 thêm bảng thật thì thay lớp
 * nguồn dữ liệu của từng màn, không đổi giao diện. Khóa có tiền tố riêng; tắt hẳn ở production.
 */
final class DemoStore
{
    private const PREFIX = 'studio.demo.';

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! DemoMode::enabled()) {
            return $default;
        }

        return Cache::get(self::PREFIX.$key, $default);
    }

    public static function put(string $key, mixed $value): void
    {
        if (! DemoMode::enabled()) {
            return;
        }
        Cache::forever(self::PREFIX.$key, $value);
    }

    public static function forget(string $key): void
    {
        Cache::forget(self::PREFIX.$key);
    }
}
