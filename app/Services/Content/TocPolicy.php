<?php

namespace App\Services\Content;

/**
 * Theme ADDP tự dựng mục lục (nav.addp-article-toc) từ các h2 theo id neo; bài mang thêm ul.autoseo-toc thì người đọc thấy hai mục lục cùng 7 mục (xác nhận trên HTML bài đã đăng).
 * Hệ thống không tạo mục lục nữa; bản dựng lại của bài cũ bỏ khối này. Id neo trên h2 không đụng tới.
 */
final class TocPolicy
{
    public static function stripArticleToc(string $html): string
    {
        return (string) preg_replace('#<(ul|ol|div|nav)\b[^>]*\bclass="[^"]*\bautoseo-toc\b[^"]*"[^>]*>.*?</\1>\s*#is', '', $html);
    }
}
