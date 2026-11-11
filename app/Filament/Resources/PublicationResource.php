<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PublicationResource\Pages;
use App\Filament\Support\Schedule\ErrorMasker;
use App\Filament\Support\Schedule\PublicationHoldAction;
use App\Filament\Support\Schedule\ReconcileAction;
use App\Filament\Support\Schedule\RescheduleAction;
use App\Filament\Support\Schedule\ScheduleExplainer;
use App\Filament\Support\Schedule\VnTime;
use App\Filament\Support\StatusPresenter;
use App\Jobs\PublishArticleJob;
use App\Models\Publication;
use App\Models\User;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class PublicationResource extends Resource
{
    protected static ?string $model = Publication::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Lịch xuất bản';

    protected static ?string $modelLabel = 'Lượt xuất bản';

    protected static ?string $pluralModelLabel = 'Lịch xuất bản';

    /** Quy ước giao diện: câu thường ("Chiến dịch tự động"), không để Filament tự Viết Hoa Từng Chữ ở tiêu đề và breadcrumb. */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $navigationGroup = 'Lịch đăng';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'publication_key';

    /** Trang "Lịch xuất bản" mở ở tab Lịch tuần (tab mặc định). */
    public static function scheduleUrl(): string
    {
        return static::getUrl('index').'#tab-lich-tuan';
    }

    /** Trang "Lịch xuất bản" mở ở tab Danh sách. */
    public static function listUrl(): string
    {
        return static::getUrl('index').'#tab-danh-sach';
    }

    protected static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_active ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return static::user()?->isApprover() ?? false;
    }

    public static function canView(Model $record): bool
    {
        return static::user()?->isApprover() ?? false;
    }

    // Lượt xuất bản chỉ do hệ thống tạo/cập nhật (idempotency), UI chỉ xem.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
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
                                'paused' => 'Tạm dừng',
                                'awaiting_confirm' => 'Chờ xác nhận',
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
            ->header(view('filament.publication.chain'))
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['website', 'articleVersion.article']))
            ->defaultSort('scheduled_at_utc', 'asc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Trạng thái')
                    ->options(fn (): array => collect(['scheduled', 'paused', 'awaiting_confirm', 'publishing', 'published', 'failed', 'needs_reconcile'])
                        ->mapWithKeys(fn (string $v): array => [$v => StatusPresenter::label($v, 'publication')])->all()),
                Tables\Filters\SelectFilter::make('website_id')->label('Website')->relationship('website', 'name'),
                Tables\Filters\Filter::make('scheduled_range')->label('Khoảng ngày đăng')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Từ ngày (giờ VN)'),
                        Forms\Components\DatePicker::make('until')->label('Đến ngày (giờ VN)'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('scheduled_at_utc', '>=', Carbon::parse($d, 'Asia/Ho_Chi_Minh')->startOfDay()->utc()))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->where('scheduled_at_utc', '<', Carbon::parse($d, 'Asia/Ho_Chi_Minh')->addDay()->startOfDay()->utc()));
                    })
                    ->indicateUsing(function (array $data): array {
                        $out = [];
                        if ($data['from'] ?? null) {
                            $out[] = 'Từ '.Carbon::parse($data['from'])->format('d/m/Y');
                        }
                        if ($data['until'] ?? null) {
                            $out[] = 'Đến '.Carbon::parse($data['until'])->format('d/m/Y');
                        }

                        return $out;
                    }),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('articleVersion.article.primary_keyword')
                    ->label('Bài / từ khóa')
                    ->description(fn (Publication $r): ?string => $r->articleVersion?->title)
                    ->searchable()
                    ->wrap()
                    ->url(fn (Publication $r): ?string => $r->articleVersion?->article_id
                        ? ArticleResource::getUrl('view', ['record' => $r->articleVersion->article_id]).'#tab-publish'
                        : null)
                    // Cột nội dung là cột quan trọng nhất: cho rộng, các cột phụ bên dưới gọn lại.
                    ->extraHeaderAttributes(['style' => 'min-width:22rem'])
                    ->extraCellAttributes(['style' => 'min-width:22rem'])
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('website.name')->toggleable()->sortable()
                    ->label('Website')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('scheduled_at_utc')
                    ->label('Giờ đăng (giờ VN)')
                    ->formatStateUsing(fn (Publication $r): string => VnTime::format(VnTime::fromRaw($r, 'scheduled_at_utc')))
                    ->description(fn (Publication $r): string => VnTime::formatUtc(VnTime::fromRaw($r, 'scheduled_at_utc')))
                    ->tooltip('Hệ thống lưu giờ UTC, hiển thị theo giờ Việt Nam (UTC+7).')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')->sortable()
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => StatusPresenter::label($state, 'publication'))
                    ->color(fn (string $state): string => StatusPresenter::color($state, 'publication'))
                    ->icon(fn (string $state): string => StatusPresenter::icon($state, 'publication'))
                    ->tooltip(fn (Publication $r): string => ScheduleExplainer::explain($r->status)['why']),
                Tables\Columns\TextColumn::make('attempt_count')->toggleable()->sortable()
                    ->label('Số lần thử')
                    ->numeric()
                    ->alignCenter()
                    ->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])
                    ->extraCellAttributes(['style' => 'width:1%']),
                Tables\Columns\TextColumn::make('last_error')->toggleable()
                    ->label('Lỗi')
                    ->formatStateUsing(fn (?string $state): string => ErrorMasker::short($state, 60))
                    ->tooltip(fn (Publication $r): ?string => $r->last_error ? ErrorMasker::mask($r->last_error) : null)
                    ->wrap()
                    ->extraCellAttributes(['style' => 'max-width:16rem'])
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('published_url')->toggleable(isToggledHiddenByDefault: true)
                    ->label('Link bài')
                    ->limit(30)
                    ->url(fn (Publication $r) => $r->published_url, true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('publication_key')->toggleable(isToggledHiddenByDefault: true)->sortable()
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
                Tables\Actions\Action::make('publishNow')
                    ->label('Đăng ngay')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->visible(fn (Publication $r) => in_array($r->status, ['scheduled', 'failed'], true))
                    ->authorize(fn () => auth()->user()?->isApprover() || auth()->user()?->isAdmin())
                    ->requiresConfirmation()
                    ->modalHeading('Xuất bản ngay lên CMS')
                    ->modalDescription('Hệ thống sẽ gửi bài viết này trực tiếp lên CMS đích ngay lập tức.')
                    ->action(function (Publication $r) {
                        PublishArticleJob::dispatchSync($r);
                        $r->refresh();
                        if ($r->status === 'published') {
                            Notification::make()
                                ->title('Đã xuất bản thành công lên '.($r->website?->name ?? 'CMS'))
                                ->body($r->published_url ? 'URL: '.$r->published_url : null)
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Xuất bản thất bại')
                                ->body($r->last_error ?? 'Lỗi không xác định')
                                ->danger()
                                ->send();
                        }
                    }),
                PublicationHoldAction::pauseRow(),
                PublicationHoldAction::resumeRow(),
                ReconcileAction::row(),
                RescheduleAction::row(),
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
