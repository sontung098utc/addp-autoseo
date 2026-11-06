<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleVersionResource\Pages;
use App\Models\ArticleVersion;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ArticleVersionResource extends Resource
{
    protected static ?string $model = ArticleVersion::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationLabel = 'Phiên bản bài';

    protected static ?string $modelLabel = 'Phiên bản bài';

    protected static ?string $pluralModelLabel = 'Phiên bản bài';

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
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('article.primary_keyword')->label('Bài / từ khóa chính')->searchable(),
                Tables\Columns\TextColumn::make('version')->label('Phiên bản')->formatStateUsing(fn ($state) => 'v' . $state)->sortable(),
                Tables\Columns\TextColumn::make('title')->label('Tiêu đề')->limit(60)->searchable(),
                Tables\Columns\TextColumn::make('review_status')->label('Trạng thái duyệt')->badge()
                    ->formatStateUsing(fn ($state) => self::reviewLabel($state))
                    ->color(fn ($state) => \App\Filament\Support\StatusPresenter::color($state, 'article')),
                Tables\Columns\TextColumn::make('tokens_used')->label('Token')->numeric(0, ',', '.')->sortable(),
                Tables\Columns\TextColumn::make('cost_vnd')->label('Chi phí')->formatStateUsing(fn ($state) => self::formatVnd($state))->sortable(),
                Tables\Columns\TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y H:i')->sortable(),
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
            Section::make('Nội dung bài')->schema([
                ViewEntry::make('html_content')->hiddenLabel()->view('filament.article-version.content'),
            ])->columnSpanFull(),
            Section::make('Thẻ SEO')->schema([
                TextEntry::make('title')->label('Tiêu đề')->placeholder('—'),
                TextEntry::make('slug')->label('Slug')->placeholder('—'),
                TextEntry::make('seo_title')->label('SEO title')->placeholder('—'),
                TextEntry::make('meta_description')->label('Meta description')->placeholder('—'),
                TextEntry::make('focus_keyword')->label('Từ khóa chính')->placeholder('—'),
                TextEntry::make('faq_schema')->label('FAQ schema')->bulleted()->placeholder('—')
                    ->formatStateUsing(fn ($state) => is_array($state) ? trim(($state['question'] ?? $state['name'] ?? '') . ' ' . ($state['answer'] ?? '')) ?: json_encode($state, JSON_UNESCAPED_UNICODE) : (string) $state),
            ])->columns(2),
            Section::make('AI đã tạo (AI đề xuất)')->schema([
                TextEntry::make('ai_provider')->label('Nhà cung cấp')->placeholder('—'),
                TextEntry::make('ai_model')->label('Model')->placeholder('—'),
                TextEntry::make('prompt_version')->label('Phiên bản prompt')->placeholder('—'),
                TextEntry::make('tokens_used')->label('Token')->numeric(0, ',', '.'),
                TextEntry::make('cost_vnd')->label('Chi phí')->formatStateUsing(fn ($state) => self::formatVnd($state)),
                ViewEntry::make('sources_cited')->label('Nguồn trích')->view('filament.article-version.sources')->columnSpanFull(),
            ])->columns(3),
            Section::make('Ảnh')->schema([
                ViewEntry::make('media')->hiddenLabel()->view('filament.article-version.media'),
            ]),
            Section::make('Duyệt')->schema([
                TextEntry::make('review_status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn ($state) => self::reviewLabel($state))
                    ->color(fn ($state) => \App\Filament\Support\StatusPresenter::color($state, 'article')),
                TextEntry::make('reviewer.name')->label('Người duyệt')->placeholder('—'),
                TextEntry::make('reviewed_at')->label('Thời điểm duyệt')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('review_notes')->label('Ghi chú duyệt')->placeholder('—'),
            ])->columns(2),
            Section::make('Lịch sử duyệt')->schema([
                TextEntry::make('review_history_note')->hiddenLabel()
                    ->state('Chỉ hiển thị người duyệt, thời điểm và ghi chú của bản này (ở mục Duyệt phía trên). Nhật ký chi tiết sẽ có khi Dev1 thêm bảng audit_logs.'),
            ]),
            Section::make('Lịch sử phiên bản')->schema([
                ViewEntry::make('history')->hiddenLabel()->view('filament.article-version.history'),
            ]),
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
