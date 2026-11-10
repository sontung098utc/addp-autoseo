<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['workflow_status']);

        return $data;
    }

    /** Lưu xong quay về trang của bài (AG-S27). */
    protected function getRedirectUrl(): ?string
    {
        return ArticleResource::getUrl('view', ['record' => $this->record]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(fn () => ArticleResource::canDeleteAny()),
        ];
    }
}
