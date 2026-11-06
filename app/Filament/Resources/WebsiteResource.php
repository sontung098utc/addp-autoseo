<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebsiteResource\Pages;
use App\Models\Website;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WebsiteResource extends Resource
{
    protected static ?string $model = Website::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Website kết nối';

    protected static ?string $modelLabel = 'Website';

    protected static ?string $pluralModelLabel = 'Danh sách Website';

    protected static ?int $navigationSort = 1;

    protected static function user(): ?\App\Models\User
    {
        $user = \Illuminate\Support\Facades\Auth::user();

        return $user instanceof \App\Models\User && $user->is_active ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return static::user()?->isAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::user()?->isAdmin() ?? false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return static::user()?->isAdmin() ?? false;
    }

    // Tạm khóa hoàn toàn việc xóa website; "Thu hồi kết nối" làm ở PR sau.
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
            ->schema([
                Forms\Components\Section::make('Thông tin chung')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Tên website')
                            ->placeholder('Ví dụ: Blog Tin Tức Công Ty')
                            ->required(),
                        Forms\Components\Select::make('cms_type')
                            ->label('Hệ thống CMS')
                            ->options([
                                'wordpress' => 'WordPress (REST API)',
                                'magento2' => 'Magento 2 (Company_BlogApi)',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('base_url')
                            ->label('Địa chỉ Website (URL)')
                            ->placeholder('https://example.com')
                            ->url()
                            ->required(),
                        Forms\Components\TextInput::make('store_scope')
                            ->label('Store Scope / Phân vùng')
                            ->default('default')
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->label('Trạng thái kết nối')
                            ->options([
                                'active' => 'Hoạt động',
                                'pending' => 'Chờ kiểm tra',
                                'revoked' => 'Đã thu hồi quyền',
                                'error' => 'Lỗi kết nối',
                            ])
                            ->default('active')
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Xác thực bảo mật (Mã hóa AES-256)')
                    ->description('Thông tin credentials được mã hóa tự động trước khi lưu trữ vào cơ sở dữ liệu')
                    ->schema(fn (string $operation): array => $operation === 'create'
                        ? [
                            Forms\Components\TextInput::make('credentials_input.username')->label('Tên đăng nhập (WordPress)')->maxLength(255),
                            Forms\Components\TextInput::make('credentials_input.application_password')->label('Application Password (WordPress)')->password()->revealable(false)->autocomplete('new-password'),
                            Forms\Components\TextInput::make('credentials_input.consumer_key')->label('Consumer Key (Magento)')->password()->revealable(false)->autocomplete('new-password'),
                            Forms\Components\TextInput::make('credentials_input.consumer_secret')->label('Consumer Secret (Magento)')->password()->revealable(false)->autocomplete('new-password'),
                            Forms\Components\TextInput::make('credentials_input.access_token')->label('Access Token (Magento)')->password()->revealable(false)->autocomplete('new-password'),
                            Forms\Components\TextInput::make('credentials_input.access_token_secret')->label('Access Token Secret (Magento)')->password()->revealable(false)->autocomplete('new-password'),
                        ]
                        : [
                            Forms\Components\Placeholder::make('credentials_saved')
                                ->label('Tham số xác thực')
                                ->content(fn (?\Illuminate\Database\Eloquent\Model $record): string => $record && ! empty($record->encrypted_credentials) ? '•••• đã lưu' : 'Chưa có'),
                        ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tên website')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('cms_type')
                    ->label('CMS')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'wordpress' => 'success',
                        'magento2' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('base_url')
                    ->label('Địa chỉ URL')
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('store_scope')
                    ->label('Store Scope'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        'revoked' => 'danger',
                        'error' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Đồng bộ gần nhất')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebsites::route('/'),
            'create' => Pages\CreateWebsite::route('/create'),
            'edit' => Pages\EditWebsite::route('/{record}/edit'),
        ];
    }
}
