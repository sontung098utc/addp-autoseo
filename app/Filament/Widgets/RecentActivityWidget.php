<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Dashboard\DashboardQueries;
use Filament\Widgets\Widget;

class RecentActivityWidget extends Widget
{
    protected static string $view = 'filament.widgets.dash-activity';

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 1;

    protected function getViewData(): array
    {
        $user = auth()->user();
        $now = now();

        return ['rows' => DashboardQueries::recentActivity()];
    }
}
