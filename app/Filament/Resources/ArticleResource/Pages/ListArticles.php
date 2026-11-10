<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use App\Filament\Support\Review\ReviewActions;
use App\Models\Article;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/** AG-S16: một màn Bài viết gộp cả duyệt bài, chia tab ngang theo trạng thái. */
class ListArticles extends ListRecords
{
    protected static ?string $title = 'Bài viết';

    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $counts = Article::query()->selectRaw('workflow_status, count(*) as n')->groupBy('workflow_status')->pluck('n', 'workflow_status');
        $tabs = ['all' => Tab::make('Tất cả')->badge((int) $counts->sum())];
        foreach (ArticleResource::TAB_STATUSES as $status) {
            $tabs[$status] = Tab::make(ArticleResource::TAB_LABELS[$status])
                ->badge((int) ($counts[$status] ?? 0))
                ->badgeColor($status === 'needs_review' ? 'warning' : 'gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('articles.workflow_status', $status));
        }

        return $tabs;
    }

    /** Người duyệt vào thẳng 'Chờ duyệt' khi có việc; còn lại xem 'Tất cả'. */
    public function getDefaultActiveTab(): string|int|null
    {
        if (ReviewActions::canApprove() && Article::query()->where('workflow_status', 'needs_review')->exists()) {
            return 'needs_review';
        }

        return 'all';
    }
}
