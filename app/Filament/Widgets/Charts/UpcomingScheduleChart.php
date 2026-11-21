<?php

namespace App\Filament\Widgets\Charts;

use App\Filament\Support\Dashboard\ChartQueries as Q;

class UpcomingScheduleChart extends BaseChart
{
    protected static ?string $heading = '7 ngày tới có bao nhiêu bài lên lịch?';

    protected function getType(): string
    {
        return 'bar';
    }

    private function series(): array
    {
        return $this->remember(fn () => Q::upcomingSchedule(7, now()));
    }

    protected function isEmpty(): bool
    {
        return $this->series()['total'] === 0;
    }

    public function getDescription(): ?string
    {
        $s = $this->series();
        if ($s['total'] === 0) {
            return 'Chưa có bài nào được xếp lịch trong 7 ngày tới.';
        }
        $empty = count(array_filter($s['counts'], fn ($n) => $n === 0));

        return "{$s['total']} bài đã xếp lịch".($empty > 0 ? ", {$empty} ngày còn trống." : ', ngày nào cũng có bài.');
    }

    protected function getData(): array
    {
        $s = $this->series();

        return [
            'labels' => $s['labels'],
            'datasets' => [['label' => 'Bài đã xếp lịch', 'data' => $s['counts'], 'backgroundColor' => Q::COLOR['info'], 'borderWidth' => 0, 'borderRadius' => 6]],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['grid' => ['display' => false]], 'y' => self::countScale()],
        ];
    }
}
