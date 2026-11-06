<?php

namespace App\Filament\Support\Competitors;

use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Models\Article;
use App\Models\Website;

/**
 * Khoảng trống nội dung THẬT: từ khóa đối thủ có hạng mà website CHƯA có bài. Từ khóa đã có bài / đã lên kế hoạch
 * (khớp articles.primary_keyword sau chuẩn hóa) được ĐÁNH DẤU kèm bài, không chấm điểm, không đề xuất (tránh ăn thịt từ khóa).
 */
final class GapCalculator
{
    /** @return array<string, array{id:int, keyword:string, status:string, cluster:?string}> khóa = từ khóa đã chuẩn hóa */
    public static function existingFor(Website $website): array
    {
        $map = [];
        foreach (Article::where('website_id', $website->id)->get(['id', 'primary_keyword', 'workflow_status', 'topic_cluster']) as $a) {
            $map[PlannedArticleCreator::normalize((string) $a->primary_keyword)] ??= [
                'id' => $a->id, 'keyword' => (string) $a->primary_keyword,
                'status' => (string) $a->workflow_status, 'cluster' => $a->topic_cluster,
            ];
        }

        return $map;
    }

    /**
     * @param  list<array{keyword:string, intent?:string, volume:int, difficulty:int, ranks:array<string,int>}>  $rows
     * @param  array<string, array{id:int, keyword:string, status:string, cluster:?string}>  $existing
     * @return list<array<string,mixed>> đề xuất xếp theo điểm giảm dần, dòng đã có bài ở cuối
     */
    public static function calculate(array $rows, array $existing, int $totalCompetitors): array
    {
        $merged = [];
        foreach ($rows as $r) {
            $n = PlannedArticleCreator::normalize($r['keyword']);
            if ($n === '' || $r['ranks'] === []) {
                continue;
            }
            if (isset($merged[$n])) {
                $merged[$n]['ranks'] = $merged[$n]['ranks'] + $r['ranks'];
                $merged[$n]['volume'] = max($merged[$n]['volume'], $r['volume']);

                continue;
            }
            $merged[$n] = [
                'keyword' => trim((string) preg_replace('/\s+/u', ' ', $r['keyword'])), 'norm' => $n,
                'intent' => $r['intent'] ?? 'informational', 'volume' => $r['volume'],
                'difficulty' => $r['difficulty'], 'ranks' => $r['ranks'],
            ];
        }

        $open = array_filter($merged, fn (array $m) => ! isset($existing[$m['norm']]));
        $maxVolume = $open === [] ? 0 : max(array_column($open, 'volume'));

        $out = [];
        foreach ($merged as $n => $m) {
            $ex = $existing[$n] ?? null;
            $m['existing'] = $ex;
            $m['score'] = null;
            if ($ex === null) {
                $m['score'] = PriorityScorer::score($m['volume'], $m['difficulty'], count($m['ranks']), $totalCompetitors, $maxVolume);
            }
            $out[] = $m;
        }
        usort($out, function (array $a, array $b): int {
            if (($a['existing'] === null) !== ($b['existing'] === null)) {
                return $a['existing'] === null ? -1 : 1;
            }

            return [$b['score']['score'] ?? 0, $b['volume']] <=> [$a['score']['score'] ?? 0, $a['volume']];
        });

        return $out;
    }
}
