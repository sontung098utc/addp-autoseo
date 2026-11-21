<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Support\Dashboard\ChartQueries as Q;

class LifecycleChart extends BaseChart
{
    protected static ?string $heading = 'Bài đang nằm ở khâu nào?';

    protected function getType(): string
    {
        return 'bar';
    }

    private function series(): array
    {
        return $this->remember(fn () => Q::lifecycle());
    }

    protected function isEmpty(): bool
    {
        return $this->series()['total'] === 0;
    }

    public function getDescription(): ?string
    {
        $l = $this->series();
        if ($l['total'] === 0) {
            return 'Chưa có bài nào.';
        }
        $parts = [];
        $review = $l['counts'][array_search('needs_review', $l['keys'], true)];
        if ($review > 0) {
            $parts[] = "{$review} chờ duyệt";
        }
        if ($l['rejected'] > 0) {
            $parts[] = "{$l['rejected']} bị từ chối (không nằm trong biểu đồ)";
        }

        return "Tổng {$l['total']} bài. ".implode(', ', $parts);
    }

    protected function getData(): array
    {
        $l = $this->series();
        $colors = [Q::COLOR['muted'], Q::COLOR['info'], Q::COLOR['wait'], Q::COLOR['brand'], '#7c3aed', Q::COLOR['good']];

        return [
            'labels' => $l['labels'],
            'datasets' => [['label' => 'Số bài', 'data' => $l['counts'], 'backgroundColor' => $colors, 'borderWidth' => 0, 'borderRadius' => 6, 'barPercentage' => .8]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => self::countScale(), 'y' => ['grid' => ['display' => false]]],
        ];
    }
}
