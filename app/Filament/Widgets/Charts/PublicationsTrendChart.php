<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Support\Dashboard\ChartQueries as Q;

class PublicationsTrendChart extends BaseChart
{
    protected static ?string $heading = 'Nhịp đăng bài';

    public ?string $filter = '14';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getFilters(): ?array
    {
        return ['7' => '7 ngày', '14' => '14 ngày', '30' => '30 ngày'];
    }

    private function series(): array
    {
        return $this->remember(fn () => Q::publicationsByDay((int) ($this->filter ?: 14), now()));
    }

    protected function isEmpty(): bool
    {
        return $this->series()['total'] === 0;
    }

    public function getDescription(): ?string
    {
        $s = $this->series();
        if ($s['total'] === 0) {
            return 'Chưa có lượt đăng nào trong khoảng này.';
        }
        $ok = array_sum($s['published']);
        $bad = array_sum($s['failed']) + array_sum($s['reconcile']);

        return "{$ok} đã đăng".($bad > 0 ? ", {$bad} lỗi hoặc chờ đối soát" : ', không có lỗi').'. Mỗi cột là một ngày.';
    }

    protected function getData(): array
    {
        $s = $this->series();

        return [
            'labels' => $s['labels'],
            'datasets' => [
                ['label' => 'Đã đăng', 'data' => $s['published'], 'backgroundColor' => Q::COLOR['good'], 'borderWidth' => 0, 'borderRadius' => 4],
                ['label' => 'Cần đối soát', 'data' => $s['reconcile'], 'backgroundColor' => Q::COLOR['wait'], 'borderWidth' => 0, 'borderRadius' => 4],
                ['label' => 'Lỗi', 'data' => $s['failed'], 'backgroundColor' => Q::COLOR['bad'], 'borderWidth' => 0, 'borderRadius' => 4],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => self::legend()],
            'scales' => ['x' => ['stacked' => true, 'grid' => ['display' => false]], 'y' => self::countScale(true)],
        ];
    }
}
