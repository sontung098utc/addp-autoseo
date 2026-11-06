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

    protected static ?string $navigationLabel = 'Duyệt bài';

    protected static ?string $navigationGroup = 'Chiến dịch';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'Phiên bản bài';

    protected static ?string $pluralModelLabel = 'Phiên bản bài';

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = ArticleVersion::where('review_status', 'needs_review')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

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
        return $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('article.primary_keyword')->label('Bài / từ khóa chính')->searchable(),
                Tables\Columns\TextColumn::make('version')->label('Phiên bản')->formatStateUsing(fn ($state) => 'v' . $state)->sortable(),
                Tables\Columns\TextColumn::make('title')->toggleable()->sortable()->label('Tiêu đề')->limit(60)->searchable(),
                Tables\Columns\TextColumn::make('review_status')->sortable()->label('Trạng thái duyệt')->badge()
                    ->formatStateUsing(fn ($state) => self::reviewLabel($state))
                    ->color(fn ($state) => \App\Filament\Support\StatusPresenter::color($state, 'article')),
                Tables\Columns\TextColumn::make('tokens_used')->toggleable()->label('Token')->numeric(0, ',', '.')->sortable(),
                Tables\Columns\TextColumn::make('cost_vnd')->toggleable()->label('Chi phí')->formatStateUsing(fn ($state) => self::formatVnd($state))->sortable(),
                Tables\Columns\TextColumn::make('created_at')->toggleable()->label('Ngày tạo')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('review_status')->label('Trạng thái duyệt')
                    ->options(['needs_review' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Cần sửa']),
                Tables\Filters\SelectFilter::make('article_id')->label('Bài')
                    ->relationship('article', 'primary_keyword'),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Xem')]);
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
