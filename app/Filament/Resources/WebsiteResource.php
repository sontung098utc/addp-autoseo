<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebsiteResource\Pages;
use App\Models\Website;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use App\Filament\Support\Audit\AuditAction;
use App\Filament\Support\Audit\AuditRecorder;
use App\Filament\Support\StatusPresenter;
use App\Models\Publication;
use App\Services\Publishing\PublisherManager;
use App\Services\Security\UrlSafetyService;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class WebsiteResource extends Resource
{
    protected static ?string $model = Website::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Website kết nối';

    protected static ?string $modelLabel = 'Website';

    protected static ?string $pluralModelLabel = 'Danh sách Website';

    protected static ?string $navigationGroup = 'Cài đặt';

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
                            ->live()
                            ->required(),
                        Forms\Components\TextInput::make('base_url')
                            ->label('Địa chỉ Website (URL)')
                            ->placeholder('https://example.com')
                            ->url()
                            ->required()
                            ->rules(fn (Get $get, ?\Illuminate\Database\Eloquent\Model $record): array => [
                                static::httpsSafeUrlRule(),
                                Rule::unique('websites', 'base_url')
                                    ->where('store_scope', (string) $get('store_scope'))
                                    ->ignore($record?->getKey()),
                            ])
                            ->validationMessages(['unique' => 'Website với địa chỉ URL và Store Scope này đã tồn tại.']),
                        Forms\Components\TextInput::make('store_scope')
                            ->label('Store Scope / Phân vùng')
                            ->default('default')
                            ->required(),
                        Forms\Components\Placeholder::make('status_badge')
                            ->label('Trạng thái kết nối')
                            ->visibleOn('edit')
                            ->content(fn (?Website $record): HtmlString => static::statusBadgeHtml($record?->status)),
                    ])->columns(2),

                Forms\Components\Section::make('Xác thực bảo mật (Mã hóa AES-256)')
                    ->description('Thông tin credentials được mã hóa tự động trước khi lưu trữ vào cơ sở dữ liệu')
                    ->schema(fn (string $operation): array => $operation === 'create'
                        ? [
                            Forms\Components\Placeholder::make('credentials_hint')->hiddenLabel()
                                ->content('Chọn hệ thống CMS ở trên để nhập đúng loại credential.')
                                ->visible(fn (Forms\Get $get): bool => ! in_array($get('cms_type'), ['wordpress', 'magento2'], true)),
                            Forms\Components\TextInput::make('credentials_input.username')->label('Tên đăng nhập (WordPress)')->maxLength(255)->visible(fn (Forms\Get $get): bool => $get('cms_type') === 'wordpress'),
                            Forms\Components\TextInput::make('credentials_input.application_password')->label('Application Password (WordPress)')->password()->revealable(false)->autocomplete('new-password')->visible(fn (Forms\Get $get): bool => $get('cms_type') === 'wordpress'),
                            Forms\Components\TextInput::make('credentials_input.consumer_key')->label('Consumer Key (Magento)')->password()->revealable(false)->autocomplete('new-password')->visible(fn (Forms\Get $get): bool => $get('cms_type') === 'magento2'),
                            Forms\Components\TextInput::make('credentials_input.consumer_secret')->label('Consumer Secret (Magento)')->password()->revealable(false)->autocomplete('new-password')->visible(fn (Forms\Get $get): bool => $get('cms_type') === 'magento2'),
                            Forms\Components\TextInput::make('credentials_input.access_token')->label('Access Token (Magento)')->password()->revealable(false)->autocomplete('new-password')->visible(fn (Forms\Get $get): bool => $get('cms_type') === 'magento2'),
                            Forms\Components\TextInput::make('credentials_input.access_token_secret')->label('Access Token Secret (Magento)')->password()->revealable(false)->autocomplete('new-password')->visible(fn (Forms\Get $get): bool => $get('cms_type') === 'magento2'),
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
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tên website')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('cms_type')->toggleable()->sortable()
                    ->label('CMS')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'wordpress' => 'WordPress',
                        'magento2' => 'Magento 2',
                        default => (string) $state,
                    }),
                Tables\Columns\TextColumn::make('base_url')->toggleable()->sortable()
                    ->label('Địa chỉ URL')
                    ->copyable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('store_scope')->toggleable(isToggledHiddenByDefault: true)
                    ->label('Store Scope'),
                Tables\Columns\TextColumn::make('status')->sortable()
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => StatusPresenter::label($state, 'website'))
                    ->color(fn (?string $state): string => StatusPresenter::color($state, 'website'))
                    ->icon(fn (?string $state): string => StatusPresenter::icon($state, 'website')),
                Tables\Columns\TextColumn::make('last_synced_at')->toggleable()
                    ->label('Đồng bộ gần nhất')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Chưa đồng bộ')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('cms_type')
                    ->label('CMS')
                    ->options(['wordpress' => 'WordPress', 'magento2' => 'Magento 2']),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(fn (): array => collect(['active', 'pending', 'error', 'revoked'])
                        ->mapWithKeys(fn (string $v): array => [$v => StatusPresenter::label($v, 'website')])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Chi tiết')->slideOver()->modalHeading('Chi tiết kết nối')
                    ->infolist(static::infolistSchema()),
                Tables\Actions\EditAction::make(),
                Tables\Actions\ActionGroup::make([
                    static::testConnectionAction(),
                    static::rotateCredentialsAction(),
                    static::revokeAction(),
                ]),
            ]);
    }

    public static function isAdmin(): bool
    {
        return static::user()?->isAdmin() ?? false;
    }

    public static function statusBadgeHtml(?string $status): HtmlString
    {
        return new HtmlString(Blade::render(
            '<x-filament::badge :color="$color" :icon="$icon">{{ $label }}</x-filament::badge>',
            [
                'color' => StatusPresenter::color($status, 'website'),
                'icon' => StatusPresenter::icon($status, 'website'),
                'label' => StatusPresenter::label($status, 'website'),
            ],
        ));
    }

    /** Quy tắc: bắt buộc HTTPS và không thuộc mạng nội bộ (tái dùng UrlSafetyService). */
    public static function httpsSafeUrlRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $url = (string) $value;
            if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                $fail('Địa chỉ website bắt buộc dùng giao thức HTTPS.');

                return;
            }
            try {
                app(UrlSafetyService::class)->validateUrl($url);
            } catch (InvalidArgumentException) {
                $fail('Địa chỉ website không hợp lệ hoặc thuộc mạng nội bộ, không được phép kết nối.');
            }
        };
    }

    public static function infolistSchema(): array
    {
        return [
            Infolists\Components\Section::make('Thông tin kết nối')->schema([
                Infolists\Components\TextEntry::make('name')->label('Tên website'),
                Infolists\Components\TextEntry::make('cms_type')->label('CMS')->badge()->color('gray')
                    ->formatStateUsing(fn (?string $state): string => $state === 'magento2' ? 'Magento 2' : ($state === 'wordpress' ? 'WordPress' : (string) $state)),
                Infolists\Components\TextEntry::make('base_url')->label('Địa chỉ URL'),
                Infolists\Components\TextEntry::make('store_scope')->label('Store Scope'),
                Infolists\Components\TextEntry::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (?string $state): string => StatusPresenter::label($state, 'website'))
                    ->color(fn (?string $state): string => StatusPresenter::color($state, 'website'))
                    ->icon(fn (?string $state): string => StatusPresenter::icon($state, 'website')),
                Infolists\Components\TextEntry::make('last_synced_at')->label('Đồng bộ gần nhất')
                    ->dateTime('d/m/Y H:i')->placeholder('Chưa đồng bộ'),
            ])->columns(2),
            Infolists\Components\Section::make('Khả năng của kết nối')->schema([
                Infolists\Components\TextEntry::make('capability_flags')->hiddenLabel()
                    ->listWithLineBreaks()
                    ->getStateUsing(function (Website $record): array {
                        $flags = (array) ($record->capability_flags ?? []);

                        return $flags === []
                            ? ['Chưa có dữ liệu']
                            : collect($flags)->map(fn ($v, $k): string => $k.': '.($v ? 'Có' : 'Không'))->values()->all();
                    }),
            ]),
            Infolists\Components\Section::make('Nhật ký kết nối 90 ngày')->schema([
                Infolists\Components\TextEntry::make('connection_log')->hiddenLabel()
                    ->getStateUsing(fn (): string => 'Chưa có dữ liệu'),
            ]),
        ];
    }

    public static function testConnectionAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('testConnection')
            ->label('Kiểm tra kết nối')
            ->icon('heroicon-o-signal')
            ->visible(fn (Website $record): bool => static::isAdmin() && $record->status !== 'revoked')
            ->authorize(fn (): bool => static::isAdmin())
            ->action(function (Website $record): void {
                abort_unless(static::isAdmin(), 403);

                try {
                    $publisher = app(PublisherManager::class)->getPublisher($record);
                } catch (Throwable) {
                    Notification::make()->title('Chưa hỗ trợ kiểm tra kết nối cho CMS này')->warning()->send();

                    return;
                }

                try {
                    $ok = $publisher->testConnection($record);
                } catch (Throwable) {
                    $ok = false;
                }

                if ($ok) {
                    $record->forceFill(['last_synced_at' => now()])->save();
                    Notification::make()->title('Kết nối thành công')->success()->send();
                } else {
                    Notification::make()->title('Kết nối không thành công. Vui lòng kiểm tra lại địa chỉ và credential.')->danger()->send();
                }
            });
    }

    public static function rotateCredentialsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('rotateCredentials')
            ->label('Đổi credential')
            ->icon('heroicon-o-key')
            ->visible(fn (): bool => static::isAdmin())
            ->authorize(fn (): bool => static::isAdmin())
            ->modalHeading('Đổi credential')
            ->modalDescription('Giá trị cũ không được hiển thị. Nhập toàn bộ giá trị mới.')
            ->form(function (Website $record): array {
                $fields = $record->cms_type === 'magento2'
                    ? ['consumer_key' => 'Consumer Key', 'consumer_secret' => 'Consumer Secret', 'access_token' => 'Access Token', 'access_token_secret' => 'Access Token Secret']
                    : ['username' => 'Tên đăng nhập', 'application_password' => 'Application Password'];

                return collect($fields)->map(function (string $label, string $key) {
                    $input = Forms\Components\TextInput::make($key)->label($label)->required()->maxLength(1000);

                    return $key === 'username'
                        ? $input->autocomplete('off')
                        : $input->password()->revealable(false)->autocomplete('new-password');
                })->values()->all();
            })
            ->action(function (Website $record, array $data): void {
                abort_unless(static::isAdmin(), 403);

                $update = ['encrypted_credentials' => array_filter($data, fn ($v) => filled($v))];
                if ($record->status === 'revoked') {
                    $update['status'] = 'pending';
                }
                $record->forceFill($update)->save();

                Log::info('website.credentials_rotated', ['website_id' => $record->id, 'actor_id' => Auth::id()]);
                Notification::make()->title('Đã cập nhật credential')->success()->send();
            });
    }

    public static function revokeAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('revoke')
            ->label('Thu hồi kết nối')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (Website $record): bool => static::isAdmin() && $record->status !== 'revoked')
            ->authorize(fn (): bool => static::isAdmin())
            ->modalHeading('Thu hồi kết nối')
            ->modalDescription(function (Website $record): string {
                $articles = $record->articles()->count();
                $pending = Publication::where('website_id', $record->id)->whereIn('status', ['scheduled', 'publishing'])->count();

                return "Website này có {$articles} bài viết và {$pending} lượt đăng đang chờ (đã xếp lịch/đang đăng). "
                    .'Các lượt đăng đang chờ sẽ báo Lỗi vì website không còn hoạt động. '
                    .'Credential sẽ bị xóa; bài viết, phiên bản và lượt đăng được giữ nguyên.';
            })
            ->modalSubmitActionLabel('Xác nhận thu hồi')
            ->form([
                Forms\Components\Textarea::make('reason')->label('Lý do thu hồi')->required()->minLength(5)->maxLength(1000),
            ])
            ->action(function (Website $record, array $data): void {
                $user = static::user();
                abort_unless($user?->isAdmin(), 403);

                app(AuditRecorder::class)->record(AuditAction::RevokeWebsite, $user, null, null, $data['reason'], ['website_id' => $record->id]);
                $record->forceFill(['encrypted_credentials' => [], 'status' => 'revoked'])->save();

                Notification::make()->title('Đã thu hồi kết nối')->success()->send();
            });
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
