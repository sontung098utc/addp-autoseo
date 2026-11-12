<?php

namespace App\Services\Content;

/**
 * Đếm "từ" cho văn bản tiếng Việt, MỘT hàm dùng chung (số hiển thị ở bước viết và luật LEN01).
 * Quy ước của hệ thống: tách theo khoảng trắng, mỗi âm tiết là một từ; chỉ tính token có chữ hoặc số (bỏ dấu câu/ký hiệu đứng riêng).
 * Không dùng str_word_count: hàm đó chỉ hiểu chữ cái ASCII nên cắt "tiêu chuẩn" thành "ti", "u", "chu", "n" và phóng số từ khoảng 1,5 lần.
 */
final class VietnameseText
{
    /** @return list<string> */
    public static function words(string $text): array
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tokens = preg_split('/[\s\x{00A0}\x{200B}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($tokens, fn (string $t) => preg_match('/[\p{L}\p{N}]/u', $t) === 1));
    }

    public static function wordCount(string $text): int
    {
        return count(self::words($text));
    }

    /** Số từ của HTML (bỏ thẻ, script, style). */
    public static function wordCountHtml(string $html): int
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        return self::wordCount(strip_tags(str_replace(['</p>', '</li>', '</h1>', '</h2>', '</h3>', '</h4>', '<br>', '<br/>', '</td>', '</tr>'], ' ', $html)));
    }
}
