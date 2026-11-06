<?php

namespace App\Filament\Support\Gsc;

use App\Filament\Support\Demo\GscDemoData;

/** Ba luật cơ hội có tên và hằng số. Mỗi cơ hội kèm LÝ DO bằng số liệu. */
final class OpportunityFinder
{
    public const PAGE1_MIN_POS = 8.0;

    public const PAGE1_MAX_POS = 20.0;

    public const MIN_IMPRESSIONS = 200;

    /** CTR thấp = dưới 60% CTR kỳ vọng theo vị trí (bảng ước lượng cố định, không phải số đo của website). */
    public const LOW_CTR_RATIO = 0.6;

    public const DROP_POSITION = 3.0;

    public const DROP_CLICKS_PCT = 30;

    public const DROP_MIN_PREV_CLICKS = 20;

    public const RULE_PAGE1 = 'co-hoi-trang-1';

    public const RULE_LOW_CTR = 'ctr-thap';

    public const RULE_DROP = 'tut-hang';

    public const LABELS = [
        self::RULE_PAGE1 => 'Cơ hội lên trang 1',
        self::RULE_LOW_CTR => 'CTR thấp so với vị trí',
        self::RULE_DROP => 'Tụt hạng',
    ];

    /**
     * @param  list<array<string,mixed>>  $items  kết quả PeriodComparer::compare
     * @return list<array{rule:string,label:string,key:string,reason:string}>
     */
    public static function find(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $c = $it['cur'];
            $p = $it['prev'];
            $key = $it['key'];
            if ($c['impressions'] <= 0 || $c['position'] === null) {
                continue;
            }
            $pos = round($c['position'], 2);
            if ($c['impressions'] >= self::MIN_IMPRESSIONS && $pos >= self::PAGE1_MIN_POS && $pos <= self::PAGE1_MAX_POS) {
                $out[] = self::make(self::RULE_PAGE1, $key, sprintf('Vị trí trung bình %s (trong khoảng %d–%d) với %s impressions (từ %d).', self::n($pos, 1), self::PAGE1_MIN_POS, self::PAGE1_MAX_POS, self::n($c['impressions']), self::MIN_IMPRESSIONS));
            }
            if ($c['impressions'] >= self::MIN_IMPRESSIONS) {
                $exp = GscDemoData::expectedCtr($pos);
                if ($c['ctr'] < self::LOW_CTR_RATIO * $exp) {
                    $out[] = self::make(self::RULE_LOW_CTR, $key, sprintf('CTR %s%% thấp hơn %d%% CTR kỳ vọng %s%% ở vị trí %s (ước lượng) với %s impressions.', self::n($c['ctr'] * 100, 2), self::LOW_CTR_RATIO * 100, self::n($exp * 100, 1), self::n($pos, 1), self::n($c['impressions'])));
                }
            }
            if ($p['has_data'] && $p['clicks'] >= self::DROP_MIN_PREV_CLICKS) {
                $worse = $p['position'] !== null ? round($c['position'] - $p['position'], 2) : 0.0;
                $reasons = [];
                if ($worse >= self::DROP_POSITION) {
                    $reasons[] = sprintf('vị trí xấu đi %s bậc (%s → %s)', self::n($worse, 1), self::n($p['position'], 1), self::n($c['position'], 1));
                }
                if ($c['clicks'] * 10 <= $p['clicks'] * 7) {
                    $reasons[] = sprintf('clicks giảm %s%% (%s → %s)', self::n(($p['clicks'] - $c['clicks']) / $p['clicks'] * 100, 1), self::n($p['clicks']), self::n($c['clicks']));
                }
                if ($reasons !== []) {
                    $out[] = self::make(self::RULE_DROP, $key, ucfirst(implode('; ', $reasons)).sprintf('. Kỳ trước có %s clicks (cần từ %d).', self::n($p['clicks']), self::DROP_MIN_PREV_CLICKS));
                }
            }
        }

        return $out;
    }

    private static function make(string $rule, string $key, string $reason): array
    {
        return ['rule' => $rule, 'label' => self::LABELS[$rule], 'key' => $key, 'reason' => $reason];
    }

    public static function n(float|int $v, int $dec = 0): string
    {
        return number_format($v, $dec, ',', '.');
    }
}
