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
        // 0. Cùng một đường dẫn mà có hai dòng nguồn (vd. nguồn kho và nguồn web trùng trang): chú thích trỏ hết về dòng đầu, dòng trùng sẽ bị bỏ khỏi danh sách.
        $html = self::mergeDuplicateSources($html);

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

        $html = self::collapseAdjacentSameNumber($html);

        // 1b. Kiểu Wikipedia: chú thích web chỉ ở chỗ CẦN. Hạn mức theo độ dài bài (khoảng 1 chú thích mỗi 220 từ, từ 3 đến 12); vượt hạn mức thì giữ những câu có số liệu/mốc trước, rồi theo thứ tự xuất hiện.
        $html = self::capWeb($html);

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

        // 3. Xếp lại danh sách nguồn theo số mới; nguồn không được trích bị bỏ.
        $html = (string) preg_replace_callback('#(<div class="autoseo-refs">.*?<ol>)(.*?)(</ol>\s*</div>)#s', function (array $m) use ($map): string {
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
            // Nguồn không được trích ở thân bài (không còn chú thích số nào trỏ tới) không được liệt kê: danh sách chỉ gồm nguồn người đọc có thể bấm từ một chú thích.
            $rest = [];

            if ($cited === []) {
                return ''; // không còn nguồn nào được trích: bỏ cả khối
            }

            return $m[1].implode('', $cited).$m[3];
        }, $html, 1);

        return $html;
    }

    private static function hrefOf(string $li): string
    {
        if (! preg_match('#<a\b[^>]*\bhref="([^"]+)"#i', $li, $h)) {
            return '';
        }

        return rtrim(strtolower(preg_replace('/#.*$/', '', html_entity_decode($h[1])) ?? ''), '/');
    }

    private static function mergeDuplicateSources(string $html): string
    {
        if (! preg_match('#<div class="autoseo-refs">(.*?)</div>#s', $html, $rm) || ! preg_match_all('#<li\b[^>]*\bid="nguon-(\d+)"[^>]*>.*?</li>#s', $rm[1], $lis, PREG_SET_ORDER)) {
            return $html;
        }
        $first = [];
        $canon = [];
        foreach ($lis as $l) {
            $u = self::hrefOf($l[0]);
            $n = (int) $l[1];
            if ($u === '') {
                continue;
            }
            if (isset($first[$u])) {
                $canon[$n] = $first[$u];
            } else {
                $first[$u] = $n;
            }
        }
        if ($canon === []) {
            return $html;
        }

        return (string) preg_replace_callback(self::SUP, function (array $s) use ($canon): string {
            $old = (int) $s[1];
            if (! isset($canon[$old])) {
                return $s[0];
            }

            return (string) preg_replace(['/href="#nguon-\d+"/', '/\[\d+\]/'], ['href="#nguon-'.$canon[$old].'"', '['.$canon[$old].']'], $s[0], 1);
        }, $html);
    }

    /** Hai chú thích đứng liền nhau cùng số nhưng khác thuộc tính (vd. một của kho, một của web): chỉ giữ một, ưu tiên bản của kho. */
    private static function collapseAdjacentSameNumber(string $html): string
    {
        $pair = '#('.trim(self::SUP, '#u').')\s*('.trim(self::SUP, '#u').')#u';
        for ($i = 0; $i < 10; $i++) {
            $next = (string) preg_replace_callback($pair, function (array $m): string {
                if ($m[2] !== $m[4]) {
                    return $m[0];
                }

                return str_contains($m[3], 'data-kb=') && ! str_contains($m[1], 'data-kb=') ? $m[3] : $m[1];
            }, $html);
            if ($next === $html) {
                break;
            }
            $html = $next;
        }

        return $html;
    }

    /** Số chú thích web tối đa cho bài có $words từ. */
    public static function allowance(int $words): int
    {
        return max(3, min(12, (int) round($words / 220)));
    }

    private static function capWeb(string $html): string
    {
        $body = (string) preg_replace('#<div class="autoseo-refs">.*#s', '', $html);
        $words = count(preg_split('/\s+/u', trim(strip_tags($body)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $allowed = self::allowance($words);
        if (! preg_match_all(self::SUP, $body, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $web = [];
        foreach ($m[0] as $i => [$tag, $off]) {
            if (str_contains($tag, 'data-kb=')) {
                continue; // chú thích kho do chính sách của Trí tuệ yêu cầu: không cắt
            }
            $before = mb_substr(strip_tags(substr($body, max(0, $off - 400), min(400, $off))), -220);
            $score = preg_match('/\d/u', $before) ? 2 : 1;
            $web[] = ['off' => $off, 'len' => strlen($tag), 'score' => $score, 'i' => $i];
        }
        if (count($web) <= $allowed) {
            return $html;
        }
        usort($web, fn ($a, $b) => [$b['score'], $a['i']] <=> [$a['score'], $b['i']]);
        $drop = array_slice($web, $allowed);
        usort($drop, fn ($a, $b) => $b['off'] <=> $a['off']);
        foreach ($drop as $d) {
            $html = substr($html, 0, $d['off']).substr($html, $d['off'] + $d['len']);
        }

        return $html;
    }
}
