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
                        Forms\Components\Select::make('workflow_status')
                            ->label('Trạng thái vòng đời')
                            ->options([
                                'planned' => 'Đã lên kế hoạch',
                                'drafting' => 'Đang sinh nội dung (AI)',
                                'needs_review' => 'Chờ duyệt nội dung',
                                'approved' => 'Đã duyệt (Sẵn sàng xếp lịch)',
                                'rejected' => 'Từ chối (Cần sửa)',
                                'scheduled' => 'Đã lên lịch xuất bản',
                                'published' => 'Đã xuất bản thành công',
                            ])
                            ->default('planned')
                            ->required(),
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
