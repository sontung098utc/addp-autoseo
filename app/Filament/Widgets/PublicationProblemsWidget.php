<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Dashboard\DashboardQueries;
use Filament\Widgets\Widget;

class PublicationProblemsWidget extends Widget
{
    protected static string $view = 'filament.widgets.dash-problems';

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isApprover();
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $now = now();

        return ['rows' => $user ? DashboardQueries::publicationProblems($user) : collect(), 'visible' => (bool) $user?->isApprover()];
    }
}
