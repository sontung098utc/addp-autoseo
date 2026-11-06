<?php

namespace App\Filament\Support\Gsc;

use App\Filament\Support\Demo\GscDemoData;
use Carbon\CarbonImmutable;

/**
 * So sánh hai kỳ. CTR = tổng clicks / tổng impressions (KHÔNG trung bình cộng CTR); vị trí trung bình có
 * trọng số theo impressions; chia cho 0 -> null (giao diện ghi "Mới"/"Chưa có dữ liệu", không số 0 giả).
 */
final class PeriodComparer
{
    /**
     * Kỳ gần nhất kết thúc ở (hôm nay - 3 ngày) vì Search Console trễ 2-3 ngày; kỳ trước liền kề cùng độ dài.
     *
     * @return array{current:array{from:CarbonImmutable,to:CarbonImmutable},previous:array{from:CarbonImmutable,to:CarbonImmutable}}
     */
    public static function periods(CarbonImmutable $today, int $days = 28): array
    {
        $end = GscDemoData::lastDate($today);
        $curFrom = $end->subDays($days - 1);

        return [
            'current' => ['from' => $curFrom, 'to' => $end],
            'previous' => ['from' => $curFrom->subDays($days), 'to' => $curFrom->subDay()],
        ];
    }

    /**
     * @param  list<array{clicks:int,impressions:int,position:float}>  $rows
     * @return array{has_data:bool,clicks:int,impressions:int,ctr:?float,position:?float}
     */
    public static function aggregate(array $rows): array
    {
        $c = 0;
        $i = 0;
        $pw = 0.0;
        foreach ($rows as $r) {
            $c += $r['clicks'];
            $i += $r['impressions'];
            $pw += $r['position'] * $r['impressions'];
        }

        return [
            'has_data' => $rows !== [],
            'clicks' => $c,
            'impressions' => $i,
            'ctr' => $i > 0 ? $c / $i : null,
            'position' => $i > 0 ? $pw / $i : null,
        ];
    }

    /**
     * Chuỗi hàng ngày trong [from,to]; ngày thiếu dữ liệu -> clicks null (không điền 0).
     *
     * @return list<array{date:string,clicks:?int,impressions:?int}>
     */
    public static function daily(array $rows, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $by = [];
        foreach ($rows as $r) {
            $by[$r['date']]['clicks'] = ($by[$r['date']]['clicks'] ?? 0) + $r['clicks'];
            $by[$r['date']]['impressions'] = ($by[$r['date']]['impressions'] ?? 0) + $r['impressions'];
        }
        $out = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $k = $d->toDateString();
            $out[] = ['date' => $k, 'clicks' => $by[$k]['clicks'] ?? null, 'impressions' => $by[$k]['impressions'] ?? null];
        }

        return $out;
    }

    /**
     * @return array{state:'ok'|'new'|'nodata',abs:?float,pct:?float}
     */
    public static function delta(?float $cur, ?float $prev): array
    {
        if ($cur === null) {
            return ['state' => 'nodata', 'abs' => null, 'pct' => null];
        }
        if ($prev === null) {
            return ['state' => 'new', 'abs' => null, 'pct' => null];
        }
        $abs = $cur - $prev;
        if ($prev == 0.0) {
            return ['state' => $cur > 0 ? 'new' : 'ok', 'abs' => $abs, 'pct' => null];
        }

        return ['state' => 'ok', 'abs' => $abs, 'pct' => $abs / $prev * 100];
    }

    /**
     * Gom theo khóa rồi so hai kỳ. Mỗi mục: key, page, cur, prev (aggregate) và d (delta các chỉ số).
     *
     * @return list<array<string,mixed>>
     */
    public static function compare(array $curRows, array $prevRows): array
    {
        $group = function (array $rows): array {
            $g = [];
            foreach ($rows as $r) {
                $g[$r['key']][] = $r;
            }

            return $g;
        };
        $cur = $group($curRows);
        $prev = $group($prevRows);
        $items = [];
        foreach ($cur as $key => $rows) {
            $c = self::aggregate($rows);
            $p = self::aggregate($prev[$key] ?? []);
            $pp = $p['has_data'] ? $p : null;
            $items[] = [
                'key' => (string) $key,
                'page' => $rows[0]['page'] ?? null,
                'cur' => $c,
                'prev' => $p,
                'd' => [
                    'clicks' => self::delta($c['clicks'], $pp['clicks'] ?? null),
                    'impressions' => self::delta($c['impressions'], $pp['impressions'] ?? null),
                    'ctr' => self::delta($c['ctr'], $pp['ctr'] ?? null),
                    'position' => self::delta($c['position'], $pp['position'] ?? null),
                ],
            ];
        }

        return $items;
    }
}
