<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateWebsite extends CreateRecord
{
    protected static string $resource = WebsiteResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $credentials = array_filter($data['credentials_input'] ?? [], fn ($v) => filled($v));
        unset($data['credentials_input'], $data['encrypted_credentials']);
        $data['encrypted_credentials'] = $credentials;

        return $data;
    }
}
