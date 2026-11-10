<?php

namespace App\Services\Brain;

/**
 * AG-B1: sổ mẫu trí tuệ (resources/brains/templates/*.php). Mẫu chỉ mang CẤU TRÚC và QUY TẮC, tuyệt đối không mang sự kiện/số liệu/tên sản phẩm
 * (thuật ngữ để trống để người dùng hoặc chuyên gia điền). Thứ tự hiển thị cố định.
 */
final class BrainTemplates
{
    public const ORDER = ['thuong_hieu', 'y_khoa_duoc', 'giao_duc', 'ngoai_ngu', 'khoa_hoc', 'san_pham', 'trong'];

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string,array<string,mixed>> khóa mẫu => định nghĩa (đã thêm 'key') */
    public static function all(): array
    {
        if (self::$cache === null) {
            $out = [];
            foreach (self::ORDER as $key) {
                $file = resource_path('brains/templates/'.$key.'.php');
                if (is_file($file)) {
                    $out[$key] = ['key' => $key] + (array) require $file;
                }
            }
            self::$cache = $out;
        }

        return self::$cache;
    }

    /** @return array<string,mixed>|null */
    public static function get(?string $key): ?array
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    /** @return array<string,string> khóa => tên, cho ô chọn */
    public static function options(): array
    {
        return array_map(fn ($t) => (string) $t['name'], self::all());
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
