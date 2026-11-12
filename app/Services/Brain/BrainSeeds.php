<?php

namespace App\Services\Brain;

/** Trang chủ lực theo lĩnh vực (resources/brains/seeds/{domain_key}.php), tách khỏi mẫu Trí tuệ vì mẫu chỉ chứa cấu trúc, không chứa liên kết hay sự kiện. */
final class BrainSeeds
{
    /** Thư mục khác mặc định (dùng cho test). */
    public static ?string $dir = null;

    /** @return list<array{url:string,title:string,tier:string}> chỉ URL https hợp lệ */
    public static function for(?string $domainKey): array
    {
        $key = preg_replace('/[^a-z0-9_]/', '', (string) $domainKey);
        if ($key === '') {
            return [];
        }
        $f = (self::$dir ?? resource_path('brains/seeds')).'/'.$key.'.php';
        $rows = is_file($f) ? (array) include $f : [];

        return array_values(array_map(fn ($u) => ['url' => (string) $u['url'], 'title' => (string) ($u['title'] ?? ''), 'tier' => (string) ($u['tier'] ?? '3')],
            array_filter($rows, fn ($u) => is_array($u) && preg_match('#^https://#', (string) ($u['url'] ?? '')) === 1)));
    }
}
