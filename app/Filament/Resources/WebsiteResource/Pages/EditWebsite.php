<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditWebsite extends EditRecord
{
    protected static string $resource = WebsiteResource::class;

    // Không nạp credential đã giải mã vào form và không cho ghi đè qua trang sửa.
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['encrypted_credentials']);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['encrypted_credentials'], $data['credentials_input']);

        return $data;
    }
}
