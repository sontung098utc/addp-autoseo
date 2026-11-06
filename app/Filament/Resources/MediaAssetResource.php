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

    protected static ?string $pluralModelLabel = 'Thư viện ảnh công ty';

    protected static ?string $navigationGroup = 'Cài đặt';

    protected static ?int $navigationSort = 2;

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
            ->columns([
                Tables\Columns\ImageColumn::make('file_path')
                    ->label('Xem trước')
                    ->disk('public')
                    ->square(),
                Tables\Columns\TextColumn::make('title')
                    ->label('Tiêu đề')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('alt_text')
                    ->label('Thẻ Alt SEO')
                    ->limit(35)
                    ->searchable(),
                Tables\Columns\TextColumn::make('website.name')
                    ->label('Website')
                    ->badge()
                    ->default('Dùng chung'),
                Tables\Columns\TextColumn::make('mime_type')
                    ->label('Định dạng')
                    ->badge(),
                Tables\Columns\TextColumn::make('file_size')
                    ->label('Kích thước')
                    ->formatStateUsing(fn ($record) => $record?->human_file_size ?? ''),
                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Đại diện')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
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
