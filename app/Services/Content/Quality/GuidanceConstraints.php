<?php

namespace App\Services\Content\Quality;

use App\Services\Content\VietnameseText;
use DOMDocument;
use DOMXPath;

/**
 * Trích ràng buộc ĐO ĐƯỢC từ "Chỉ dẫn cho đội AI" (và lời nhắn làm lại) bằng mẫu câu cố định, KHÔNG dùng AI để chốt số.
 *
 * Chỉ nhận cú pháp rõ ("dưới 600 từ", "đúng một bảng", "ít nhất 3 mục H2"); câu mơ hồ bị bỏ qua, không đoán.
 * Loại ràng buộc nằm trong whitelist TYPES. Chỉ dẫn là DỮ LIỆU hướng dẫn nội dung: câu yêu cầu bỏ qua quy tắc an toàn hay đăng bài
 * được nhận diện và BỎ QUA (ignored), không bao giờ nới quy tắc (AGENTS.md §1, §3).
 */
final class GuidanceConstraints
{
    public const TYPES = ['word_limit', 'table_count', 'heading_count', 'image_count'];

    /** "khoảng N từ" cho phép lệch ±15%. */
    public const APPROX_TOLERANCE = 0.15;

    private const NUM_WORDS = ['một' => 1, 'hai' => 2, 'ba' => 3, 'bốn' => 4, 'năm' => 5, 'sáu' => 6, 'bảy' => 7, 'tám' => 8, 'chín' => 9, 'mười' => 10];

    /**
     * @return array{constraints: list<array{type:string,min:?int,max:?int,label:string}>, ignored: list<string>}
     */
    public static function extract(string ...$texts): array
    {
        $constraints = [];
        $ignored = [];
        foreach ($texts as $text) {
            foreach (self::sentences($text) as $sentence) {
                if (self::isUnsafe($sentence)) {
                    $ignored[] = self::clip($sentence);
                }
                foreach (self::parse($sentence) as $c) {
                    $constraints[$c['type'].'|'.$c['min'].'|'.$c['max']] = $c;
                }
            }
        }

        return ['constraints' => array_values($constraints), 'ignored' => array_values(array_unique($ignored))];
    }

    /** @return list<string> */
    private static function sentences(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/(?<=\d)[.,](?=\d{3}(?!\d))/u', '', $text) ?? $text; // 1.000 → 1000
        $parts = preg_split('/[.!?;\n]+\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }

    /** Câu đòi bỏ/nới quy tắc an toàn, bỏ qua kiểm tra, hoặc đăng/xuất bản: không phải ràng buộc nội dung. */
    public static function isUnsafe(string $s): bool
    {
        $s = mb_strtolower($s);

        return preg_match('/(bỏ qua|phớt lờ|vô hiệu|tắt|không cần|đừng|ignore|bypass|disable).{0,40}(quy tắc|luật|an toàn|kiểm|chấm|rubric|duyệt|safety|rule)/u', $s) === 1
            || preg_match('/(đăng|xuất bản|publish)\s+(ngay|luôn|lập tức|tự động|thẳng|bài này|lên)/u', $s) === 1
            || preg_match('/(bỏ qua|ignore).{0,20}(mọi|tất cả|all)/u', $s) === 1;
    }

    private static function num(string $t): ?int
    {
        return ctype_digit($t) ? (int) $t : (self::NUM_WORDS[$t] ?? null);
    }

    /** @return list<array{type:string,min:?int,max:?int,label:string}> */
    private static function parse(string $s): array
    {
        $out = [];
        $n = '(\d{1,6}|một|hai|ba|bốn|năm|sáu|bảy|tám|chín|mười)';
        $w = 'từ(?![\p{L}])(?!\s+(?:khóa|khoá|ngữ|nối|vựng|điển|mới))';
        $add = function (string $type, ?int $min, ?int $max, string $label) use (&$out) {
            $out[] = ['type' => $type, 'min' => $min, 'max' => $max, 'label' => $label];
        };

        // Số từ.
        if (preg_match_all('/(dưới|ít hơn|nhỏ hơn|không quá|không vượt quá|tối đa|nhiều nhất|không dài quá)\s+(\d{2,6})\s+'.$w.'/u', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $v = (int) $x[2];
                $strict = in_array($x[1], ['dưới', 'ít hơn', 'nhỏ hơn'], true);
                if ($v >= 50 && $v <= 20000) {
                    $add('word_limit', null, $strict ? $v - 1 : $v, "{$x[1]} {$v} từ");
                }
            }
        }
        if (preg_match_all('/(ít nhất|tối thiểu|không dưới|không ít hơn|trên|nhiều hơn)\s+(\d{2,6})\s+'.$w.'/u', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $v = (int) $x[2];
                $strict = in_array($x[1], ['trên', 'nhiều hơn'], true);
                if ($v >= 50 && $v <= 20000) {
                    $add('word_limit', $strict ? $v + 1 : $v, null, "{$x[1]} {$v} từ");
                }
            }
        }
        if (preg_match_all('/(khoảng|xấp xỉ|tầm|chừng)\s+(\d{2,6})\s+'.$w.'/u', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $v = (int) $x[2];
                if ($v >= 50 && $v <= 20000) {
                    $add('word_limit', (int) ceil($v * (1 - self::APPROX_TOLERANCE)), (int) floor($v * (1 + self::APPROX_TOLERANCE)), "khoảng {$v} từ");
                }
            }
        }

        // Số bảng / ảnh / mục H2.
        $units = ['table_count' => 'bảng', 'image_count' => '(?:ảnh|hình ảnh|hình)', 'heading_count' => '(?:mục h2|đề mục h2|heading h2|h2)'];
        $names = ['table_count' => 'bảng', 'image_count' => 'ảnh', 'heading_count' => 'mục H2'];
        foreach ($units as $type => $unit) {
            $name = $names[$type];
            if (preg_match_all('/(đúng|chỉ|duy nhất|ít nhất|tối thiểu|tối đa|nhiều nhất|không quá)\s+'.$n.'\s+'.$unit.'(?![\p{L}])/u', $s, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    $v = self::num($x[2]);
                    if ($v === null) {
                        continue;
                    }
                    $q = $x[1];
                    [$min, $max] = match (true) {
                        in_array($q, ['ít nhất', 'tối thiểu'], true) => [$v, null],
                        in_array($q, ['tối đa', 'nhiều nhất', 'không quá'], true) => [null, $v],
                        default => [$v, $v],
                    };
                    $add($type, $min, $max, "{$q} {$v} {$name}");
                }
            }
            if (preg_match_all('/'.$n.'\s+'.$unit.'\s+duy nhất/u', $s, $m, PREG_SET_ORDER)) {
                foreach ($m as $x) {
                    if (($v = self::num($x[1])) !== null) {
                        $add($type, $v, $v, "đúng {$v} {$name}");
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Dòng cho người duyệt: "Chỉ dẫn đã tuân: dưới 600 từ ✗ (990), đúng 1 bảng ✓". null nếu chỉ dẫn không có ràng buộc đo được.
     */
    public static function summaryLine(string $guidance, string $html, string $extra = ''): ?string
    {
        $ex = self::extract($guidance, $extra);
        if ($ex['constraints'] === []) {
            return null;
        }
        $parts = array_map(function (array $r) {
            if ($r['ok'] === null) {
                return "{$r['label']} (chưa kiểm tự động)";
            }

            return $r['label'].($r['ok'] ? ' ✓' : " ✗ ({$r['measured']})");
        }, self::evaluate($ex['constraints'], $html));

        return 'Chỉ dẫn đã tuân: '.implode(', ', $parts);
    }

    private static function clip(string $s): string
    {
        return mb_strlen($s) > 140 ? mb_substr($s, 0, 140).'…' : $s;
    }

    /**
     * Đo bản viết: số từ THÂN BÀI (bỏ tiêu đề trang H1, ảnh/chú thích, mục lục, ghi chú, danh sách nguồn; cùng hàm đếm với cột "Số từ"), số bảng, số H2, số ảnh.
     *
     * @return array{words:int,tables:int,h2:int,images:int}
     */
    public static function measure(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="ss-root">'.$html.'</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $x = new DOMXPath($dom);
        $count = fn (string $q) => (int) ($x->query($q)?->length ?? 0);
        $tables = $count('//table');
        $h2 = $count('//h2[not(ancestor::*[contains(@class,"autoseo-refs") or contains(@class,"toc")])]');
        $images = $count('//img');

        return ['words' => VietnameseText::bodyWordCountHtml($html), 'tables' => $tables, 'h2' => $h2, 'images' => $images];
    }

    /**
     * @param  list<array{type:string,min:?int,max:?int,label:string}>  $constraints
     * @return list<array{label:string,type:string,ok:?bool,measured:?int,unit:string,min:?int,max:?int,deviation:int,message:string}> ok = null: chưa đo được tự động
     */
    public static function evaluate(array $constraints, string $html): array
    {
        $m = self::measure($html);
        $out = [];
        foreach ($constraints as $c) {
            $unit = ['word_limit' => 'từ', 'table_count' => 'bảng', 'heading_count' => 'mục H2', 'image_count' => 'ảnh'][$c['type']];
            if ($c['type'] === 'image_count') { // ảnh được gắn sau khi viết (bước lắp ráp), nên không chấm được trên bản nháp
                $out[] = ['label' => $c['label'], 'type' => $c['type'], 'ok' => null, 'measured' => null, 'unit' => $unit, 'min' => $c['min'], 'max' => $c['max'], 'deviation' => 0, 'message' => "{$c['label']}: chưa kiểm tự động (ảnh gắn ở bước lắp ráp)"];
                continue;
            }
            $v = ['word_limit' => $m['words'], 'table_count' => $m['tables'], 'heading_count' => $m['h2']][$c['type']];
            $dev = 0;
            $ask = '';
            if ($c['max'] !== null && $v > $c['max']) {
                $dev = $v - $c['max'];
                $ask = ($c['type'] === 'word_limit' ? "rút xuống tối đa {$c['max']}" : "giảm xuống tối đa {$c['max']}")." {$unit}";
            } elseif ($c['min'] !== null && $v < $c['min']) {
                $dev = $c['min'] - $v;
                $ask = "tăng lên tối thiểu {$c['min']} {$unit}";
            }
            $ok = $dev === 0;
            $out[] = ['label' => $c['label'], 'type' => $c['type'], 'ok' => $ok, 'measured' => $v, 'unit' => $unit, 'min' => $c['min'], 'max' => $c['max'], 'deviation' => $dev,
                'message' => $ok ? "{$c['label']}: đạt ({$v} {$unit})" : "{$c['label']}: hiện {$v} {$unit}, cần {$ask}"];
        }

        return $out;
    }
}
