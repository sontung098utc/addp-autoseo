<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleVersionResource\Pages;
use App\Models\ArticleVersion;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ArticleVersionResource extends Resource
{
    protected static ?string $model = ArticleVersion::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'Bài viết';

    protected static ?string $modelLabel = 'Phiên bản bài';

    protected static ?string $pluralModelLabel = 'Phiên bản bài';

    /** AG-S16: không còn mục menu riêng; việc duyệt nằm ở màn Bài viết. Resource giữ lại để xem chi tiết bản bài. */
    protected static bool $shouldRegisterNavigation = false;

    public static function reviewLabel(?string $status): string
    {
        return \App\Filament\Support\StatusPresenter::label($status, 'article');
    }

    public static function formatVnd($value): string
    {
        return number_format((float) $value, 0, ',', '.') . ' ₫';
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return (bool) ($user && $user->is_active && $user->isWriter());
    }

    public static function canView($record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([])->paginated(false);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            ViewEntry::make('review')->hiddenLabel()->view('filament.article-version.review')->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticleVersions::route('/'),
            'view' => Pages\ViewArticleVersion::route('/{record}'),
        ];
    }
}
