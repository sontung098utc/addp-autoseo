<?php

namespace App\Filament\Pages;

use App\Filament\Support\Dashboard\DashboardQueries;
use App\Filament\Widgets\DecisionsWidget;
use App\Filament\Widgets\NextPublicationsWidget;
use App\Filament\Widgets\PublicationProblemsWidget;
use App\Filament\Widgets\RecentActivityWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Tổng quan';

    protected static ?int $navigationSort = -100;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    public function getTitle(): string
    {
        $user = auth()->user();

        return $user ? DashboardQueries::heading($user, now()) : 'Tổng quan';
    }

    public function getHeading(): string
    {
        return $this->getTitle();
    }

    public function getSubheading(): ?string
    {
        $user = auth()->user();

        return $user ? 'Tổng quan · ' . $user->name : null;
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }

    public function getWidgets(): array
    {
        return [
            DecisionsWidget::class,
            PublicationProblemsWidget::class,
            NextPublicationsWidget::class,
            RecentActivityWidget::class,
        ];
    }
}
