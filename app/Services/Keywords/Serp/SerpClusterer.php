<?php

namespace App\Services\Keywords\Serp;

use App\Filament\Support\Keywords\CsvKeywordParser;
use App\Filament\Support\Keywords\MockClusterer;

/**
 * Gom cụm theo SERP (học seo-cluster): hai từ khóa cùng cụm khi Google xếp CHUNG nhiều kết quả trong top 10, không dựa vào chữ giống nhau.
 * Ngưỡng số URL chung: 7–10 cùng một bài (gộp), 4–6 cùng cụm, 2–3 nên dẫn link chéo, 0–1 tách riêng.
 * Gom kiểu "ngôi sao" quanh từ khóa trụ (nhiều liên kết nhất) để không bị dây chuyền (A~B, B~C nhưng A không liên quan C).
 * Không đoán: từ khóa không lấy được SERP thì vào `unprocessed` kèm lý do, không bị xếp bừa vào cụm nào.
 */
final class SerpClusterer
{
    public const SAME_POST = 7;

    public const SAME_CLUSTER = 4;

    public const INTERLINK = 2;

    public const MAX_KEYWORDS = 40;

    public const TOP = 10;

    /**
     * @param  list<string>  $keywords  thứ tự ưu tiên (từ khóa quan trọng trước, vd. lượt tìm cao)
     * @param  ?float  $budgetVnd  trần chi phí tra SERP (null = không giới hạn)
     * @return array{groups:list<array{name:string,pillar:string,keywords:list<string>,same_post:list<array{keyword:string,shared:int}>,evidence:list<array{a:string,b:string,shared:int,urls:list<string>}>}>, interlinks:list<array{from:string,to:string,shared:int}>, unprocessed:list<array{keyword:string,reason:string}>, cost_vnd:float, providers:list<string>, stopped:?string}
     *
     * @throws SerpUnavailable khi chưa có nguồn SERP nào
     */
    public function cluster(array $keywords, SerpInsights $serp, ?float $budgetVnd = null): array
    {
        $keywords = array_slice(array_values(array_unique(array_filter(array_map(fn ($k) => CsvKeywordParser::normalizeKeyword((string) $k), $keywords), fn ($k) => $k !== ''))), 0, self::MAX_KEYWORDS);
        $res = ['groups' => [], 'interlinks' => [], 'unprocessed' => [], 'cost_vnd' => 0.0, 'providers' => [], 'stopped' => null];
        if ($keywords === []) {
            return $res;
        }
        if (! $serp->available()) {
            throw new SerpUnavailable('Chưa có nguồn dữ liệu SERP. '.SerpInsights::HOWTO);
        }
        $urls = []; // keyword => set các URL chuẩn hóa trong top 10
        foreach ($keywords as $i => $kw) {
            if ($budgetVnd !== null && $res['cost_vnd'] >= $budgetVnd) {
                $res['stopped'] = 'Dừng vì chạm trần chi phí tra SERP.';
                foreach (array_slice($keywords, $i) as $rest) {
                    $res['unprocessed'][] = ['keyword' => $rest, 'reason' => 'Chưa tra SERP (hết trần chi phí).'];
                }
                break;
            }
            try {
                $r = $serp->fetch($kw);
            } catch (SerpUnavailable $e) {
                $res['unprocessed'][] = ['keyword' => $kw, 'reason' => mb_substr($e->getMessage(), 0, 160)];

                continue;
            }
            $res['cost_vnd'] += (float) $r['cost_vnd'];
            $res['providers'] = array_values(array_unique(array_merge($res['providers'], $r['providers'])));
            $set = [];
            foreach ($r['organic'] as $o) {
                if ((int) $o['rank'] <= self::TOP && ($u = self::normalizeUrl($o['url'])) !== '') {
                    $set[$u] = true;
                }
            }
            if ($set === []) {
                $res['unprocessed'][] = ['keyword' => $kw, 'reason' => 'Nguồn SERP không trả kết quả tự nhiên (cần DataForSEO).'];

                continue;
            }
            $urls[$kw] = $set;
        }
        $ok = array_keys($urls);
        if ($ok === []) {
            return $res;
        }
        $overlap = [];
        foreach ($ok as $i => $a) {
            foreach (array_slice($ok, $i + 1) as $b) {
                $shared = array_keys(array_intersect_key($urls[$a], $urls[$b]));
                $overlap[$a][$b] = $overlap[$b][$a] = $shared;
            }
        }
        $n = fn (string $a, string $b): int => count($overlap[$a][$b] ?? []);
        $degree = [];
        foreach ($ok as $a) {
            $degree[$a] = count(array_filter($ok, fn ($b) => $b !== $a && $n($a, $b) >= self::SAME_CLUSTER));
        }
        $order = $ok;
        usort($order, fn ($a, $b) => [$degree[$b], array_search($a, $ok, true)] <=> [$degree[$a], array_search($b, $ok, true)]);
        $assigned = [];
        $leftover = [];
        foreach ($order as $seed) {
            if (isset($assigned[$seed])) {
                continue;
            }
            $members = [$seed];
            foreach ($ok as $m) {
                if ($m !== $seed && ! isset($assigned[$m]) && $n($seed, $m) >= self::SAME_CLUSTER) {
                    $members[] = $m;
                }
            }
            foreach ($members as $m) {
                $assigned[$m] = true;
            }
            if (count($members) === 1) {
                $leftover[] = $seed; // không có từ khóa nào cùng cụm theo SERP

                continue;
            }
            $sameP = [];
            $evidence = [];
            foreach (array_slice($members, 1) as $m) {
                $c = $n($seed, $m);
                if ($c >= self::SAME_POST) {
                    $sameP[] = ['keyword' => $m, 'shared' => $c];
                }
                $evidence[] = ['a' => $seed, 'b' => $m, 'shared' => $c, 'urls' => array_slice($overlap[$seed][$m], 0, 5)];
            }
            $res['groups'][] = ['name' => mb_substr($seed, 0, 120), 'pillar' => $seed, 'keywords' => $members, 'same_post' => $sameP, 'evidence' => $evidence];
        }
        if ($leftover !== []) {
            $res['groups'][] = ['name' => MockClusterer::LEFTOVER, 'pillar' => '', 'keywords' => $leftover, 'same_post' => [], 'evidence' => []];
        }
        // Liên kết chéo giữa các cụm (kể cả từ khóa lẻ): cặp từ khóa khác cụm có 2–3 URL chung (không đủ cùng cụm nhưng cùng chủ đề rộng).
        // Mỗi cặp cụm chỉ giữ cặp có nhiều URL chung nhất; đại diện của cụm là từ khóa trụ, của từ khóa lẻ là chính nó.
        $groupOf = [];
        $repOf = [];
        foreach ($res['groups'] as $gi => $g) {
            foreach ($g['keywords'] as $k) {
                $solo = $g['pillar'] === '';
                $groupOf[$k] = $solo ? 'solo:'.$k : 'g'.$gi;
                $repOf[$k] = $solo ? $k : $g['pillar'];
            }
        }
        $best = [];
        foreach ($ok as $i => $a) {
            foreach (array_slice($ok, $i + 1) as $b) {
                $c = $n($a, $b);
                if ($c < self::INTERLINK || $c >= self::SAME_CLUSTER || $groupOf[$a] === $groupOf[$b]) {
                    continue;
                }
                $pair = [$groupOf[$a], $groupOf[$b]];
                sort($pair);
                $key = implode('|', $pair);
                if (! isset($best[$key]) || $c > $best[$key]['shared']) {
                    $best[$key] = ['from' => $repOf[$a], 'to' => $repOf[$b], 'shared' => $c];
                }
            }
        }
        $res['interlinks'] = array_values($best);

        return $res;
    }

    /** Chuẩn hóa URL để so sánh: bỏ scheme, www, query, fragment, dấu / cuối; chữ thường. */
    public static function normalizeUrl(string $url): string
    {
        $p = parse_url(trim($url));
        if (! is_array($p) || empty($p['host'])) {
            return '';
        }
        $host = preg_replace('/^www\./', '', strtolower((string) $p['host'])) ?? '';
        $path = rtrim(strtolower(rawurldecode((string) ($p['path'] ?? ''))), '/');

        return $host.$path;
    }
}
