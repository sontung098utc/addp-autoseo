<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Resources\Pages\EditRecord;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    protected static ?string $title = 'Sửa chiến dịch';

    private ?string $modeBefore = null;

    protected function beforeSave(): void
    {
        $this->modeBefore = $this->record->getOriginal('mode');
    }

    /** Ghi nhật ký xác nhận chính sách khi lưu ở chế độ Toàn quyền lần đầu. */
    protected function afterSave(): void
    {
        if ($this->record->mode === 'autonomous' && $this->modeBefore !== 'autonomous') {
            CampaignResource::logPolicyAck($this->record);
        }
    }
}
