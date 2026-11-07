<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCampaigns extends ListRecords
{
    protected static string $resource = CampaignResource::class;

    protected static ?string $title = 'Chiến dịch tự động';

    public function getSubheading(): ?string
    {
        return 'Đặt từ khóa và giới hạn bài mỗi ngày; hệ thống tự giao từng bài cho đội AI. Kết quả luôn là bản nháp chờ người duyệt, chưa có gì tự đăng.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Tạo chiến dịch')];
    }
}
