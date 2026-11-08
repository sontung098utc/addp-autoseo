<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ArticleResource\Pages;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\Review\ArticleRows;
use App\Filament\Support\Review\ReviewActions;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Filament\Support\StatusPresenter;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Website;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Bài viết';

    protected static ?string $modelLabel = 'Bài viết';

    protected static ?string $pluralModelLabel = 'Bài viết';

    protected static ?string $navigationGroup = 'Chiến dịch';

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

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $n = Article::query()->where('workflow_status', 'needs_review')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** Mã tab (cũng là giá trị workflow_status trừ 'all') theo thứ tự hiển thị. */
    public const TAB_STATUSES = ['planned', 'drafting', 'needs_review', 'rejected', 'approved', 'scheduled', 'published'];

    public const TAB_LABELS = [
        'planned' => 'Kế hoạch', 'drafting' => 'Đang viết', 'needs_review' => 'Chờ duyệt', 'rejected' => 'Cần sửa',
        'approved' => 'Đã duyệt', 'scheduled' => 'Đã lên lịch', 'published' => 'Đã đăng',
    ];

    /** Nút chính (duy nhất) của dòng theo trạng thái: submit_review | approve | schedule | view_version | null. */
    public static function primaryActionFor(Article $r): string
    {
        $canApprove = ReviewActions::canApprove();
        $hasVersion = $r->latestVersion !== null || $r->versions()->exists();

        return match ($r->workflow_status) {
            'needs_review' => $canApprove ? 'approve' : 'view_version',
            'approved' => $canApprove ? 'schedule' : 'view_version',
            'planned', 'drafting', 'rejected' => ($hasVersion && ReviewActions::canSubmit()) ? 'submit_review' : 'view_version',
            default => 'view_version',
        };
    }

    private static function narrow(Tables\Columns\Column $c): Tables\Columns\Column
    {
        return $c->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'width:1%;white-space:nowrap']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['website', 'latestVersion']))
            ->poll(fn () => RunActivity::anyRunning() ? RunActivity::POLL_LIVE.'s' : RunActivity::POLL_IDLE.'s')
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->defaultSort('id', 'desc')
            ->recordClasses('ss-sticky-row')
            ->columns([
                Tables\Columns\TextColumn::make('primary_keyword')
                    ->label('Bài / từ khóa')
                    ->searchable(query: fn ($query, string $search) => $query
                        ->where('primary_keyword', 'like', "%{$search}%")
                        ->orWhereHas('latestVersion', fn ($v) => $v->where('title', 'like', "%{$search}%")))
                    ->sortable()
                    ->weight('bold')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (Article $r) => $r->primary_keyword)
                    ->description(fn (Article $r) => $r->latestVersion?->title ? \Illuminate\Support\Str::limit($r->latestVersion->title, 60) : null)
                    ->extraHeaderAttributes(['class' => 'ss-col-main'])
                    ->extraCellAttributes(['class' => 'ss-col-main']),
                static::narrow(Tables\Columns\TextColumn::make('workflow_status')->sortable()
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(function (?string $state, Article $r) {
                        $label = StatusPresenter::label($state, 'article');
                        $live = ArticleRows::liveRun($r);

                        return $live ? ($state === 'drafting' ? 'Đang viết' : $label).' · '.(ArticleRows::stageLabel($live) ?? 'AI') : $label;
                    })
                    ->color(fn (?string $state) => StatusPresenter::color($state, 'article'))
                    ->icon(fn (?string $state, Article $r) => ArticleRows::liveRun($r) ? 'heroicon-o-arrow-path' : StatusPresenter::icon($state, 'article'))
                    ->extraAttributes(fn (Article $r) => ArticleRows::liveRun($r) ? ['class' => 'ss-badge-live', 'data-running' => '1'] : [])),
                static::narrow(Tables\Columns\TextColumn::make('campaign_name')
                    ->label('Chiến dịch')
                    ->state(fn (Article $r) => ArticleRows::campaignName($r) ?? '—')
                    ->limit(16)
                    ->tooltip(fn (Article $r) => ArticleRows::campaignName($r))
                    ->visibleFrom('xl')),
                static::narrow(Tables\Columns\TextColumn::make('last_run')
                    ->label('Phiên gần nhất')
                    ->badge()
                    ->limit(18)
                    ->tooltip(function (Article $r) {
                        $run = ArticleRows::lastRun($r);
                        $score = $run ? ArticleRows::score($run) : null;

                        return $run ? (AgentRunResource::STATUS[$run->status][0] ?? $run->status).' · phiên #'.$run->id.($score !== null ? ' · '.$score.' điểm' : '') : null;
                    })
                    ->state(function (Article $r) {
                        $run = ArticleRows::lastRun($r);
                        if (! $run) {
                            return '—';
                        }

                        return ArticleRows::RUN_SHORT[$run->status] ?? (AgentRunResource::STATUS[$run->status][0] ?? $run->status);
                    })
                    ->url(fn (Article $r) => ($run = ArticleRows::lastRun($r)) && AgentRunResource::canViewAny() ? AgentRunResource::getUrl('view', ['record' => $run]) : null)
                    ->color(fn (Article $r) => ($run = ArticleRows::lastRun($r)) ? (AgentRunResource::STATUS[$run->status][1] ?? 'gray') : 'gray')
                    ->visibleFrom('xl')),
                static::narrow(Tables\Columns\TextColumn::make('updated_at')->label('Cập nhật')->since()->sortable()
                    ->tooltip(fn (Article $r) => $r->updated_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i'))
                    ->visibleFrom('md')),
                Tables\Columns\TextColumn::make('website.name')->toggleable(isToggledHiddenByDefault: true)
                    ->label('Website')->badge()->color('info')->sortable(),
                Tables\Columns\TextColumn::make('topic_cluster')->toggleable(isToggledHiddenByDefault: true)->sortable()
                    ->label('Cụm chủ đề')->searchable(),
                Tables\Columns\TextColumn::make('search_intent')->toggleable(isToggledHiddenByDefault: true)->sortable()
                    ->label('Ý định'),
                Tables\Columns\TextColumn::make('created_at')->toggleable(isToggledHiddenByDefault: true)
                    ->label('Ngày tạo')->dateTime('d/m/Y')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('website_id')->label('Website')->relationship('website', 'name'),
                Tables\Filters\SelectFilter::make('campaign_id')->label('Chiến dịch')
                    ->options(fn () => Campaign::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn ($query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereIn('articles.id', AgentRun::query()->where('campaign_id', $data['value'])->select('article_id'))
                        : $query),
                Tables\Filters\TernaryFilter::make('ai_running')->label('Phiên AI đang chạy')
                    ->placeholder('Tất cả')->trueLabel('Đang có phiên chạy')->falseLabel('Không có phiên chạy')
                    ->queries(
                        true: fn ($query) => $query->whereIn('articles.id', AgentRun::query()->where('status', 'running')->select('article_id')),
                        false: fn ($query) => $query->whereNotIn('articles.id', AgentRun::query()->where('status', 'running')->whereNotNull('article_id')->select('article_id')),
                        blank: fn ($query) => $query,
                    ),
                Tables\Filters\SelectFilter::make('search_intent')->label('Ý định')->options([
                    'informational' => 'Thông tin', 'commercial' => 'Thương mại', 'transactional' => 'Giao dịch', 'navigational' => 'Điều hướng',
                ]),
            ])
            ->actions([
                static::submitAction(),
                static::approveAction(),
                static::scheduleAction(),
                static::viewVersionAction(),
                Tables\Actions\ActionGroup::make([
                    static::rejectAction(),
                    Tables\Actions\Action::make('generate_ai')
                        ->label('AI Viết bài')
                        ->icon('heroicon-o-sparkles')
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
                    Tables\Actions\Action::make('run_agent')
                        ->label('Giao đội AI')
                        ->icon('heroicon-o-cpu-chip')
                        ->visible(fn (Article $record) => (\Illuminate\Support\Facades\Auth::user()?->isApprover() ?? false)
                            && in_array($record->workflow_status, ['planned', 'drafting', 'rejected'], true)
                            && ArticleRows::liveRun($record) === null)
                        ->requiresConfirmation()
                        ->modalHeading('Giao bài này cho đội AI')
                        ->modalDescription('Đội AI sẽ kiểm an toàn, phân hạng rủi ro, lập dàn ý, viết, tự chấm và sửa (tối đa 3 vòng). Kết quả luôn là BẢN NHÁP chờ người duyệt; không có gì được tự đăng.')
                        ->modalSubmitActionLabel('Giao việc')
                        ->action(function (Article $record) {
                            abort_unless(\Illuminate\Support\Facades\Auth::user()?->isApprover(), 403);
                            // Chống giao trùng: đã có phiên đang chạy thật thì không tạo phiên thứ hai.
                            if (ArticleRows::liveRun($record) !== null) {
                                \Filament\Notifications\Notification::make()->title('Bài này đang có phiên chạy')->body('Đợi phiên hiện tại xong rồi hãy giao lại.')->warning()->send();

                                return;
                            }
                            $ai = \App\Services\Agent\Settings\AgentAi::forInteractiveUse();
                            if ($ai === null) {
                                \Filament\Notifications\Notification::make()->title('Chưa kết nối AI')->body('Vào Đội AI → Kết nối AI để nhập khóa trước khi giao việc.')->warning()->send();

                                return;
                            }
                            $run = (new \App\Services\Agent\Run\AgentRunOrchestrator($ai, policy: \App\Services\Agent\Settings\AgentAi::policy()))->run($record, \Illuminate\Support\Facades\Auth::user());
                            $n = \Filament\Notifications\Notification::make()->title('Phiên chạy #'.$run->id.': '.(AgentRunResource::STATUS[$run->status][0] ?? $run->status))
                                ->body($run->outcome_reason ?: 'Đã có bản nháp chờ duyệt.')
                                ->actions([\Filament\Notifications\Actions\Action::make('xem')->label('Xem diễn biến')->url(AgentRunResource::getUrl('view', ['record' => $run]))]);
                            in_array($run->status, ['ready_for_review'], true) ? $n->success() : $n->warning();
                            $n->send();
                        }),
                    Tables\Actions\EditAction::make(),
                ])->label('⋯')->icon('heroicon-m-ellipsis-horizontal')->iconButton()->tooltip('Thao tác khác'),
            ], position: Tables\Enums\ActionsPosition::AfterColumns)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function viewVersionAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('view_version')->size('sm')->label('Xem')->icon('heroicon-o-eye')->color('gray')
            ->visible(fn (Article $r) => static::primaryActionFor($r) === 'view_version' && $r->versions()->exists())
            ->url(fn (Article $r) => ArticleVersionResource::getUrl('view', ['record' => $r->versions()->orderByDesc('version')->firstOrFail()]));
    }

    /** Kiểm chất lượng bản mới nhất; null nếu bài chưa có bản nào. */
    private static function qualityOf(Article $record): ?\App\Services\Content\Quality\QualityReport
    {
        $v = $record->versions()->orderByDesc('version')->first();

        return $v ? ReviewActions::qualityFor($v) : null;
    }

    private static function latest(Article $record): \App\Models\ArticleVersion
    {
        $v = $record->versions()->orderByDesc('version')->first();
        if (! $v) {
            throw new \InvalidArgumentException('Bài chưa có bản thảo nào.');
        }

        return $v;
    }

    private static function submitAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('submit_review')->size('sm')->label('Gửi duyệt')->icon('heroicon-o-paper-airplane')
            ->visible(fn (Article $r) => static::primaryActionFor($r) === 'submit_review')
            ->authorize(fn () => ReviewActions::canSubmit())
            ->requiresConfirmation()
            ->action(function (Article $r) {
                abort_unless(ReviewActions::canSubmit(), 403);
                ReviewActions::run(fn () => (new ReviewWorkflow)->submitForReview(ReviewActions::user(), static::latest($r)), 'Đã gửi duyệt');
            });
    }

    private static function approveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve')->size('sm')->label('Duyệt')->icon('heroicon-o-check')->color('success')
            ->visible(fn (Article $r) => static::primaryActionFor($r) === 'approve')
            ->authorize(fn () => ReviewActions::canApprove())
            ->tooltip(fn (Article $r) => ReviewActions::blockedHint(static::qualityOf($r)))
            ->modalHeading(fn (Article $r) => static::qualityOf($r)?->hasBlocking() ? 'Duyệt ngoại lệ: bài còn lỗi P0' : 'Duyệt nội dung')
            ->form(fn (Article $r) => ReviewActions::approveForm((int) $r->created_by === (int) ReviewActions::user()?->id, static::qualityOf($r)))
            ->action(function (Article $r, array $data) {
                abort_unless(ReviewActions::canApprove(), 403);
                ReviewActions::run(fn () => (new ReviewWorkflow)->approve(ReviewActions::user(), static::latest($r), $data['note'] ?? null, $data['override_reason'] ?? null), 'Đã duyệt nội dung');
            });
    }

    private static function rejectAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('reject')->label('Từ chối')->icon('heroicon-o-x-mark')->color('danger')
            ->visible(fn (Article $r) => $r->workflow_status === 'needs_review')
            ->authorize(fn () => ReviewActions::canApprove())
            ->form(ReviewActions::rejectForm())
            ->action(function (Article $r, array $data) {
                abort_unless(ReviewActions::canApprove(), 403);
                ReviewActions::run(fn () => (new ReviewWorkflow)->reject(ReviewActions::user(), static::latest($r), $data['reason'] ?? null), 'Đã từ chối bản thảo');
            });
    }

    private static function scheduleAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('schedule')->size('sm')->label('Xếp lịch')->icon('heroicon-o-clock')
            ->visible(fn (Article $r) => static::primaryActionFor($r) === 'schedule')
            ->authorize(fn () => ReviewActions::canApprove())
            ->form(ReviewActions::scheduleForm())
            ->action(function (Article $r, array $data) {
                abort_unless(ReviewActions::canApprove(), 403);
                ReviewActions::run(function () use ($r, $data) {
                    $website = Website::findOrFail($data['website_id']);
                    (new ReviewWorkflow)->schedule(ReviewActions::user(), static::latest($r), ReviewActions::parseVn($data['scheduled_at_vn']), $website);
                }, 'Đã xếp lịch xuất bản');
            });
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
