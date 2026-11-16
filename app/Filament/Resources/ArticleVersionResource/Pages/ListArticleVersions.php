<?php

namespace App\Filament\Resources\ArticleVersionResource\Pages;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleVersionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * AG-S16: "Duyệt bài" đã gộp vào màn Bài viết. Giữ đường dẫn cũ /admin/article-versions
 * (liên kết, bookmark) bằng cách chuyển sang đúng tab; bộ lọc cũ review_status cũng được hiểu.
 */
class ListArticleVersions extends ListRecords
{
    protected static ?string $title = 'Bài viết';

    protected static string $resource = ArticleVersionResource::class;

    public function mount(): void
    {
        $status = request()->input('tableFilters.review_status.value');
        $tab = in_array($status, ['needs_review', 'approved', 'rejected'], true) ? $status : null;

        $this->redirect(ArticleResource::getUrl('index', $tab ? ['activeTab' => $tab] : []));
    }
}
