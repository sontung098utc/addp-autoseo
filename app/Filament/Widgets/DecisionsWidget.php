<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Dashboard\DashboardQueries;
use Filament\Widgets\Widget;

class DecisionsWidget extends Widget
{
    /** Số dòng tối đa hiện trên Tổng quan; còn lại xem ở trang đầy đủ. */
    public const LIMIT = 10;

    protected static string $view = 'filament.widgets.dash-decisions';

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $user = auth()->user();
        $now = now();

        $all = $user ? DashboardQueries::decisions($user, $now) : collect();

        return [
            'rows' => $all->take(self::LIMIT),
            'total' => $all->count(),
            'limit' => self::LIMIT,
            'isApprover' => (bool) $user?->isApprover(),
            'overdueHours' => DashboardQueries::OVERDUE_AFTER_HOURS,
        ];
    }
}
