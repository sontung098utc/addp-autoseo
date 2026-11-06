<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PublicationResource\Pages;
use App\Models\Publication;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PublicationResource extends Resource
{
    protected static ?string $model = Publication::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Lịch xuất bản';

    protected static ?string $modelLabel = 'Lượt xuất bản';

    protected static ?string $pluralModelLabel = 'Lịch xuất bản bài';

    protected static ?string $navigationGroup = 'Lịch đăng';

    protected static ?int $navigationSort = 3;


    protected static function user(): ?\App\Models\User
    {
        $user = \Illuminate\Support\Facades\Auth::user();

        return $user instanceof \App\Models\User && $user->is_active ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return static::user()?->isApprover() ?? false;
    }

    public static function canView(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::user()?->isApprover() ?? false;
    }

    // Lượt xuất bản chỉ do hệ thống tạo/cập nhật (idempotency), UI chỉ xem.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->disabled()
            ->schema([
                Forms\Components\Section::make('Thông tin xuất bản')
                    ->schema([
                        Forms\Components\Select::make('website_id')
                            ->label('Website đích')
                            ->relationship('website', 'name')
                            ->required(),
                        Forms\Components\TextInput::make('publication_key')
                            ->label('Khóa chống trùng (Publication Key)')
                            ->required(),
                        Forms\Components\DateTimePicker::make('scheduled_at_utc')
                            ->label('Thời gian dự kiến (UTC)')
                            ->required(),
                        Forms\Components\TextInput::make('timezone')
                            ->label('Múi giờ')
                            ->default('Asia/Ho_Chi_Minh')
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->label('Trạng thái')
                            ->options([
                                'scheduled' => 'Đã lên lịch',
                                'publishing' => 'Đang xuất bản',
                                'published' => 'Đã xuất bản',
                                'failed' => 'Thất bại',
                                'needs_reconcile' => 'Cần đối soát (Timeout)',
                            ])
                            ->default('scheduled')
                            ->required(),
                        Forms\Components\TextInput::make('cms_post_id')
                            ->label('ID bài viết trên CMS'),
                        Forms\Components\TextInput::make('published_url')
                            ->label('Đường dẫn bài viết đã đăng (URL)')
                            ->url()
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('publication_key')
                    ->label('Publication Key')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('website.name')
                    ->label('Website')
                    ->badge(),
                Tables\Columns\TextColumn::make('scheduled_at_utc')
                    ->label('Hạn xuất bản (Giờ VN)')
                    ->dateTime('d/m/Y H:i', 'Asia/Ho_Chi_Minh')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'scheduled' => 'warning',
                        'failed' => 'danger',
                        'needs_reconcile' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('published_url')
                    ->label('Link bài')
                    ->limit(30)
                    ->url(fn ($record) => $record->published_url, true),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPublications::route('/'),
            'view' => Pages\ViewPublication::route('/{record}'),
        ];
    }
}
