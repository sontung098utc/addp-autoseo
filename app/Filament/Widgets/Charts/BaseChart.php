<?php

namespace App\Filament\Widgets\Charts;

use Filament\Widgets\ChartWidget;

/** Nền chung cho biểu đồ Tổng quan: không tự làm mới, tải ngay, cùng kiểu chú giải và trục số nguyên. */
abstract class BaseChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    protected static ?string $maxHeight = '260px';

    /** Kết quả truy vấn giữ trong một lần dựng (mô tả, dữ liệu và kiểm tra rỗng dùng chung), tách theo bộ lọc. */
    private array $memo = [];

    protected function remember(callable $load): mixed
    {
        return $this->memo[(string) $this->filter] ??= $load();
    }

    /** Biểu đồ chưa có dữ liệu thì thu thấp lại: câu giải thích ở trên đã đủ nói "chưa có", khỏi chiếm chỗ bằng trục 0–1 trống. */
    protected function isEmpty(): bool
    {
        return false;
    }

    protected function getMaxHeight(): ?string
    {
        return $this->isEmpty() ? '90px' : static::$maxHeight;
    }

    protected static function legend(string $position = 'bottom'): array
    {
        return ['position' => $position, 'labels' => ['usePointStyle' => true, 'boxWidth' => 8, 'padding' => 14]];
    }

    /** Trục tung đếm số nguyên bắt đầu từ 0, lưới nhạt. */
    protected static function countScale(bool $stacked = false): array
    {
        return ['beginAtZero' => true, 'stacked' => $stacked, 'ticks' => ['precision' => 0], 'grid' => ['color' => 'rgba(128,128,128,.15)']];
    }

    protected static function hiddenAxes(): array
    {
        return ['x' => ['display' => false], 'y' => ['display' => false]];
    }
}
