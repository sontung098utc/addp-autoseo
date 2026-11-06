<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Dashboard\DashboardQueries;
use Filament\Widgets\Widget;

class DecisionsWidget extends Widget
{
    protected static string $view = 'filament.widgets.dash-decisions';

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $user = auth()->user();
        $now = now();

        return ['rows' => $user ? DashboardQueries::decisions($user, $now) : collect(), 'isApprover' => (bool) $user?->isApprover(), 'overdueHours' => DashboardQueries::OVERDUE_AFTER_HOURS];
    }
}
