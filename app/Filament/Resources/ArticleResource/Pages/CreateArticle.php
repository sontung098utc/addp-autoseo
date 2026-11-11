<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    public function getTitle(): string|Htmlable
    {
        return request()->query('campaign') ? 'Tạo bài trong chiến dịch' : 'Tạo bài lẻ';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['workflow_status']);
        $data['campaign_id'] = $data['campaign_id'] ?: null; // trống = bài lẻ

        // Ghi người tạo: luật "tự duyệt phải có ghi chú + log" dựa vào created_by, nếu để trống sẽ không bao giờ kích hoạt.
        $data['created_by'] = Auth::id();

        return $data;
    }
}
