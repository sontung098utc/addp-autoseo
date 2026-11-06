<?php

namespace App\Filament\Support\Competitors;

/**
 * "AI đề xuất (minh họa)": gom khoảng trống thành cụm chủ đề bằng thuật toán XÁC ĐỊNH theo từ chung (không gọi LLM).
 * Chỉ đề xuất; người bấm Thêm mới tạo bài.
 */
final class ClusterSuggester
{
    private const STOP = ['và', 'của', 'các', 'cho', 'là', 'có', 'nào', 'gì', 'khi', 'với', 'một', 'những', 'để', 'the', 'a', 'of', 'to', 'in'];

    /**
     * @param  list<array<string,mixed>>  $gaps  (chỉ dòng chưa có bài)
     * @return list<array{name:string, keywords:list<string>, volume:int}>
     */
    public static function suggest(array $gaps): array
    {
        $open = array_values(array_filter($gaps, fn ($g) => $g['existing'] === null));
        $tokens = fn (string $k): array => array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $k) ?: [],
            fn ($t) => mb_strlen($t) > 2 && ! in_array($t, self::STOP, true) && ! preg_match('/^\d+$/', $t)
        )));
        $kw = array_map(fn ($g) => ['g' => $g, 't' => $tokens($g['norm'])], $open);

        $freq = [];
        foreach ($kw as $k) {
            foreach ($k['t'] as $t) {
                $freq[$t] = ($freq[$t] ?? 0) + 1;
            }
        }
        arsort($freq);

        $assigned = [];
        $clusters = [];
        foreach ($freq as $token => $count) {
            if ($count < 2) {
                break;
            }
            $members = [];
            foreach ($kw as $i => $k) {
                if (! isset($assigned[$i]) && in_array($token, $k['t'], true)) {
                    $members[] = $i;
                }
            }
            if (count($members) < 2) {
                continue;
            }
            foreach ($members as $i) {
                $assigned[$i] = true;
            }
            $clusters[] = [
                'name' => 'Chủ đề "'.$token.'"',
                'keywords' => array_map(fn ($i) => $kw[$i]['g']['keyword'], $members),
                'volume' => array_sum(array_map(fn ($i) => $kw[$i]['g']['volume'], $members)),
            ];
        }
        usort($clusters, fn ($a, $b) => $b['volume'] <=> $a['volume']);

        return $clusters;
    }
}
