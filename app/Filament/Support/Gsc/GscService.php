<?php

namespace App\Filament\Support\Gsc;

use App\Filament\Support\Demo\DemoMode;
use Carbon\CarbonImmutable;

final class GscService
{
    public const DIMENSIONS = ['query' => 'Truy vấn', 'page' => 'Trang', 'device' => 'Thiết bị', 'country' => 'Quốc gia'];

    /** Bản Demo hiện tại; ở production là nguồn rỗng. Thay bằng client Google thật mà không đổi giao diện. */
    public static function make(?CarbonImmutable $today = null): self
    {
        return new self(DemoMode::enabled() ? new GscDemoSource($today) : new GscNullSource, $today ?? CarbonImmutable::now());
    }

    public function __construct(private readonly GscSource $source, private readonly CarbonImmutable $today) {}

    /**
     * @return array{periods:array,items:array<string,list<array>>,daily_cur:array,daily_prev:array,totals:array,opportunities:list<array>,empty:bool}
     */
    public function report(int $days): array
    {
        $p = PeriodComparer::periods($this->today, $days);
        $items = [];
        $totals = null;
        $dailyCur = $dailyPrev = [];
        foreach (array_keys(self::DIMENSIONS) as $dim) {
            $cur = $this->source->rows($dim, $p['current']['from'], $p['current']['to']);
            $prev = $this->source->rows($dim, $p['previous']['from'], $p['previous']['to']);
            $items[$dim] = PeriodComparer::compare($cur, $prev);
            if ($dim === 'page') {
                $c = PeriodComparer::aggregate($cur);
                $pv = PeriodComparer::aggregate($prev);
                $totals = ['cur' => $c, 'prev' => $pv, 'd' => [
                    'clicks' => PeriodComparer::delta($c['clicks'], $pv['has_data'] ? $pv['clicks'] : null),
                    'impressions' => PeriodComparer::delta($c['impressions'], $pv['has_data'] ? $pv['impressions'] : null),
                    'ctr' => PeriodComparer::delta($c['ctr'], $pv['ctr']),
                    'position' => PeriodComparer::delta($c['position'], $pv['position']),
                ]];
                $dailyCur = PeriodComparer::daily($cur, $p['current']['from'], $p['current']['to']);
                $dailyPrev = PeriodComparer::daily($prev, $p['previous']['from'], $p['previous']['to']);
            }
        }
        $opps = array_merge(OpportunityFinder::find($items['query']), OpportunityFinder::find($items['page']));

        return [
            'periods' => $p, 'items' => $items, 'daily_cur' => $dailyCur, 'daily_prev' => $dailyPrev,
            'totals' => $totals, 'opportunities' => $opps, 'empty' => $this->source->isEmpty() || ! $totals['cur']['has_data'],
        ];
    }
}
