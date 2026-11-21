<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Support\Dashboard\ChartQueries as Q;

class RepairRoundsChart extends BaseChart
{
    protected static ?string $heading = 'Viết một lần có đạt không?';

    protected function getType(): string
    {
        return 'bar';
    }

    private function series(): array
    {
        return $this->remember(fn () => Q::repairRounds(30, now()));
    }

    protected function isEmpty(): bool
    {
        return $this->series()['total'] === 0;
    }

    public function getDescription(): ?string
    {
        $d = $this->series();
        if ($d['total'] === 0) {
            return 'Chưa có phiên nào kết thúc trong 30 ngày qua.';
        }
        $first = round($d['counts'][0] / $d['total'] * 100);

        return "{$first}% bài đạt ngay lần viết đầu. Trung bình {$d['avg']} vòng sửa mỗi phiên (30 ngày).";
    }

    protected function getData(): array
    {
        $d = $this->series();

        return [
            'labels' => $d['labels'],
            'datasets' => [['label' => 'Số phiên', 'data' => $d['counts'], 'borderWidth' => 0, 'borderRadius' => 6, 'backgroundColor' => [Q::COLOR['good'], Q::COLOR['info'], Q::COLOR['wait'], Q::COLOR['bad']]]],
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => ['grid' => ['display' => false]], 'y' => self::countScale()]];
    }
}
