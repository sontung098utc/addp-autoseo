<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Support\Dashboard\ChartQueries as Q;
use Filament\Support\RawJs;

class AiCostTrendChart extends BaseChart
{
    protected static ?string $heading = 'Chi phí AI mỗi ngày';

    public ?string $filter = '14';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isApprover();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        return ['7' => '7 ngày', '14' => '14 ngày', '30' => '30 ngày'];
    }

    private function series(): array
    {
        return $this->remember(fn () => Q::costByDay((int) ($this->filter ?: 14), now()));
    }

    protected function isEmpty(): bool
    {
        return $this->series()['total'] <= 0;
    }

    public function getDescription(): ?string
    {
        $s = $this->series();
        if ($s['total'] <= 0) {
            return 'Chưa có chi phí AI trong khoảng này.';
        }
        $avg = number_format($s['total'] / max(1, count($s['cost'])), 0, ',', '.');
        $tot = number_format($s['total'], 0, ',', '.');

        return "Tổng {$tot} ₫, trung bình {$avg} ₫ mỗi ngày".($s['cap'] > 0 ? '. Đường đứt là trần ngày.' : '. Chưa đặt trần chi phí ngày.');
    }

    protected function getData(): array
    {
        $s = $this->series();
        $sets = [[
            'label' => 'Chi phí (₫)', 'data' => $s['cost'], 'borderColor' => Q::COLOR['brand'], 'backgroundColor' => 'rgba(15,107,92,.15)',
            'fill' => true, 'tension' => .35, 'pointRadius' => 3, 'pointBackgroundColor' => Q::COLOR['brand'],
        ]];
        if ($s['cap'] > 0) {
            $sets[] = ['label' => 'Trần ngày (₫)', 'data' => array_fill(0, count($s['cost']), $s['cap']), 'borderColor' => Q::COLOR['bad'], 'borderDash' => [6, 4], 'pointRadius' => 0, 'fill' => false];
        }

        return ['labels' => $s['labels'], 'datasets' => $sets];
    }

    protected function getOptions(): array|RawJs|null
    {
        return RawJs::make(<<<'JS'
        {
            plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } },
                tooltip: { callbacks: { label: (c) => c.dataset.label.replace(' (₫)', '') + ': ' + Number(c.parsed.y).toLocaleString('vi-VN') + ' ₫' } } },
            scales: { x: { grid: { display: false } },
                y: { beginAtZero: true, grid: { color: 'rgba(128,128,128,.15)' }, ticks: { callback: (v) => Number(v).toLocaleString('vi-VN') } } },
        }
        JS);
    }
}
