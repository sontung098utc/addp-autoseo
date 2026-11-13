<?php

namespace App\Services\Agent\Eval;

/**
 * Q0.5: đo giám khảo AI so với NHÃN CỦA NGƯỜI (dược sĩ): độ chính xác, kappa (hiệu chỉnh theo cơ hội) và precision/recall từng loại.
 * Hàm thuần, không gọi mạng. Chỉ tin giám khảo (cho quyền chặn bài) sau khi số đo này đạt ngưỡng do chủ dự án đặt.
 */
final class JudgeCalibration
{
    /** Giám khảo trích dẫn: AI (supported/partial/unsupported) ↔ người (dung/khong_ro/sai). */
    public const CLAIM_MAP = ['supported' => 'dung', 'partial' => 'khong_ro', 'unsupported' => 'sai'];

    /** Giám khảo ý định: AI (answered/partial/evasive/missing) ↔ người (co/mot_phan/noi_vong/khong). */
    public const INTENT_MAP = ['answered' => 'co', 'partial' => 'mot_phan', 'evasive' => 'noi_vong', 'missing' => 'khong'];

    /**
     * @param  list<array{0:string,1:string}>  $pairs  [nhãn AI, nhãn người] đã quy về CÙNG bộ lớp
     * @param  list<string>  $classes
     * @return array{n:int, accuracy:?float, kappa:?float, confusion:array<string,array<string,int>>, per_class:array<string,array{precision:?float,recall:?float,support:int}>}
     */
    public static function compare(array $pairs, array $classes): array
    {
        $conf = [];
        foreach ($classes as $a) {
            foreach ($classes as $h) {
                $conf[$a][$h] = 0;
            }
        }
        $n = 0;
        foreach ($pairs as [$ai, $human]) {
            if (isset($conf[$ai][$human])) {
                $conf[$ai][$human]++;
                $n++;
            }
        }
        if ($n === 0) {
            return ['n' => 0, 'accuracy' => null, 'kappa' => null, 'confusion' => $conf, 'per_class' => []];
        }
        $agree = 0;
        $rowSum = array_fill_keys($classes, 0);
        $colSum = array_fill_keys($classes, 0);
        foreach ($classes as $a) {
            foreach ($classes as $h) {
                $rowSum[$a] += $conf[$a][$h];
                $colSum[$h] += $conf[$a][$h];
            }
            $agree += $conf[$a][$a];
        }
        $po = $agree / $n;
        $pe = 0.0;
        foreach ($classes as $c) {
            $pe += ($rowSum[$c] / $n) * ($colSum[$c] / $n);
        }
        $kappa = abs(1 - $pe) < 1e-12 ? null : round(($po - $pe) / (1 - $pe), 4);
        $per = [];
        foreach ($classes as $c) {
            $per[$c] = [
                'precision' => $rowSum[$c] === 0 ? null : round($conf[$c][$c] / $rowSum[$c], 4),
                'recall' => $colSum[$c] === 0 ? null : round($conf[$c][$c] / $colSum[$c], 4),
                'support' => $colSum[$c],
            ];
        }

        return ['n' => $n, 'accuracy' => round($po, 4), 'kappa' => $kappa, 'confusion' => $conf, 'per_class' => $per];
    }

    /**
     * Từ tệp nhãn (schema autoseo-eval-labels/1) tạo hai bộ so sánh: trích dẫn và ý định. Mục người chưa chọn bị bỏ qua.
     *
     * @param  array<string,mixed>  $labels
     * @return array{claims: array<string,mixed>, intent: array<string,mixed>}
     */
    public static function fromLabels(array $labels): array
    {
        $claims = [];
        $intent = [];
        foreach ((array) ($labels['labels'] ?? []) as $row) {
            foreach ((array) ($row['pairs'] ?? []) as $p) {
                $ai = self::CLAIM_MAP[(string) ($p['ai'] ?? '')] ?? null;
                $h = (string) ($p['human'] ?? '');
                if ($ai !== null && in_array($h, self::CLAIM_MAP, true)) {
                    $claims[] = [$ai, $h];
                }
            }
            foreach ((array) ($row['must_answer'] ?? []) as $m) {
                $ai = self::INTENT_MAP[(string) ($m['ai'] ?? '')] ?? null;
                $h = (string) ($m['human'] ?? '');
                if ($ai !== null && in_array($h, self::INTENT_MAP, true)) {
                    $intent[] = [$ai, $h];
                }
            }
        }

        return [
            'claims' => self::compare($claims, array_values(self::CLAIM_MAP)),
            'intent' => self::compare($intent, array_values(self::INTENT_MAP)),
        ];
    }
}
