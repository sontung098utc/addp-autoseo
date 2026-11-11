<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MediaAssetResource\Pages;
use App\Models\MediaAsset;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MediaAssetResource extends Resource
{
    protected static ?string $model = MediaAsset::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationLabel = 'Thư viện ảnh';

    protected static ?string $modelLabel = 'Ảnh tư liệu';

    protected static ?string $pluralModelLabel = 'Thư viện ảnh';

    /** Quy ước giao diện: câu thường ("Chiến dịch tự động"), không để Filament tự Viết Hoa Từng Chữ ở tiêu đề và breadcrumb. */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $navigationGroup = 'Chiến dịch';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Thông tin tệp ảnh')
                    ->schema([
                        Forms\Components\FileUpload::make('file_path')
                            ->label('Tải lên ảnh mới')
                            ->disk('public')
                            ->directory('media/' . date('Y/m'))
                            ->image()
                            ->imageEditor()
                            ->maxSize(10240)
                            ->helperText('PNG, JPG hoặc WebP · tối đa 10 MB')
                            ->required(fn (string $context): bool => $context === 'create'),
                        Forms\Components\Select::make('website_id')
                            ->label('Gán cho Website')
                            ->relationship('website', 'name')
                            ->nullable()
                            ->placeholder('Dùng chung cho toàn công ty'),
                        Forms\Components\TextInput::make('title')
                            ->label('Tiêu đề ảnh')
                            ->placeholder('Ví dụ: Hình ảnh nhà máy sản xuất đạt chuẩn GMP'),
                        Forms\Components\TextInput::make('alt_text')
                            ->label('Thẻ Alt SEO (Văn bản thay thế)')
                            ->placeholder('Tự động sinh hoặc nhập thủ công tối ưu từ khóa'),
                        Forms\Components\Textarea::make('caption')
                            ->label('Chú thích ảnh (Caption)')
                            ->rows(2),
                        Forms\Components\Toggle::make('is_featured')
                            ->label('Đánh dấu là ảnh đại diện nổi bật'),
                    ]),
            ]);
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
            ->columns([
                Tables\Columns\ImageColumn::make('file_path')
                    ->label('Xem trước')
                    ->disk('public')
                    ->square(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('alt_text')->toggleable()
                    ->label('Thẻ Alt SEO')
                    ->limit(35)
                    ->searchable(),
                Tables\Columns\TextColumn::make('website.name')->toggleable()->sortable()
                    ->label('Website')
                    ->badge()
                    ->default('Dùng chung'),
                Tables\Columns\TextColumn::make('mime_type')->toggleable(isToggledHiddenByDefault: true)->sortable()
                    ->label('Định dạng')
                    ->badge(),
                Tables\Columns\TextColumn::make('file_size')->toggleable(isToggledHiddenByDefault: true)->sortable()
                    ->label('Kích thước')
                    ->formatStateUsing(fn ($record) => $record?->human_file_size ?? ''),
                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Đại diện')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')->toggleable()
                    ->label('Ngày tải lên')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('website')
                    ->relationship('website', 'name')
                    ->label('Lọc theo Website'),
                Tables\Filters\TernaryFilter::make('is_featured')
                    ->label('Ảnh đại diện'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListMediaAssets::route('/'),
            'create' => Pages\CreateMediaAsset::route('/create'),
            'edit' => Pages\EditMediaAsset::route('/{record}/edit'),
        ];
    }
}
