<?php

namespace App\Filament\Support\Keywords;

/**
 * Gom cụm MINH HỌA, xác định (cùng đầu vào -> cùng kết quả), không gọi AI. Bỏ stopword tiếng Việt, chọn cụm từ
 * (2 từ liền nhau, hoặc 1 từ) xuất hiện ở nhiều từ khóa nhất; tên cụm = cụm từ chung dài nhất của các thành viên.
 * Mọi từ khóa vào ĐÚNG một cụm (từ khóa lẻ vào cụm "Từ khóa lẻ").
 */
final class MockClusterer
{
    public const LEFTOVER = 'Từ khóa lẻ';

    private const STOP = ['và', 'của', 'cho', 'là', 'các', 'những', 'một', 'có', 'để', 'trong', 'với', 'được', 'ở', 'tại', 'từ', 'như', 'thế', 'nào', 'bao', 'nhiêu', 'gì', 'không', 'hay', 'hoặc', 'the', 'a', 'of', 'ra', 'sao', 'thì', 'khi'];

    /** @return list<string> */
    private static function words(string $kw): array
    {
        $w = preg_split('/[^\p{L}\p{N}]+/u', CsvKeywordParser::key($kw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($w, fn ($x) => ! in_array($x, self::STOP, true)));
    }

    /** @return list<string> */
    private static function grams(array $w): array
    {
        $g = [];
        for ($i = 0; $i < count($w) - 1; $i++) {
            $g[$w[$i].' '.$w[$i + 1]] = true;
        }

        return array_keys($g);
    }

    /**
     * @param  list<string>  $keywords
     * @return list<array{name:string, keywords:list<string>}>
     */
    public function cluster(array $keywords): array
    {
        $keywords = array_values(array_unique($keywords));
        sort($keywords, SORT_STRING);
        $pending = $keywords;
        $out = [];
        foreach ([2, 1] as $n) {
            while (true) {
                $df = [];
                foreach ($pending as $kw) {
                    $w = self::words($kw);
                    $cands = $n === 2 ? self::grams($w) : array_values(array_unique($w));
                    foreach ($cands as $c) {
                        $df[$c] = ($df[$c] ?? 0) + 1;
                    }
                }
                $df = array_filter($df, fn ($c) => $c >= 2);
                if (! $df) {
                    break;
                }
                uksort($df, fn ($a, $b) => [$df[$b], $a] <=> [$df[$a], $b]);
                $best = array_key_first($df);
                $members = array_values(array_filter($pending, function ($kw) use ($best, $n) {
                    $w = self::words($kw);

                    return in_array($best, $n === 2 ? self::grams($w) : $w, true);
                }));
                $out[] = ['name' => self::name($members, $best), 'keywords' => $members];
                $pending = array_values(array_diff($pending, $members));
            }
        }
        if ($pending) {
            $out[] = ['name' => self::LEFTOVER, 'keywords' => $pending];
        }

        return $out;
    }

    /** Cụm từ liền nhau dài nhất mà mọi thành viên đều chứa (trên dãy từ đã bỏ stopword). */
    private static function name(array $members, string $fallback): string
    {
        $seqs = array_map(fn ($m) => self::words($m), $members);
        $first = $seqs[0];
        $best = [];
        for ($i = 0; $i < count($first); $i++) {
            for ($j = $i; $j < count($first); $j++) {
                $cand = array_slice($first, $i, $j - $i + 1);
                if (count($cand) <= count($best)) {
                    continue;
                }
                $all = true;
                foreach ($seqs as $s) {
                    if (! self::contains($s, $cand)) {
                        $all = false;
                        break;
                    }
                }
                if ($all) {
                    $best = $cand;
                }
            }
        }
        $name = $best ? implode(' ', $best) : $fallback;

        return mb_strtoupper(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }

    private static function contains(array $hay, array $needle): bool
    {
        for ($i = 0; $i + count($needle) <= count($hay); $i++) {
            if (array_slice($hay, $i, count($needle)) === $needle) {
                return true;
            }
        }

        return false;
    }
}
