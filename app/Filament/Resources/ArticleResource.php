<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleResource\Pages;
use App\Models\Article;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Chiến dịch & Bài viết';

    protected static ?string $modelLabel = 'Bài viết';

    protected static ?string $pluralModelLabel = 'Danh sách Bài viết';

    protected static ?int $navigationSort = 2;


    protected static function user(): ?\App\Models\User
    {
        $user = \Illuminate\Support\Facades\Auth::user();

        return $user instanceof \App\Models\User && $user->is_active ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return static::user()?->isWriter() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::user()?->isWriter() ?? false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::user()?->isWriter() ?? false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::user()?->isAdmin() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return static::user()?->isAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Thông tin chiến dịch SEO')
                    ->schema([
                        Forms\Components\Select::make('website_id')
                            ->label('Website đích')
                            ->relationship('website', 'name')
                            ->required(),
                        Forms\Components\TextInput::make('primary_keyword')
                            ->label('Từ khóa chính')
                            ->required(),
                        Forms\Components\TagsInput::make('secondary_keywords')
                            ->label('Từ khóa phụ'),
                        Forms\Components\Select::make('search_intent')
                            ->label('Ý định tìm kiếm (Search Intent)')
                            ->options([
                                'informational' => 'Thông tin (Informational)',
                                'commercial' => 'Thương mại (Commercial)',
                                'transactional' => 'Giao dịch (Transactional)',
                                'navigational' => 'Điều hướng (Navigational)',
                            ])
                            ->default('informational')
                            ->required(),
                        Forms\Components\TextInput::make('topic_cluster')
                            ->label('Cụm chủ đề (Topic Cluster)'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('primary_keyword')
                    ->label('Từ khóa chính')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('website.name')
                    ->label('Website')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                Tables\Columns\TextColumn::make('topic_cluster')
                    ->label('Cụm chủ đề')
                    ->searchable(),
                Tables\Columns\TextColumn::make('search_intent')
                    ->label('Ý định'),
                Tables\Columns\TextColumn::make('workflow_status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'published' => 'info',
                        'needs_review' => 'warning',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('generate_ai')
                    ->label('AI Viết bài')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Sinh bản thảo bài viết bằng AI')
                    ->modalDescription('Hệ thống sẽ dựa trên từ khóa chính và cụm chủ đề để sinh dàn ý, bài viết chuẩn SEO và metadata.')
                    ->modalSubmitActionLabel('Bắt đầu sinh')
                    ->action(function (Article $record) {
                        $service = new \App\Services\Content\ContentPipelineService();
                        $version = $service->generateArticleVersion($record);
                        \Filament\Notifications\Notification::make()
                            ->title('Đã sinh xong bản thảo mới!')
                            ->body("Bản thảo phiên bản #{$version->version} ({$version->title}) đã được tạo và chuyển sang chờ duyệt.")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticles::route('/'),
            'create' => Pages\CreateArticle::route('/create'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
        ];
    }
}
