<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PublicationResource\Pages;
use App\Models\Publication;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use App\Filament\Support\Schedule\ErrorMasker;
use App\Filament\Support\Schedule\ReconcileAction;
use App\Filament\Support\Schedule\ScheduleExplainer;
use App\Filament\Support\Schedule\VnTime;
use App\Filament\Support\StatusPresenter;

class PublicationResource extends Resource
{
    protected static ?string $model = Publication::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Lịch xuất bản';

    protected static ?string $modelLabel = 'Lượt xuất bản';

    protected static ?string $pluralModelLabel = 'Lịch xuất bản bài';

    protected static ?string $navigationGroup = 'Lịch đăng';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'publication_key';


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

    public static function getNavigationBadge(): ?string
    {
        $n = static::needsAttentionCount();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Lượt đăng lỗi hoặc chờ đối soát cần xử lý';
    }

    public static function needsAttentionCount(): int
    {
        return Publication::query()->whereIn('status', ['failed', 'needs_reconcile'])->count();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->with(['website', 'articleVersion.article']))
            ->defaultSort('scheduled_at_utc', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('articleVersion.article.primary_keyword')
                    ->label('Bài / từ khóa')
                    ->description(fn (Publication $r): ?string => $r->articleVersion?->title)
                    ->searchable()
                    ->wrap()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('website.name')
                    ->label('Website')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('scheduled_at_utc')
                    ->label('Giờ đăng (giờ VN)')
                    ->formatStateUsing(fn (Publication $r): string => VnTime::format(VnTime::fromRaw($r, 'scheduled_at_utc')))
                    ->description(fn (Publication $r): string => VnTime::formatUtc(VnTime::fromRaw($r, 'scheduled_at_utc')))
                    ->tooltip('Hệ thống lưu giờ UTC, hiển thị theo giờ Việt Nam (UTC+7).')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusPresenter::label($state, 'publication'))
                    ->color(fn (string $state): string => StatusPresenter::color($state, 'publication'))
                    ->icon(fn (string $state): string => StatusPresenter::icon($state, 'publication'))
                    ->tooltip(fn (Publication $r): string => ScheduleExplainer::explain($r->status)['why']),
                Tables\Columns\TextColumn::make('attempt_count')
                    ->label('Số lần thử')
                    ->numeric()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('last_error')
                    ->label('Lỗi')
                    ->formatStateUsing(fn (?string $state): string => ErrorMasker::short($state, 60))
                    ->tooltip(fn (Publication $r): ?string => $r->last_error ? ErrorMasker::mask($r->last_error) : null)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('published_url')
                    ->label('Link bài')
                    ->limit(30)
                    ->url(fn (Publication $r) => $r->published_url, true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('publication_key')
                    ->label('Khóa xuất bản')
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Đã chép khóa xuất bản')
                    ->tooltip('Khóa duy nhất chống đăng trùng (idempotency). Bấm để chép.'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Xem'),
                ReconcileAction::row(),
                \App\Filament\Support\Schedule\RescheduleAction::row(),
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
