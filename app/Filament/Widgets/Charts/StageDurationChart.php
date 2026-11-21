<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Filament\Support\Dashboard\ChartQueries as Q;
use Filament\Support\RawJs;

class StageDurationChart extends BaseChart
{
    protected static ?string $heading = 'Khâu nào chạy chậm?';

    private function rows(): array
    {
        return $this->remember(fn () => array_slice(Q::stageDurations(30, now()), 0, 8));
    }

    protected function isEmpty(): bool
    {
        return $this->rows() === [];
    }

    public function getDescription(): ?string
    {
        $rows = $this->rows();
        if ($rows === []) {
            return 'Chưa có bước nào ghi đủ giờ bắt đầu và kết thúc (30 ngày qua).';
        }
        $title = self::short(AgentFlowBlueprint::byStage()[$rows[0]['stage']]['title'] ?? $rows[0]['stage']);

        return "Chậm nhất: {$title}, trung bình ".number_format($rows[0]['seconds'], 1, ',', '.').' giây mỗi bước (30 ngày, tối đa 8 khâu).';
    }

    /** Tên khâu bỏ phần chú thích trong ngoặc cho gọn nhãn trục. */
    private static function short(string $title): string
    {
        return trim((string) preg_replace('/\s*\(.*$/u', '', $title));
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->rows();
        $stages = AgentFlowBlueprint::byStage();
        $max = $rows[0]['seconds'] ?? 0;

        return [
            'labels' => array_map(fn ($r) => self::short($stages[$r['stage']]['title'] ?? $r['stage']), $rows),
            'datasets' => [[
                'label' => 'Giây trung bình', 'data' => array_column($rows, 'seconds'), 'borderWidth' => 0, 'borderRadius' => 6, 'barPercentage' => .75,
                'backgroundColor' => array_map(fn ($r) => $max > 0 && $r['seconds'] >= $max * .8 ? Q::COLOR['wait'] : Q::COLOR['info'], $rows),
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            indexAxis: 'y',
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => Number(c.parsed.x).toLocaleString('vi-VN') + ' giây' } } },
            scales: { x: { beginAtZero: true, grid: { color: 'rgba(128,128,128,.15)' } }, y: { grid: { display: false } } },
        }
        JS);
    }
}
