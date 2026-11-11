<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use App\Filament\Support\Review\ReviewActions;
use App\Models\Article;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/** AG-S16: một màn Bài viết gộp cả duyệt bài, chia tab ngang theo trạng thái. */
class ListArticles extends ListRecords
{
    protected static ?string $title = 'Bài viết';

    protected static string $resource = ArticleResource::class;

    public const CONCEPT = 'Chiến dịch gom các bài cùng mục tiêu, lịch và quy tắc; bài lẻ là bài không thuộc chiến dịch nào. Muốn thêm bài vào chiến dịch, mở chiến dịch đó rồi bấm "Thêm bài".';

    public function getHeading(): string|Htmlable
    {
        return new HtmlString(e(static::$title).' '.Blade::render('<x-help :text="$t" />', ['t' => self::CONCEPT]));
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Tạo bài lẻ')->tooltip('Bài không thuộc chiến dịch nào'),
        ];
    }

    public function getTabs(): array
    {
        $counts = Article::query()->selectRaw('workflow_status, count(*) as n')->groupBy('workflow_status')->pluck('n', 'workflow_status');
        $tabs = ['all' => Tab::make('Tất cả')->badge((int) $counts->sum())];
        foreach (ArticleResource::TAB_STATUSES as $status) {
            $tabs[$status] = Tab::make(ArticleResource::TAB_LABELS[$status])
                ->badge(((int) ($counts[$status] ?? 0)) ?: null)
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
