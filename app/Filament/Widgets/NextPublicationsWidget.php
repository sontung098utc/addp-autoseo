<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Dashboard\DashboardQueries;
use Filament\Widgets\Widget;

class NextPublicationsWidget extends Widget
{
    protected static string $view = 'filament.widgets.dash-next';

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected function getViewData(): array
    {
        $user = auth()->user();
        $now = now();

        return ['rows' => DashboardQueries::nextPublications($now)];
    }
}
