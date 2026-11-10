<?php

namespace App\Services\Agent\Run;

use App\Services\Content\Quality\QualityReport;

/**
 * Điểm 0–100 của một bản viết, suy ra từ kết quả ContentQualityChecker.
 *
 * CÔNG THỨC: điểm = 100 − (20 × số mục P0 chưa đạt) − (5 × số mục P1 chưa đạt) − (2 × số mục P2 chưa đạt), kẹp vào [0, 100].
 *
 * Điểm CHỈ để hiển thị và theo dõi mức cải thiện qua các vòng sửa. Quyết định đạt/không đạt luôn do luật P0
 * (QualityReport::hasBlocking) — một bài 95 điểm mà còn một lỗi P0 vẫn KHÔNG đạt. Vì vậy `threshold` là mốc tham khảo, không phải cổng.
 */
final class RunScorer
{
    public const WEIGHT_P0 = 20;
    public const WEIGHT_P1 = 5;
    public const WEIGHT_P2 = 2;

    /** Mốc tham khảo hiển thị cạnh điểm; không dùng để quyết định. */
    public const REFERENCE_THRESHOLD = 80;

    public static function score(QualityReport $report): int
    {
        $c = $report->counts();
        $score = 100 - $c['P0'] * self::WEIGHT_P0 - $c['P1'] * self::WEIGHT_P1 - $c['P2'] * self::WEIGHT_P2;

        return max(0, min(100, $score));
    }

    /**
     * Chuỗi điểm theo vòng từ các bước 'grade' đã ghi: [['label' => 'v1', 'score' => 62], ['label' => 'sửa 1', 'score' => 78], ...].
     *
     * @param iterable<\App\Models\AgentRunStep> $steps
     * @return list<array{label: string, score: int}>
     */
    public static function timeline(iterable $steps): array
    {
        $out = [];
        foreach ($steps as $s) {
            if ($s->stage !== 'grade' || ! isset($s->data['score'])) {
                continue;
            }
            $round = (int) ($s->data['round'] ?? count($out));
            $out[] = ['label' => $round === 0 ? 'v1' : "sửa {$round}", 'score' => (int) $s->data['score']];
        }

        return $out;
    }

    /** @param list<array{label: string, score: int}> $timeline */
    public static function format(array $timeline): string
    {
        return implode(' → ', array_map(fn ($t) => $t['label'].' '.$t['score'], $timeline));
    }
}
