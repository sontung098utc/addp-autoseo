<?php

namespace App\Filament\Support\Diff;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * So sánh hai bản HTML bài viết theo từng khối (đoạn/tiêu đề/mục danh sách).
 *
 * Thuần PHP, không phụ thuộc thư viện ngoài. Kết quả `compare()` là dữ liệu
 * thuần văn bản; `renderHtml()` mới sinh HTML và luôn escape toàn bộ dữ liệu vào.
 */
class ParagraphDiff
{
    private const BLOCK_XPATH = '//p | //h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //li | //blockquote | //td | //th';

    /** Ngưỡng tương đồng similar_text (%) để coi hai khối là "changed". */
    private const SIMILAR_PERCENT = 50.0;

    /** Số tích tối đa của bảng LCS mức từ; vượt thì fallback del/ins nguyên khối. */
    private const MAX_WORD_LCS_CELLS = 4_000_000;

    /**
     * @return list<array{type:string,tag:string,old:?string,new:?string,numberChanges:list<array{old:string,new:string}>}>
     */
    public static function compare(string $oldHtml, string $newHtml): array
    {
        $a = self::extractBlocks($oldHtml);
        $b = self::extractBlocks($newHtml);

        $keyA = array_map(fn ($x) => $x['tag'].'|'.$x['text'], $a);
        $keyB = array_map(fn ($x) => $x['tag'].'|'.$x['text'], $b);

        $result = [];
        $removed = [];
        $added = [];
        $flush = function () use (&$removed, &$added, &$result) {
            foreach (self::pairRun($removed, $added) as $item) {
                $result[] = $item;
            }
            $removed = [];
            $added = [];
        };

        foreach (self::lcsOps($keyA, $keyB) as [$op, $i, $j]) {
            if ($op === 'equal') {
                $flush();
                $result[] = self::item('equal', $b[$j]['tag'], $a[$i]['text'], $b[$j]['text']);
            } elseif ($op === 'del') {
                $removed[] = $a[$i];
            } else {
                $added[] = $b[$j];
            }
        }
        $flush();

        return $result;
    }

    /** Sinh HTML an toàn: mọi văn bản qua htmlspecialchars, chỉ dùng thẻ do lớp này tạo. */
    public static function renderHtml(array $diff): string
    {
        $out = '';
        foreach ($diff as $d) {
            $type = in_array($d['type'], ['equal', 'added', 'removed', 'changed'], true) ? $d['type'] : 'equal';
            $tag = self::e((string) ($d['tag'] ?? 'p'));
            $body = match ($type) {
                'added' => '<ins class="diff-ins">'.self::e((string) $d['new']).'</ins>',
                'removed' => '<del class="diff-del">'.self::e((string) $d['old']).'</del>',
                'changed' => self::wordDiffHtml((string) $d['old'], (string) $d['new']),
                default => self::e((string) $d['new']),
            };
            $out .= '<div class="diff-block diff-'.$type.'"><span class="diff-tag">'.$tag.'</span> '.$body.'</div>';
        }

        return $out;
    }

    private static function item(string $type, string $tag, ?string $old, ?string $new, array $numbers = []): array
    {
        return ['type' => $type, 'tag' => $tag, 'old' => $old, 'new' => $new, 'numberChanges' => $numbers];
    }

    /** Tách HTML thành các khối lá (không chứa khối con) với văn bản đã chuẩn hóa. */
    private static function extractBlocks(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new DOMXPath($doc);
        $blocks = [];
        foreach ($xpath->query(self::BLOCK_XPATH) ?: [] as $node) {
            /** @var DOMElement $node */
            if ($xpath->query('.//p | .//h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6 | .//li | .//blockquote | .//td | .//th', $node)->length > 0) {
                continue; // chỉ lấy khối lá để tránh trùng lặp
            }
            $text = self::normalize($node->textContent);
            if ($text !== '') {
                $blocks[] = ['tag' => strtolower($node->nodeName), 'text' => $text];
            }
        }

        if ($blocks === []) {
            // Không có thẻ khối: coi toàn bộ văn bản là một đoạn.
            $text = self::normalize($doc->textContent ?? '');
            if ($text !== '') {
                $blocks[] = ['tag' => 'p', 'text' => $text];
            }
        }

        return $blocks;
    }

    private static function normalize(string $s): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $s));
    }

    /**
     * LCS O(n*m) -> chuỗi thao tác [op, i, j] theo thứ tự, op: equal|del|add.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function lcsOps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j] ? $dp[$i + 1][$j + 1] + 1 : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['equal', $i++, $j++];
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['del', $i++, -1];
            } else {
                $ops[] = ['add', -1, $j++];
            }
        }
        while ($i < $n) {
            $ops[] = ['del', $i++, -1];
        }
        while ($j < $m) {
            $ops[] = ['add', -1, $j++];
        }

        return $ops;
    }

    /** Ghép khối xóa/thêm liền kề theo thứ tự: tương đồng -> changed, còn lại giữ removed/added. */
    private static function pairRun(array $removed, array $added): array
    {
        $out = [];
        $j = 0;
        foreach ($removed as $r) {
            $match = null;
            for ($k = $j; $k < count($added); $k++) {
                if (self::isSimilar($r, $added[$k])) {
                    $match = $k;
                    break;
                }
            }
            if ($match === null) {
                $out[] = self::item('removed', $r['tag'], $r['text'], null);

                continue;
            }
            for (; $j < $match; $j++) {
                $out[] = self::item('added', $added[$j]['tag'], null, $added[$j]['text']);
            }
            $out[] = self::item('changed', $added[$match]['tag'], $r['text'], $added[$match]['text'],
                self::numberChanges($r['text'], $added[$match]['text']));
            $j = $match + 1;
        }
        for (; $j < count($added); $j++) {
            $out[] = self::item('added', $added[$j]['tag'], null, $added[$j]['text']);
        }

        return $out;
    }

    private static function isSimilar(array $x, array $y): bool
    {
        if ($x['text'] === $y['text']) {
            return false; // chỉ khác vị trí/thẻ: để removed + added
        }
        similar_text(mb_strtolower($x['text']), mb_strtolower($y['text']), $percent);
        if ($percent >= self::SIMILAR_PERCENT) {
            return true;
        }
        if ($x['tag'] !== $y['tag']) {
            return false;
        }
        $wa = array_unique(array_filter(self::words($x['text']), fn ($w) => mb_strlen($w) >= 5));
        $wb = array_map('mb_strtolower', self::words($y['text']));

        foreach ($wa as $w) {
            if (in_array(mb_strtolower($w), $wb, true)) {
                return true;
            }
        }

        return false;
    }

    private static function words(string $s): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Trích token số: 1.000.000, 15%, 2027, 30/11, 12,5, "5 triệu". */
    private static function numbers(string $s): array
    {
        preg_match_all('/\d+(?:[.,\/]\d+)*(?:%|\s?(?:triệu|tỷ|nghìn|ngàn|tr)(?![\p{L}]))?/iu', $s, $m);

        return $m[0];
    }

    /** @return list<array{old:string,new:string}> */
    private static function numberChanges(string $old, string $new): array
    {
        $a = self::numbers($old);
        $b = self::numbers($new);
        // Loại các số xuất hiện ở cả hai bên (theo multiset), phần còn lại ghép theo thứ tự.
        foreach ($a as $i => $x) {
            $k = array_search($x, $b, true);
            if ($k !== false) {
                unset($a[$i], $b[$k]);
            }
        }
        $a = array_values($a);
        $b = array_values($b);
        $changes = [];
        for ($i = 0, $n = max(count($a), count($b)); $i < $n; $i++) {
            $changes[] = ['old' => $a[$i] ?? '', 'new' => $b[$i] ?? ''];
        }

        return $changes;
    }

    /** Diff mức từ trong một khối changed. */
    private static function wordDiffHtml(string $old, string $new): string
    {
        $a = preg_split('/\s+/u', $old, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('/\s+/u', $new, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($a) * count($b) > self::MAX_WORD_LCS_CELLS) {
            return '<del class="diff-del">'.self::e($old).'</del> <ins class="diff-ins">'.self::e($new).'</ins>';
        }

        $parts = [];
        foreach (self::lcsOps($a, $b) as [$op, $i, $j]) {
            $parts[] = match ($op) {
                'equal' => self::e($b[$j]),
                'del' => '<del class="diff-del'.(preg_match('/\d/', $a[$i]) ? ' diff-num' : '').'">'.self::e($a[$i]).'</del>',
                default => '<ins class="diff-ins'.(preg_match('/\d/', $b[$j]) ? ' diff-num' : '').'">'.self::e($b[$j]).'</ins>',
            };
        }

        return implode(' ', $parts);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
