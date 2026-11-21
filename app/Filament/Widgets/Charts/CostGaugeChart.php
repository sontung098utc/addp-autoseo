<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Support\Dashboard\ChartQueries as Q;
use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;

/** Đồng hồ nửa vòng: chi phí hôm nay so với tổng trần ngày của các chiến dịch đang chạy. Chưa đặt trần thì không có gì để so, nên ẩn. */
class CostGaugeChart extends BaseChart
{
    protected static ?string $heading = 'Hôm nay đã dùng bao nhiêu ngân sách?';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isApprover() && Q::dailyCap() > 0;
    }

    private function spent(): float
    {
        [$from, $to] = VnTime::todayRangeApp();

        return (float) AgentRun::query()->where('started_at', '>=', $from)->where('started_at', '<', $to)->sum('cost_vnd');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getDescription(): ?string
    {
        $cap = Q::dailyCap();
        $spent = $this->spent();
        $pct = $cap > 0 ? (int) round($spent / $cap * 100) : 0;

        return number_format($spent, 0, ',', '.').' ₫ trên '.number_format($cap, 0, ',', '.')." ₫ ({$pct}%)".($pct >= 100 ? '. Đã chạm trần ngày.' : '.');
    }

    protected function getData(): array
    {
        $cap = Q::dailyCap();
        $spent = $this->spent();
        $ratio = $cap > 0 ? $spent / $cap : 0;
        $color = $ratio >= 1 ? Q::COLOR['bad'] : ($ratio >= .8 ? Q::COLOR['wait'] : Q::COLOR['good']);

        return [
            'labels' => ['Đã dùng', 'Còn lại'],
            'datasets' => [['data' => [round($spent), max(0, round($cap - $spent))], 'backgroundColor' => [$color, 'rgba(148,163,184,.25)'], 'borderWidth' => 0, 'circumference' => 180, 'rotation' => 270]],
        ];
    }

    protected function getOptions(): array
    {
        return ['cutout' => '72%', 'plugins' => ['legend' => self::legend()], 'scales' => self::hiddenAxes()];
    }
}
