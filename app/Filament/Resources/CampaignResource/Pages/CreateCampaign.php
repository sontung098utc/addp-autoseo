<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    protected static ?string $title = 'Tạo chiến dịch';

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }

    /** Ghi nhật ký xác nhận chính sách khi lưu ở chế độ Toàn quyền. */
    protected function afterCreate(): void
    {
        if ($this->record->mode === 'autonomous') {
            CampaignResource::logPolicyAck($this->record);
        }
    }
}
