<?php

namespace Tests\Unit;

use App\Filament\Support\Demo\GscDemoData;
use App\Filament\Support\Gsc\OpportunityFinder as OF;
use App\Filament\Support\Gsc\PeriodComparer as PC;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class GscCalcTest extends TestCase
{
    private function agg(int $c, int $i, float $p): array
    {
        return ['has_data' => true, 'clicks' => $c, 'impressions' => $i, 'ctr' => $i ? $c / $i : null, 'position' => $p];
    }

    private function item(array $cur, ?array $prev = null): array
    {
        return ['key' => 'k', 'page' => null, 'cur' => $cur, 'prev' => $prev ?? ['has_data' => false, 'clicks' => 0, 'impressions' => 0, 'ctr' => null, 'position' => null]];
    }

    private function rules(array $item): array
    {
        return array_column(OF::find([$item]), 'rule');
    }

    public function test_aggregate_weighted(): void
    {
        $a = PC::aggregate([
            ['clicks' => 10, 'impressions' => 100, 'position' => 2.0],
            ['clicks' => 30, 'impressions' => 300, 'position' => 6.0],
        ]);
        $this->assertSame(40, $a['clicks']);
        $this->assertEqualsWithDelta(0.1, $a['ctr'], 1e-9);
        $this->assertEqualsWithDelta(5.0, $a['position'], 1e-9); // (200+1800)/400, không phải 4.0
    }

    public function test_aggregate_empty_is_null(): void
    {
        $a = PC::aggregate([]);
        $this->assertFalse($a['has_data']);
        $this->assertNull($a['ctr']);
        $this->assertNull($a['position']);
    }

    public function test_delta_cases(): void
    {
        $this->assertSame('new', PC::delta(5, null)['state']);
        $this->assertSame('nodata', PC::delta(null, 5)['state']);
        $d = PC::delta(5, 0);
        $this->assertNull($d['pct']);
        $this->assertSame('new', $d['state']);
        $d = PC::delta(75, 100);
        $this->assertEqualsWithDelta(-25.0, $d['pct'], 1e-9);
        $this->assertEqualsWithDelta(-25.0, $d['abs'], 1e-9);
    }

    public function test_periods_end_today_minus_3(): void
    {
        $p = PC::periods(CarbonImmutable::parse('2026-11-10'), 28);
        $this->assertSame('2026-11-07', $p['current']['to']->toDateString());
        $this->assertSame('2026-10-11', $p['current']['from']->toDateString());
        $this->assertSame('2026-10-10', $p['previous']['to']->toDateString());
        $this->assertSame('2026-09-13', $p['previous']['from']->toDateString());
    }

    public function test_compare_new_when_previous_empty(): void
    {
        $items = PC::compare([['date' => 'd', 'key' => 'a', 'page' => null, 'clicks' => 5, 'impressions' => 50, 'position' => 3.0]], []);
        $this->assertSame('new', $items[0]['d']['clicks']['state']);
    }

    public function test_page1_boundaries(): void
    {
        foreach ([[7.9, false], [8.0, true], [20.0, true], [20.1, false]] as [$pos, $hit]) {
            // CTR bình thường để không dính luật khác
            $this->assertSame($hit, in_array(OF::RULE_PAGE1, $this->rules($this->item($this->agg(20, 1000, $pos)))), "pos $pos");
        }
        $this->assertNotContains(OF::RULE_PAGE1, $this->rules($this->item($this->agg(2, 199, 10.0))));
        $this->assertContains(OF::RULE_PAGE1, $this->rules($this->item($this->agg(2, 200, 10.0))));
    }

    public function test_low_ctr_boundary_and_impressions(): void
    {
        $exp = GscDemoData::expectedCtr(3.0); // 0.10 -> ngưỡng 0.06
        $this->assertContains(OF::RULE_LOW_CTR, $this->rules($this->item($this->agg(59, 1000, 3.0))));
        $this->assertNotContains(OF::RULE_LOW_CTR, $this->rules($this->item($this->agg(60, 1000, 3.0))));
        $this->assertNotContains(OF::RULE_LOW_CTR, $this->rules($this->item($this->agg(1, 199, 3.0))));
        $this->assertSame(0.10, $exp);
    }

    public function test_drop_position_boundary(): void
    {
        $prev = $this->agg(100, 2000, 5.0);
        $this->assertContains(OF::RULE_DROP, $this->rules($this->item($this->agg(100, 2000, 8.0), $prev)));
        $this->assertNotContains(OF::RULE_DROP, $this->rules($this->item($this->agg(100, 2000, 7.9), $prev)));
    }

    public function test_drop_clicks_boundary_and_min_prev(): void
    {
        $prev = $this->agg(100, 2000, 5.0);
        $this->assertContains(OF::RULE_DROP, $this->rules($this->item($this->agg(70, 2000, 5.0), $prev)));
        $this->assertNotContains(OF::RULE_DROP, $this->rules($this->item($this->agg(71, 2000, 5.0), $prev)));
        $small = $this->agg(19, 500, 5.0);
        $this->assertNotContains(OF::RULE_DROP, $this->rules($this->item($this->agg(1, 500, 5.0), $small)));
        $ok = $this->agg(20, 500, 5.0);
        $this->assertContains(OF::RULE_DROP, $this->rules($this->item($this->agg(10, 500, 5.0), $ok)));
    }

    public function test_every_opportunity_has_numeric_reason(): void
    {
        $o = OF::find([$this->item($this->agg(2, 1000, 12.0), $this->agg(100, 2000, 5.0))]);
        $this->assertNotEmpty($o);
        foreach ($o as $x) {
            $this->assertMatchesRegularExpression('/\d/', $x['reason']);
        }
    }

    public function test_demo_data_consistent(): void
    {
        $today = CarbonImmutable::parse('2026-11-10');
        $last = GscDemoData::lastDate($today);
        $from = $last->subDays(55);
        $sum = [];
        foreach (['query', 'page', 'device', 'country'] as $dim) {
            $rows = GscDemoData::rows($dim, $from, $last, $today);
            $this->assertNotEmpty($rows);
            $c = $i = 0;
            foreach ($rows as $r) {
                $this->assertLessThanOrEqual($r['impressions'], $r['clicks']);
                $c += $r['clicks'];
                $i += $r['impressions'];
            }
            $sum[$dim] = [$c, $i];
        }
        $this->assertGreaterThanOrEqual($sum['query'][1], $sum['page'][1]);
        $this->assertGreaterThanOrEqual($sum['query'][0], $sum['page'][0]);
        $this->assertSame($sum['page'], $sum['device']);
        $this->assertSame($sum['page'], $sum['country']);
        // xác định: gọi lại ra cùng kết quả
        $this->assertSame(GscDemoData::rows('query', $from, $last, $today), GscDemoData::rows('query', $from, $last, $today));
    }
}
