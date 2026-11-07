<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWebsites extends ListRecords
{
    protected static ?string $title = 'Website kết nối';

    protected static string $resource = WebsiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
