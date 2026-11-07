<?php

namespace App\Filament\Resources\PublicationResource\Pages;

use App\Filament\Resources\PublicationResource;
use App\Filament\Support\Explain\WhyAction;
use App\Filament\Support\Schedule\ReconcileAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPublication extends ViewRecord
{
    protected static string $resource = PublicationResource::class;

    protected static string $view = 'filament.publication.view';

    protected function getHeaderActions(): array
    {
        return [WhyAction::page(), ReconcileAction::page(), \App\Filament\Support\Schedule\RescheduleAction::page()];
    }
}
