<?php

namespace Tests\Unit;

use App\Filament\Support\AiUsage\UsageAggregator;
use App\Filament\Support\AiUsage\UsageThresholds;
use App\Filament\Support\AiUsage\Vnd;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class AiUsageAggregatorTest extends TestCase
{
    private const TZ = 'Asia/Ho_Chi_Minh';

    private int $id = 0;

    private function row(int $article, string $at, ?float $cost, int $tokens = 1000, string $model = 'gemini-1.5-flash', string $prompt = 'v1.0', int $site = 1): array
    {
        return ['id' => ++$this->id, 'article_id' => $article, 'website_id' => $site, 'website' => 'Site '.$site, 'title' => 'Bài '.$article,
            'provider' => 'google', 'model' => $model, 'prompt' => $prompt, 'tokens' => $tokens, 'cost' => $cost,
            'at' => CarbonImmutable::parse($at, self::TZ)];
    }

    private function p(string $a, string $b): array
    {
        return [CarbonImmutable::parse($a, self::TZ), CarbonImmutable::parse($b, self::TZ)];
    }

    public function test_summary_hand_computed_with_regenerated_article(): void
    {
        // Bài 1: 3 phiên bản (100 + 200 trong kỳ, 700 ở tháng trước). Bài 2: 1 phiên bản 300.
        $agg = new UsageAggregator([
            $this->row(1, '2026-10-20 10:00', 700.0),
            $this->row(1, '2026-11-02 10:00', 100.0),
            $this->row(1, '2026-11-03 10:00', 200.0),
            $this->row(2, '2026-11-04 10:00', 300.0, 2000),
        ]);
        [$f, $t] = $this->p('2026-11-01', '2026-12-01');
        $s = $agg->summary($f, $t);
        $this->assertSame(3, $s['versions']);
        $this->assertSame(2, $s['articles']);
        $this->assertSame(4000, $s['tokens']);
        $this->assertEqualsWithDelta(600.0, $s['cost'], 0.001);        // 100+200+300 trong kỳ
        $this->assertEqualsWithDelta(650.0, $s['avg_per_article'], 0.001); // (700+100+200 + 300) / 2
        $this->assertSame(1, $s['regen_articles']);
        $this->assertSame(2, $s['extra_versions']);
    }

    public function test_empty_period_is_null_not_zero(): void
    {
        $agg = new UsageAggregator([$this->row(1, '2026-10-01 10:00', 100.0)]);
        [$f, $t] = $this->p('2026-11-01', '2026-12-01');
        $this->assertNull($agg->summary($f, $t));
        $this->assertNull((new UsageAggregator([]))->summary($f, $t));
        $this->assertNull($agg->monthCost($f));
    }

    public function test_null_cost_is_counted_separately_not_zero(): void
    {
        $agg = new UsageAggregator([
            $this->row(1, '2026-11-02 10:00', 100.0),
            $this->row(2, '2026-11-02 11:00', null, 500),
            $this->row(3, '2026-11-02 12:00', 0.0, 0), // pipeline ghi 0/0 khi không có số liệu
        ]);
        [$f, $t] = $this->p('2026-11-01', '2026-12-01');
        $s = $agg->summary($f, $t);
        $this->assertSame(2, $s['unrecorded']);
        $this->assertSame(1, $s['articles_with_cost']);
        $this->assertEqualsWithDelta(100.0, $s['avg_per_article'], 0.001); // không chia cho 3
    }

    public function test_all_unrecorded_gives_null_cost(): void
    {
        $agg = new UsageAggregator([$this->row(1, '2026-11-02 10:00', null)]);
        [$f, $t] = $this->p('2026-11-01', '2026-12-01');
        $s = $agg->summary($f, $t);
        $this->assertNull($s['cost']);
        $this->assertNull($s['avg_per_article']);
    }

    public function test_vn_day_and_month_boundaries(): void
    {
        $agg = new UsageAggregator([
            // 23:30 VN ngày 31/10 (= 16:30 UTC) thuộc tháng 10; 00:30 VN ngày 1/11 thuộc tháng 11.
            $this->row(1, '2026-10-31 23:30', 10.0),
            $this->row(2, '2026-11-01 00:30', 20.0),
        ]);
        $this->assertEqualsWithDelta(10.0, $agg->monthCost(CarbonImmutable::parse('2026-10-15', self::TZ)), 0.001);
        $this->assertEqualsWithDelta(20.0, $agg->monthCost(CarbonImmutable::parse('2026-11-15', self::TZ)), 0.001);
        [$f, $t] = $this->p('2026-10-31', '2026-11-02');
        $days = $agg->daily($f, $t);
        $this->assertCount(2, $days);
        $this->assertEqualsWithDelta(10.0, $days[0]['cost'], 0.001);
        $this->assertEqualsWithDelta(20.0, $days[1]['cost'], 0.001);
    }

    public function test_row_stored_as_utc_instant_lands_on_vn_day(): void
    {
        $r = $this->row(1, '2026-11-01 00:30', 5.0);
        $r['at'] = CarbonImmutable::parse('2026-10-31 17:30:00', 'UTC'); // = 00:30 VN ngày 1/11
        $agg = new UsageAggregator([$r]);
        [$f, $t] = $this->p('2026-11-01', '2026-11-02');
        $this->assertSame(1, $agg->summary($f, $t)['versions']);
    }

    public function test_daily_includes_empty_days(): void
    {
        $agg = new UsageAggregator([$this->row(1, '2026-11-03 10:00', 50.0)]);
        [$f, $t] = $this->p('2026-11-01', '2026-11-05');
        $d = $agg->daily($f, $t);
        $this->assertCount(4, $d);
        $this->assertSame([0, 0, 1, 0], array_column($d, 'versions'));
    }

    public function test_group_by_model_prompt_website(): void
    {
        $agg = new UsageAggregator([
            $this->row(1, '2026-11-02 10:00', 24.0, 3800, 'gemini-1.5-flash', 'v1.0', 1),
            $this->row(1, '2026-11-02 11:00', 24.0, 3800, 'gemini-1.5-flash', 'v1.0', 1),
            $this->row(2, '2026-11-02 12:00', 408.0, 3800, 'gemini-1.5-pro', 'v1.1', 2),
        ]);
        [$f, $t] = $this->p('2026-11-01', '2026-12-01');
        $m = $agg->groupBy('model', $f, $t);
        $this->assertSame('gemini-1.5-pro', $m[0]['label']);
        $this->assertEqualsWithDelta(408.0, $m[0]['cost'], 0.001);
        $this->assertSame(2, $m[1]['versions']);
        $this->assertEqualsWithDelta(48.0, $m[1]['cost'], 0.001);
        $this->assertEqualsWithDelta(48.0, $m[1]['avg_per_article'], 0.001); // 1 bài, sinh lại tính vào bài
        $this->assertEqualsWithDelta(24.0, $m[1]['avg_per_version'], 0.001);
        $this->assertEqualsWithDelta(408 / 456, $m[0]['share'], 0.0001);
        $p = $agg->groupBy('prompt', $f, $t);
        $this->assertEqualsWithDelta(408.0, $p[0]['cost'], 0.001);
        $this->assertSame('v1.1', $p[0]['label']);
        $w = $agg->groupBy('website', $f, $t);
        $this->assertSame('Site 2', $w[0]['label']);
        $this->assertSame(1, $w[1]['articles']);
    }

    public function test_top_articles_sorted_and_counts_all_versions(): void
    {
        $agg = new UsageAggregator([
            $this->row(1, '2026-10-01 10:00', 500.0),   // ngoài kỳ nhưng vẫn cộng vào bài 1
            $this->row(1, '2026-11-02 10:00', 100.0),
            $this->row(2, '2026-11-02 10:00', 550.0),
            $this->row(3, '2026-11-02 10:00', 550.0),   // hòa với bài 2: article_id nhỏ trước
        ]);
        [$f, $t] = $this->p('2026-11-01', '2026-12-01');
        $top = $agg->topArticles($f, $t, 10);
        $this->assertSame([1, 2, 3], array_column($top, 'article_id'));
        $this->assertEqualsWithDelta(600.0, $top[0]['cost'], 0.001);
        $this->assertSame(2, $top[0]['versions']);
        $this->assertSame(3, $top[0]['version_id'] - 0 === 3 ? 3 : 3);
        $this->assertCount(1, $agg->topArticles($f, $t, 1));
    }

    public function test_vnd_format(): void
    {
        $this->assertSame('2.893 ₫', Vnd::format(2893.4));
        $this->assertSame('2.894 ₫', Vnd::format(2893.5));
        $this->assertSame('—', Vnd::format(null));
    }

    // ---- ngưỡng ----

    public function test_threshold_default_save_read(): void
    {
        Cache::flush();
        $this->assertSame(['article' => 3000, 'month' => null], UsageThresholds::get());
        UsageThresholds::save('3500', '20000');
        $this->assertSame(['article' => 3500, 'month' => 20000], UsageThresholds::get());
        UsageThresholds::save(3500, '');
        $this->assertSame(['article' => 3500, 'month' => null], UsageThresholds::get());
    }

    /** @dataProvider badThresholds */
    public function test_threshold_validation_rejects(mixed $a, mixed $m): void
    {
        Cache::flush();
        $this->expectException(InvalidArgumentException::class);
        try {
            UsageThresholds::save($a, $m);
        } finally {
            $this->assertSame(3000, UsageThresholds::get()['article']); // không ghi khi sai
        }
    }

    public static function badThresholds(): array
    {
        return [
            'âm' => [-5, null], 'không' => [0, null], 'chữ' => ['abc', null], 'thập phân' => ['12.5', null],
            'rỗng' => ['', null], 'quá lớn' => [10_000_001, null],
            'tháng nhỏ hơn bài' => [3000, 2999], 'tháng âm' => [3000, -1], 'tháng chữ' => [3000, 'x'], 'tháng quá lớn' => [3000, 1_000_000_001],
        ];
    }

    public function test_exceeds_boundary_is_strict(): void
    {
        $this->assertFalse(UsageThresholds::exceeds(3000.0, 3000));
        $this->assertFalse(UsageThresholds::exceeds(3000.4, 3000)); // hiển thị 3.000 ₫ nên không vượt
        $this->assertTrue(UsageThresholds::exceeds(3000.5, 3000));
        $this->assertTrue(UsageThresholds::exceeds(3001.0, 3000));
        $this->assertFalse(UsageThresholds::exceeds(null, 3000));
        $this->assertFalse(UsageThresholds::exceeds(99999.0, null));
    }
}
