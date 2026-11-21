<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Support\Dashboard\ChartQueries as Q;
use Filament\Support\RawJs;

/** Chi phí theo nhà cung cấp AI. Phần bước chưa ghi provider hiện thành lát xám riêng để không ai tưởng đó là con số đủ. */
class CostByProviderChart extends BaseChart
{
    protected static ?string $heading = 'Provider nào tốn tiền nhất?';

    public ?string $filter = '7';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isApprover();
    }

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
        return $this->remember(fn () => Q::costByProvider((int) ($this->filter ?: 7), now()));
    }

    protected function isEmpty(): bool
    {
        return $this->data()['total'] <= 0;
    }

    public function getDescription(): ?string
    {
        $d = $this->data();
        if ($d['total'] <= 0) {
            return 'Chưa có bước nào ghi chi phí trong khoảng này.';
        }
        if ($d['rows'] === []) {
            return 'Chưa bước nào trong khoảng này ghi provider; số liệu sẽ có khi các phiên mới chạy.';
        }
        $top = $d['rows'][0];
        $share = round($top['cost'] / $d['total'] * 100);

        return "Tốn nhất: {$top['name']} ({$share}% tổng chi phí). Mới ghi được provider cho {$d['coverage']}% chi phí; phần còn lại hiện màu xám.";
    }

    protected function getData(): array
    {
        $d = $this->data();
        $palette = [Q::COLOR['brand'], Q::COLOR['info'], '#7c3aed', Q::COLOR['wait'], '#0891b2', '#db2777', Q::COLOR['good']];
        $labels = $values = $colors = $tokens = $steps = [];
        foreach ($d['rows'] as $i => $r) {
            $labels[] = $r['name'];
            $values[] = round($r['cost']);
            $tokens[] = $r['tokens'];
            $steps[] = $r['steps'];
            $colors[] = $palette[$i % count($palette)];
        }
        if ($d['unrecorded']['steps'] > 0) {
            $labels[] = 'Chưa ghi provider';
            $values[] = round($d['unrecorded']['cost']);
            $tokens[] = $d['unrecorded']['tokens'];
            $steps[] = $d['unrecorded']['steps'];
            $colors[] = 'rgba(148,163,184,.55)';
        }

        return ['labels' => $labels, 'datasets' => [['data' => $values, 'tokens' => $tokens, 'steps' => $steps, 'backgroundColor' => $colors, 'borderWidth' => 2, 'borderColor' => 'transparent', 'hoverOffset' => 6]]];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            cutout: '60%',
            plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8, padding: 12 } },
                tooltip: { callbacks: { label: (c) => [
                    c.label + ': ' + Number(c.parsed).toLocaleString('vi-VN') + ' ₫',
                    Number(c.dataset.tokens[c.dataIndex]).toLocaleString('vi-VN') + ' token, ' + c.dataset.steps[c.dataIndex] + ' bước'
                ] } } },
            scales: { x: { display: false }, y: { display: false } },
        }
        JS);
    }
}
