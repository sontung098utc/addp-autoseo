<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['workflow_status']);

        // Ghi người tạo: luật "tự duyệt phải có ghi chú + log" dựa vào created_by, nếu để trống sẽ không bao giờ kích hoạt.
        $data['created_by'] = \Illuminate\Support\Facades\Auth::id();

        return $data;
    }
}
