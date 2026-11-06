<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Resources\WebsiteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWebsites extends ListRecords
{
    protected static string $resource = WebsiteResource::class;

    public function getSubheading(): ?string
    {
        $n = \App\Models\Website::where('status', 'error')->count();

        return $n > 0 ? "Cảnh báo: có {$n} website đang ở trạng thái Lỗi kết nối, cần kiểm tra." : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
