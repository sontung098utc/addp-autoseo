<?php

namespace App\Filament\Resources\ArticleVersionResource\Pages;

use App\Filament\Resources\ArticleVersionResource;
use Filament\Resources\Pages\ListRecords;

class ListArticleVersions extends ListRecords
{
    protected static ?string $title = 'Duyệt bài';

    protected static string $resource = ArticleVersionResource::class;
}
