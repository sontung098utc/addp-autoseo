<?php

namespace App\Services\Content;

/**
 * Blog Magento của ADDP tự dựng hộp "Các nội dung chính" (thu gọn được) từ các h2; nếu bài còn mang mục lục riêng (ul.autoseo-toc) thì người đọc thấy hai mục lục cùng 7 mục.
 * Với CMS đã có mục lục riêng, bỏ mục lục của bài; CMS khác (WordPress) giữ nguyên.
 */
final class TocPolicy
{
    public static function cmsHasOwnToc(?string $cmsType): bool
    {
        return in_array(strtolower((string) $cmsType), ['magento', 'magento2'], true);
    }

    public static function stripArticleToc(string $html): string
    {
        return (string) preg_replace('#<(ul|ol|div|nav)\b[^>]*\bclass="[^"]*\bautoseo-toc\b[^"]*"[^>]*>.*?</\1>\s*#is', '', $html);
    }
}
