<?php

namespace App\Services\Content;

/**
 * Chuẩn hóa chú thích số ở bản lưu cho người đọc: (1) số đánh theo THỨ TỰ XUẤT HIỆN trong bài (kho và web chung một dãy 1, 2, 3...), danh sách nguồn xếp theo đúng số đó;
 * (2) bớt dày: trong cùng một đoạn/mục không lặp lại cùng một nguồn từ lần thứ hai (chú thích web), và hai chú thích giống hệt đứng liền nhau chỉ giữ một.
 * Chú thích từ kho (có data-kb) không bị bỏ vì bộ kiểm trích dẫn đọc chúng theo từng câu. Không đổi nội dung chữ của bài.
 */
final class CitationOrder
{
    private const SUP = '#<sup\b[^>]*\bclass="[^"]*\bautoseo-cite\b[^"]*"[^>]*>\s*<a\b[^>]*\bhref="\#nguon-(\d+)"[^>]*>\[\d+\]</a>\s*</sup>#u';

    public static function normalize(string $html): string
    {
        if ($html === '' || ! preg_match_all(self::SUP, $html)) {
            return $html;
        }
        // 1. Bớt dày theo từng khối (p, li, td, h2/h3 hay div): lần lặp lại thứ hai của cùng nguồn (chỉ chú thích web) và bản sao liền kề bị bỏ.
        $html = (string) preg_replace_callback('#<(p|li|td|blockquote)\b([^>]*)>(.*?)</\1>#is', function (array $m): string {
            $seen = [];
            $inner = (string) preg_replace_callback(self::SUP, function (array $s) use (&$seen): string {
                $n = (int) $s[1];
                $kb = str_contains($s[0], 'data-kb=');
                if (! $kb && isset($seen[$n])) {
                    return '';
                }
                $seen[$n] = true;

                return $s[0];
            }, $m[3]);
            $inner = (string) preg_replace_callback('#((?:'.trim(self::SUP, '#u').')\s*)\1+#u', fn (array $d) => $d[1], $inner);

            return '<'.$m[1].$m[2].'>'.$inner.'</'.$m[1].'>';
        }, $html);

        // 2. Đánh số lại theo thứ tự xuất hiện.
        $map = [];
        preg_match_all(self::SUP, $html, $all, PREG_SET_ORDER);
        foreach ($all as $a) {
            $old = (int) $a[1];
            $map[$old] ??= count($map) + 1;
        }
        $html = (string) preg_replace_callback(self::SUP, function (array $s) use ($map): string {
            $old = (int) $s[1];
            $new = $map[$old];

            return (string) preg_replace(['/href="#nguon-\d+"/', '/\[\d+\]/'], ['href="#nguon-'.$new.'"', '['.$new.']'], $s[0], 1);
        }, $html);

        // 3. Xếp lại danh sách nguồn: đã được trích theo số mới, rồi các nguồn còn lại.
        return (string) preg_replace_callback('#(<div class="autoseo-refs">.*?<ol>)(.*?)(</ol>)#s', function (array $m) use ($map): string {
            preg_match_all('#<li\b[^>]*>.*?</li>#s', $m[2], $lis);
            $cited = [];
            $rest = [];
            foreach ($lis[0] as $li) {
                if (preg_match('#^<li\b[^>]*\bid="nguon-(\d+)"#', $li, $id) && isset($map[(int) $id[1]])) {
                    $new = $map[(int) $id[1]];
                    $cited[$new] = (string) preg_replace('/\bid="nguon-\d+"/', 'id="nguon-'.$new.'"', $li, 1);
                } else {
                    $rest[] = (string) preg_replace('/\sid="nguon-\d+"/', '', $li, 1);
                }
            }
            ksort($cited);

            return $m[1].implode('', $cited).implode('', $rest).$m[3];
        }, $html, 1);
    }
}
