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

    /**
     * Số từ THÂN BÀI của HTML: bỏ tiêu đề trang (H1), ảnh và chú thích, mục lục, ghi chú, danh sách nguồn. MỘT hàm cho cột "Số từ" ở bảng phiên bản và luật INS01
     * để hai nơi ra cùng số.
     */
    public static function bodyWordCountHtml(string $html): int
    {
        if (trim($html) === '') {
            return 0;
        }
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="ss-root">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $x = new \DOMXPath($dom);
        $drop = '//h1|//figure|//figcaption|//script|//style|//img|//*[contains(@class,"autoseo-refs") or contains(@class,"autoseo-notes") or contains(@class,"toc")]';
        $nodes = [];
        foreach ($x->query($drop) ?: [] as $n) {
            $nodes[] = $n;
        }
        foreach ($nodes as $n) {
            $n->parentNode?->removeChild($n);
        }
        $parts = [];
        foreach ($x->query('//text()') ?: [] as $t) {
            $parts[] = $t->nodeValue;
        }

        return self::wordCount(implode(' ', $parts));
    }
}
