<?php

namespace App\Services\Content;

class HtmlSanitizer
{
    /**
     * Danh sách thẻ HTML được phép sử dụng trong bài viết SEO
     */
    protected static array $allowedTags = [
        'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'br', 'hr',
        'strong', 'b', 'em', 'i', 'u', 'span',
        'ul', 'ol', 'li',
        'blockquote', 'code', 'pre',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'a', 'img', 'figure', 'figcaption',
    ];

    /**
     * Làm sạch mã HTML đầu ra của AI trước khi lưu và hiển thị
     */
    public static function clean(?string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // 1. Loại bỏ các khối nguy hiểm: script, style, iframe, object, embed
        $html = preg_replace('#<(script|style|iframe|object|embed|applet)[^>]*?>.*?</\\1>#si', '', $html);

        // 2. Chặn các URL javascript: hoặc data: trong href và src
        $html = preg_replace('#(href|src)\s*=\s*["\']\s*(javascript|data|vbscript):[^"\']*["\']#si', '$1="#"', $html);

        // 3. Loại bỏ toàn bộ sự kiện inline: onload, onerror, onclick, onmouseover...
        $html = preg_replace('#\s+on[a-z]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)#si', '', $html);

        // 4. Lọc thẻ theo allowlist
        $allowedTagsString = '<' . implode('><', static::$allowedTags) . '>';
        $cleaned = strip_tags($html, $allowedTagsString);

        // 5. Chuẩn hóa khoảng trắng dư thừa
        return trim($cleaned);
    }
}
