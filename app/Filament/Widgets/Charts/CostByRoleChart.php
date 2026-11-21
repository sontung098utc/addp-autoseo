<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Support\Dashboard\ChartQueries as Q;
use Filament\Support\RawJs;

class CostByRoleChart extends BaseChart
{
    protected static ?string $heading = 'Vai trò nào tốn tiền nhất?';

    public ?string $filter = '7';

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
        return $this->remember(fn () => Q::costByRole((int) ($this->filter ?: 7), now()));
    }

    protected function isEmpty(): bool
    {
        return $this->rows() === [];
    }

    public function getDescription(): ?string
    {
        $rows = $this->rows();
        if ($rows === []) {
            return 'Chưa có bước nào ghi chi phí hoặc token trong khoảng này.';
        }
        $cost = array_sum(array_column($rows, 'cost'));
        $tok = array_sum(array_column($rows, 'prompt')) + array_sum(array_column($rows, 'completion'));
        $top = $rows[0];
        $share = $cost > 0 ? ' ('.round($top['cost'] / $cost * 100).'%)' : '';

        return number_format($cost, 0, ',', '.').' ₫ và '.number_format($tok, 0, ',', '.')." token. Tốn nhất: {$top['role']}{$share}.";
    }

    protected function getData(): array
    {
        $rows = $this->rows();

        return [
            'labels' => array_column($rows, 'role'),
            'datasets' => [['label' => 'Chi phí (₫)', 'data' => array_map(fn ($r) => round($r['cost']), $rows), 'backgroundColor' => Q::COLOR['brand'], 'borderWidth' => 0, 'borderRadius' => 6, 'barPercentage' => .75]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            indexAxis: 'y',
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => Number(c.parsed.x).toLocaleString('vi-VN') + ' ₫' } } },
            scales: { x: { beginAtZero: true, grid: { color: 'rgba(128,128,128,.15)' }, ticks: { callback: (v) => Number(v).toLocaleString('vi-VN') } }, y: { grid: { display: false } } },
        }
        JS);
    }
}
