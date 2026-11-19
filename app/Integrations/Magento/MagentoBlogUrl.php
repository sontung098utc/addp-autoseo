<?php

namespace App\Integrations\Magento;

use App\Models\Publication;
use App\Models\Website;

/**
 * Link công khai của bài blog trên Magento.
 * Magefan_Blog: {base}/blog/{url_key}; Mageplaza_Blog: {base}/blog/post/{url_key}.
 * Mô-đun Company_BlogApi bản mới trả sẵn "url" và "blog_driver"; bản cũ chỉ trả url_key nên phải tự dựng.
 * Khi không biết nền tảng thì coi là Magefan vì website ADDP (Magento duy nhất đang chạy) dùng Magefan_Blog.
 * Website dùng Mageplaza có thể ghi capability_flags.blog_driver = "mageplaza".
 */
final class MagentoBlogUrl
{
    public const MAGEFAN = 'magefan';

    public const MAGEPLAZA = 'mageplaza';

    public static function isMagento(?Website $site): bool
    {
        return $site !== null && in_array(strtolower((string) $site->cms_type), ['magento', 'magento2'], true);
    }

    /** Nền tảng blog: mô-đun trả về > capability_flags.blog_driver của website > mặc định Magefan. */
    public static function driver(?Website $site, mixed $reported = null): string
    {
        foreach ([$reported, $site?->capability_flags['blog_driver'] ?? null] as $d) {
            $d = strtolower(trim((string) $d));
            if (in_array($d, [self::MAGEFAN, self::MAGEPLAZA], true)) {
                return $d;
            }
        }

        return self::MAGEFAN;
    }

    public static function build(string $baseUrl, string $urlKey, string $driver): string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $urlKey = trim($urlKey, '/');

        return $driver === self::MAGEPLAZA ? "{$baseUrl}/blog/post/{$urlKey}" : "{$baseUrl}/blog/{$urlKey}";
    }

    /**
     * Link bài sau khi đăng, từ phản hồi của mô-đun. Chỉ nhận "url" khi là http(s) và cùng tên miền với website
     * (không lưu link lạ do phản hồi bị sửa); nếu không thì dựng từ url_key theo nền tảng.
     */
    public static function fromResponse(Website $site, array $body, string $fallbackKey): string
    {
        $url = trim((string) ($body['url'] ?? ''));
        if ($url !== '' && self::sameHost($url, (string) $site->base_url)) {
            return $url;
        }
        $key = trim((string) ($body['url_key'] ?? ''));

        return self::build((string) $site->base_url, $key !== '' ? $key : $fallbackKey, self::driver($site, $body['blog_driver'] ?? null));
    }

    public static function sameHost(string $url, string $baseUrl): bool
    {
        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }
        $h = strtolower((string) parse_url($url, PHP_URL_HOST));
        $b = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $strip = fn (string $x): string => str_starts_with($x, 'www.') ? substr($x, 4) : $x;

        return $h !== '' && $b !== '' && $strip($h) === $strip($b);
    }

    /**
     * Link cũ sai dạng "{base_url}/blog/post/{key}" (bản trước dựng cố định cho mọi site Magento) -> "{base_url}/blog/{key}".
     * Chỉ đổi đúng mẫu đó của website Magento chạy Magefan; link khác (đã đúng, nháp /blog/post/view/id/..., tên miền khác) giữ nguyên. Null nếu không cần đổi.
     */
    public static function legacyFix(?string $url, ?Website $site): ?string
    {
        if ($url === null || $url === '' || ! self::isMagento($site) || self::driver($site) !== self::MAGEFAN) {
            return null;
        }
        $base = rtrim((string) $site->base_url, '/');
        if ($base === '' || ! preg_match('#^'.preg_quote($base, '#').'/blog/post/([^/?\#]+)/?$#i', $url, $m) || strtolower($m[1]) === 'view') {
            return null;
        }

        return "{$base}/blog/{$m[1]}";
    }

    /** Link để hiển thị/mở: sửa ngay lúc hiển thị link cũ sai dạng, không ghi vào DB. */
    public static function display(?Publication $p): ?string
    {
        if ($p === null || ! $p->published_url) {
            return $p?->published_url;
        }

        return self::legacyFix($p->published_url, $p->website) ?? $p->published_url;
    }
}
