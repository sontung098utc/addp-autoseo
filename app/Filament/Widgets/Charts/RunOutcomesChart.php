<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Support\Dashboard\ChartQueries as Q;

class RunOutcomesChart extends BaseChart
{
    protected static ?string $heading = 'Đội AI làm có trót lọt không?';

    public ?string $filter = '7';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getFilters(): ?array
    {
        return ['7' => '7 ngày', '30' => '30 ngày'];
    }

    private function data(): array
    {
        return $this->remember(fn () => Q::runOutcomes((int) ($this->filter ?: 7), now()));
    }

    protected function isEmpty(): bool
    {
        return array_sum($this->data()['groups']) === 0;
    }

    public function getDescription(): ?string
    {
        $d = $this->data();
        $finished = array_sum($d['groups']);
        if ($finished === 0) {
            return $d['running'] > 0 ? "Chưa có phiên nào kết thúc ({$d['running']} đang chạy)." : 'Chưa có phiên chạy nào trong khoảng này.';
        }

        return "{$d['successRate']}% thành công trên {$finished} phiên đã kết thúc".($d['running'] > 0 ? ", {$d['running']} đang chạy" : '').'.';
    }

    protected function getData(): array
    {
        $d = $this->data();
        $labels = $values = $colors = [];
        foreach (Q::RUN_GROUPS as $key => $g) {
            $labels[] = $g['label'];
            $values[] = $d['groups'][$key];
            $colors[] = Q::COLOR[$g['color']];
        }

        return ['labels' => $labels, 'datasets' => [['data' => $values, 'backgroundColor' => $colors, 'borderWidth' => 2, 'borderColor' => 'transparent', 'hoverOffset' => 6]]];
    }

    protected function getOptions(): array
    {
        return ['cutout' => '62%', 'plugins' => ['legend' => self::legend('right')], 'scales' => self::hiddenAxes()];
    }
}
