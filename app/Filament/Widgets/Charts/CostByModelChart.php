<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Support\Dashboard\ChartQueries as Q;
use Filament\Support\RawJs;

class CostByModelChart extends BaseChart
{
    protected static ?string $heading = 'Model nào tốn tiền nhất?';

    public ?string $filter = '7';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isApprover();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getFilters(): ?array
    {
        return ['7' => '7 ngày', '30' => '30 ngày'];
    }

    private function rows(): array
    {
        return $this->remember(fn () => Q::costByModel((int) ($this->filter ?: 7), now()));
    }

    protected function isEmpty(): bool
    {
        return $this->rows() === [];
    }

    protected function getMaxHeight(): ?string
    {
        // Mỗi model một thanh: thêm model thì cao thêm, không dồn chữ.
        return $this->isEmpty() ? '90px' : max(200, 44 * count($this->rows()) + 40).'px';
    }

    public function getDescription(): ?string
    {
        $rows = $this->rows();
        if ($rows === []) {
            return 'Chưa có bước nào ghi chi phí hoặc token trong khoảng này.';
        }
        $cost = array_sum(array_column($rows, 'cost'));
        $top = $rows[0];
        $share = $cost > 0 ? ' ('.round($top['cost'] / $cost * 100).'%)' : '';

        return count($rows).' model, tổng '.number_format($cost, 0, ',', '.')." ₫. Tốn nhất: {$top['model']}{$share}.";
    }

    protected function getData(): array
    {
        $rows = $this->rows();

        return [
            'labels' => array_column($rows, 'model'),
            'datasets' => [[
                'label' => 'Chi phí (₫)', 'data' => array_map(fn ($r) => round($r['cost']), $rows), 'tokens' => array_column($rows, 'tokens'), 'steps' => array_column($rows, 'steps'),
                'backgroundColor' => Q::COLOR['info'], 'borderWidth' => 0, 'borderRadius' => 6, 'barPercentage' => .7,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            indexAxis: 'y',
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => [
                Number(c.parsed.x).toLocaleString('vi-VN') + ' ₫',
                Number(c.dataset.tokens[c.dataIndex]).toLocaleString('vi-VN') + ' token, ' + c.dataset.steps[c.dataIndex] + ' bước'
            ] } } },
            scales: { x: { beginAtZero: true, grid: { color: 'rgba(128,128,128,.15)' }, ticks: { callback: (v) => Number(v).toLocaleString('vi-VN') } }, y: { grid: { display: false }, ticks: { callback: function (v) { const l = String(this.getLabelForValue(v)); return l.length > 24 ? l.slice(0, 23) + '…' : l; } } } },
        }
        JS);
    }
}
