<?php

namespace App\Filament\Support\Competitors;

/**
 * Điểm ưu tiên MINH BẠCH: score = round(100 × (0,5·V + 0,3·(1 − D/100) + 0,2·O)).
 * V = log10(volume+1) / log10(volume_lớn_nhất+1); D = độ khó 0–100; O = số đối thủ cùng xếp hạng ÷ tổng đối thủ.
 */
final class PriorityScorer
{
    public const FORMULA = 'điểm = làm tròn(100 × (0,5 × V + 0,3 × (1 − D ÷ 100) + 0,2 × O))';

    /**
     * @return array{score:int, vol_norm:float, diff_norm:float, overlap_norm:float, pts_volume:float, pts_difficulty:float, pts_overlap:float, total:float, reason:string}
     */
    public static function score(int $volume, int $difficulty, int $rankedCompetitors, int $totalCompetitors, int $maxVolume): array
    {
        $volume = max(0, $volume);
        $difficulty = min(100, max(0, $difficulty));
        $volNorm = $maxVolume > 0 ? min(1.0, log10($volume + 1) / log10($maxVolume + 1)) : 0.0;
        $diffNorm = 1 - $difficulty / 100;
        $overlapNorm = $totalCompetitors > 0 ? min(1.0, max(0, $rankedCompetitors) / $totalCompetitors) : 0.0;

        $pv = 50 * $volNorm;
        $pd = 30 * $diffNorm;
        $po = 20 * $overlapNorm;
        $total = $pv + $pd + $po;
        $score = (int) round(100 * (0.5 * $volNorm + 0.3 * $diffNorm + 0.2 * $overlapNorm));

        $f = fn (float $n): string => number_format($n, 1, ',', '.');
        $reason = 'Volume '.number_format($volume, 0, ',', '.').': +'.$f($pv)
            .' · Độ khó '.$difficulty.': +'.$f($pd)
            .' · '.$rankedCompetitors.'/'.$totalCompetitors.' đối thủ xếp hạng: +'.$f($po)
            .' · Tổng '.$f($total).', làm tròn '.$score;

        return [
            'score' => $score, 'vol_norm' => $volNorm, 'diff_norm' => $diffNorm, 'overlap_norm' => $overlapNorm,
            'pts_volume' => $pv, 'pts_difficulty' => $pd, 'pts_overlap' => $po, 'total' => $total, 'reason' => $reason,
        ];
    }
}
