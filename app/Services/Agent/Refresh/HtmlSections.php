<?php

namespace App\Services\Agent\Refresh;

/**
 * Tách HTML bài thành phần mở đầu (trước H2 đầu) và các mục (mỗi mục từ một <h2> tới trước <h2> kế tiếp).
 * Thuần luật, 0 token. Cắt theo vị trí byte nên ghép lại giữ nguyên từng byte.
 */
final class HtmlSections
{
    private const H2_OPEN = '/<h2(?=[\s>\/])[^>]*>/iu';

    /** @return array{intro:string, sections:list<array{index:int, heading:string, html:string}>} */
    public static function split(string $html): array
    {
        $starts = self::starts($html);
        if ($starts === []) {
            return ['intro' => $html, 'sections' => []];
        }

        $sections = [];
        foreach ($starts as $i => $pos) {
            $end = $starts[$i + 1] ?? strlen($html);
            $chunk = substr($html, $pos, $end - $pos);
            $sections[] = ['index' => $i, 'heading' => self::headingText($chunk), 'html' => $chunk];
        }

        return ['intro' => substr($html, 0, $starts[0]), 'sections' => $sections];
    }

    /**
     * Thay nguyên cả mục theo chỉ số; mọi byte còn lại giữ nguyên. Chỉ số không tồn tại bị bỏ qua.
     *
     * @param  array<int,string>  $replacements  [index => html mới của cả mục, gồm thẻ H2]
     */
    public static function replace(string $html, array $replacements): string
    {
        if ($replacements === []) {
            return $html;
        }
        $parts = self::split($html);
        $out = $parts['intro'];
        foreach ($parts['sections'] as $s) {
            $out .= array_key_exists($s['index'], $replacements) ? $replacements[$s['index']] : $s['html'];
        }

        return $out;
    }

    /** @return list<int> vị trí byte của từng <h2>, bỏ qua <h2> nằm trong chú thích HTML. */
    private static function starts(string $html): array
    {
        if (! preg_match_all(self::H2_OPEN, $html, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $comments = [];
        if (preg_match_all('/<!--.*?(?:-->|$)/su', $html, $c, PREG_OFFSET_CAPTURE)) {
            foreach ($c[0] as [$text, $off]) {
                $comments[] = [$off, $off + strlen($text)];
            }
        }
        $out = [];
        foreach ($m[0] as [, $off]) {
            foreach ($comments as [$a, $b]) {
                if ($off >= $a && $off < $b) {
                    continue 2;
                }
            }
            $out[] = $off;
        }

        return $out;
    }

    private static function headingText(string $chunk): string
    {
        $inner = preg_match('/^<h2[^>]*>(.*?)<\/h2\s*>/isu', $chunk, $m) ? $m[1] : (string) preg_replace('/^<h2[^>]*>/iu', '', $chunk);
        $text = html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
