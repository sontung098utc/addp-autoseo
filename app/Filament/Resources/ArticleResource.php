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
use App\Models\ArticleVersion;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Readiness\ArticleReadiness;
use App\Services\Agent\Refresh\AgentRunDispatcher;
use App\Services\Agent\Run\BackgroundRun;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Brain\BrainContext;
use App\Services\Content\Quality\QualityReport;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Bài viết';

    protected static ?string $modelLabel = 'Bài viết';

    protected static ?string $pluralModelLabel = 'Bài viết';

    /** Quy ước giao diện: câu thường ("Chiến dịch tự động"), không để Filament tự Viết Hoa Từng Chữ ở tiêu đề và breadcrumb. */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?string $navigationGroup = 'Chiến dịch';

    protected static ?int $navigationSort = 1;

    protected static function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_active ? $user : null;
    }

    public static function canViewAny(): bool
    {
        return static::user()?->isWriter() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::user()?->isWriter() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::user()?->isWriter() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return static::user()?->isAdmin() ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return static::user()?->isAdmin() ?? false;
    }

    public const ASSIGN_DESCRIPTION = 'Đội AI sẽ kiểm an toàn, phân hạng rủi ro, lập dàn ý, viết, tự chấm và sửa (tối đa 3 vòng). Kết quả luôn là BẢN NHÁP chờ người duyệt; không có gì được tự đăng.';

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canAssignToAgent(Article $record): bool
    {
        return (Auth::user()?->isWriter() ?? false)
            && in_array($record->workflow_status, ['planned', 'drafting', 'rejected'], true)
            && ArticleRows::liveRun($record) === null;
    }

    /** Giao làm lại: bài đã có bản nháp để người duyệt nhận xét (chờ duyệt, cần sửa, đang viết dở có bản) và chưa có phiên chạy. */
    public static function canRedoWithAgent(Article $record): bool
    {
        return (Auth::user()?->isWriter() ?? false)
            && in_array($record->workflow_status, ['needs_review', 'rejected', 'drafting'], true)
            && ArticleRows::liveRun($record) === null
            && $record->versions()->exists();
    }

    /** Biểu mẫu lời nhắn của "Giao làm lại" (dùng chung cho bảng và trang bài). @return list<\Filament\Forms\Components\Component> */
    public static function redoForm(): array
    {
        return [
            Forms\Components\Textarea::make('message')->label('Lời nhắn cho điều phối')->required()->minLength(10)->maxLength(1500)->rows(5)
                ->placeholder('Ví dụ: Mở bài dài dòng; mục 2 thiếu số liệu; ảnh bìa chưa hợp chủ đề; giọng văn quá trang trọng…')
                ->helperText('Nói rõ chưa vừa ý ở đâu. Điều phối đọc lời nhắn, quyết định làm lại phần nào (dàn ý, bài viết, ảnh) và báo từng bộ phận. Bản mới được lưu thành phiên bản mới, bản cũ vẫn còn.'),
        ];
    }

    public const RISK_LABELS = ['low' => 'Thấp', 'medium' => 'Vừa', 'high' => 'Cao'];

    public const SCORE_NOTE = 'Chỉ đo luật hình thức (độ dài, tiêu đề, SEO, từ cấm...). Không đo độ đúng của nội dung.';

    public const GAP_NOTE = 'Bài này không có mục kiến thức nào từ kho làm căn cứ (AI viết khi chưa có tài liệu, hoặc chưa đọc được nguồn nào). Người có chuyên môn nên xem lại các nhận định; xem lượt "Tự đọc website" ở tab Hoạt động để biết vì sao chưa có tài liệu.';

    public const EXPERT_LABEL = 'Chờ chuyên gia xem lại';

    /** Tiền VND theo dạng "12.000 ₫". */
    public static function vnd(float|int $v): string
    {
        return number_format((float) $v, 0, ',', '.').' ₫';
    }

    /** Tóm tắt ngắn cho hộp thoại Giao việc, từ ArticleReadiness (một lần mỗi lần gọi). @return list<string> */
    public static function assignLines(array $r): array
    {
        $cost = $r['cost'];
        $lines = [];
        foreach ((array) ($r['auto_actions'] ?? []) as $a) {
            $lines[] = (string) $a;
        }
        $lines = [...$lines,
            'Rủi ro chủ đề: '.(self::RISK_LABELS[$r['risk']['level']] ?? $r['risk']['level']).'.',
            'Kho có '.$r['knowledge']['count'].' mục kiến thức đã duyệt khớp chủ đề.',
            'Chi phí: '.($cost['estimate_vnd'] === null ? 'chưa có số liệu để ước lượng' : 'khoảng '.self::vnd($cost['estimate_vnd'])).' · ngân sách phiên tối đa '.self::vnd($cost['budget_vnd']).'.',
        ];

        return $lines;
    }

    /** @return array<string, mixed> */
    public static function readiness(Article $record): array
    {
        return ArticleReadiness::for($record, $record->campaign_id);
    }

    public static function assignContent(Article $record): HtmlString
    {
        $r = static::readiness($record);
        $html = '<p>'.e(static::ASSIGN_DESCRIPTION).'</p><ul class="list-disc pl-5 mt-2 text-sm" data-testid="assign-summary">';
        foreach (static::assignLines($r) as $l) {
            $html .= '<li>'.e($l).'</li>';
        }
        $html .= '</ul>';

        return new HtmlString($html);
    }

    /** Giao bài cho đội AI (dùng chung cho bảng Bài viết và trang của bài); kết quả báo qua thông báo. */
    public static function assignToAgent(Article $record, bool $allowWithoutKnowledge = false /* không còn tác dụng, giữ để tương thích */, bool $background = false, ?string $redoMessage = null): ?string
    {
        abort_unless(Auth::user()?->isWriter(), 403);
        // Chống giao trùng: đã có phiên đang chạy thật thì không tạo phiên thứ hai.
        if (ArticleRows::liveRun($record) !== null) {
            Notification::make()->title('Bài này đang có phiên chạy')->body('Đợi phiên hiện tại xong rồi hãy giao lại.')->warning()->send();

            return AgentRunResource::getUrl('view', ['record' => ArticleRows::liveRun($record)]);
        }
        $ai = AgentAi::forInteractiveUse();
        if ($ai === null) {
            Notification::make()->title('Chưa kết nối AI')->body('Vào Đội AI → Kết nối AI để nhập khóa trước khi giao việc.')->warning()->send();

            return null;
        }
        // Chạy NỀN rồi chuyển sang trang luồng đang chạy (không treo hộp thoại hàng phút). Giao hàng loạt vẫn tuần tự đồng bộ.
        if ($background && BackgroundRun::available()) {
            $started = BackgroundRun::start($record, Auth::id(), redoMessage: $redoMessage);
            Notification::make()->title($redoMessage !== null ? 'Đã giao bài #'.$record->id.' làm lại' : 'Đã giao bài #'.$record->id.' cho đội AI')->body($started ? 'Phiên #'.$started->id.' đang chạy, theo dõi diễn biến ngay tại đây.' : 'Phiên đang khởi động, xem tiến trình ở trang bài.')->success()->send();

            return $started ? AgentRunResource::getUrl('view', ['record' => $started]) : static::getUrl('view', ['record' => $record]);
        }
        $run = AgentRunDispatcher::run($record, $ai, Auth::user(), policy: AgentAi::policy(), redo: $redoMessage !== null ? ['message' => $redoMessage] : null);
        $n = Notification::make()->title('Bài #'.$record->id.' · phiên #'.$run->id.': '.(AgentRunResource::STATUS[$run->status][0] ?? $run->status))
            ->body($run->outcome_reason ?: 'Đã có bản nháp chờ duyệt.')
            ->actions([Action::make('xem')->label('Xem diễn biến')->url(AgentRunResource::getUrl('view', ['record' => $run]))]);
        in_array($run->status, ['ready_for_review'], true) ? $n->success() : $n->warning();
        $n->send();

        return null;
    }

    public const BULK_MAX = 5;

    /** Giao hàng loạt: tuần tự, tối đa BULK_MAX bài mỗi lần (chạy đồng bộ). */
    private static function bulkAssignAction(): Tables\Actions\BulkAction
    {
        $eligible = fn (Collection $records) => $records->filter(fn (Article $a) => static::canAssignToAgent($a))->sortBy('id')->values();

        return Tables\Actions\BulkAction::make('assign_agent_bulk')
            ->label('Giao đội AI')->icon('heroicon-o-cpu-chip')
            ->visible(fn () => Auth::user()?->isWriter() ?? false)
            ->deselectRecordsAfterCompletion()
            ->modalHeading('Giao các bài đã chọn cho đội AI')
            ->modalSubmitActionLabel('Giao việc')
            ->modalDescription(function (Collection $records) use ($eligible) {
                $ok = $eligible($records);
                $run = $ok->take(static::BULK_MAX);
                $budget = (float) AgentAi::policy()->budgetVnd;
                $t = 'Đã chọn '.$records->count().' bài, giao được '.$run->count().' bài (chỉ bài Kế hoạch, Đang viết, Cần sửa và chưa có phiên chạy). Ngân sách tối đa '.static::vnd($run->count() * $budget).' ('.$run->count().' × '.static::vnd($budget).').';
                if ($ok->count() > static::BULK_MAX) {
                    $t .= ' Mỗi lần chỉ chạy '.static::BULK_MAX.' bài vì chạy tuần tự; '.($ok->count() - static::BULK_MAX).' bài còn lại sẽ KHÔNG được giao, hãy giao lượt sau.';
                }
                $t .= ' AI tự chuẩn bị kiến thức cho từng bài.';

                return $t;
            })
            ->action(function (Collection $records, array $data) use ($eligible) {
                abort_unless(Auth::user()?->isWriter(), 403);
                $ok = $eligible($records);
                foreach ($ok->take(static::BULK_MAX) as $a) {
                    static::assignToAgent($a);
                }
                if ($ok->count() > static::BULK_MAX) {
                    Notification::make()->title($ok->count() - static::BULK_MAX.' bài chưa được giao')->body('Mỗi lần chỉ giao tối đa '.static::BULK_MAX.' bài. Chọn lại các bài còn lại để giao lượt sau.')->warning()->send();
                }
                $skipped = $records->count() - $ok->count();
                if ($skipped > 0) {
                    Notification::make()->title($skipped.' bài không giao được')->body('Bài không ở trạng thái Kế hoạch, Đang viết, Cần sửa, hoặc đang có phiên chạy.')->warning()->send();
                }
            });
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Thông tin bài viết')
                    ->compact()
                    ->schema([
                        Forms\Components\Select::make('website_id')
                            ->label('Website đích')
                            ->relationship('website', 'name')
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('campaign_id', null))
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
                        Forms\Components\Select::make('campaign_id')
                            ->label('Chiến dịch')
                            ->placeholder('Bài lẻ — không thuộc chiến dịch')
                            ->options(fn (Get $get) => Campaign::query()->when($get('website_id'), fn ($q, $w) => $q->where('website_id', $w), fn ($q) => $q->whereRaw('1 = 0'))->orderBy('name')->pluck('name', 'id')->all())
                            ->default(fn () => request()->query('campaign') ?: null)
                            ->nullable()
                            ->live()
                            ->hintIcon('heroicon-o-information-circle', 'Để trống là bài lẻ. Chọn chiến dịch thì bài là bài con của chiến dịch đó và theo quy tắc của nó.'),
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

    /** Lời nói về kết quả của phiên chạy: cố ý KHÔNG dùng nhãn trạng thái của bài (như "Chờ duyệt"). */
    public const RUN_WORDS = [
        'running' => 'đang chạy', 'ready_for_review' => 'AI viết xong', 'held' => 'AI giữ lại bản nháp', 'blocked' => 'AI không viết được',
        'failed' => 'AI không viết được', 'abandoned' => 'đã bỏ',
    ];

    public static function runSummary(AgentRun $run): string
    {
        $score = ArticleRows::score($run);

        return 'Phiên #'.$run->id.' · '.(self::RUN_WORDS[$run->status] ?? $run->status).($score !== null ? ' · '.$score.' điểm' : '').' · Xem diễn biến';
    }

    /** Chiến dịch cha của bài (articles.campaign_id); null = bài lẻ. Cache theo bài trong request. */
    public static function campaignOf(Article $r): ?Campaign
    {
        return $r->campaign_id ? $r->campaign : null;
    }

    private static function narrow(Tables\Columns\Column $c): Tables\Columns\Column
    {
        return $c->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'width:1%;white-space:nowrap']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['website', 'latestVersion', 'campaign']))
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
                    ->description(fn (Article $r) => $r->latestVersion?->title ? Str::limit($r->latestVersion->title, 60) : null)
                    ->extraHeaderAttributes(['class' => 'ss-col-main'])
                    ->extraCellAttributes(['class' => 'ss-col-main']),
                static::narrow(Tables\Columns\TextColumn::make('workflow_status')->sortable()
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(function (?string $state, Article $r) {
                        return ArticleRows::statusBadge($r)[0];
                    })
                    ->color(fn (?string $state, Article $r) => ArticleRows::statusBadge($r)[1])
                    ->icon(fn (?string $state, Article $r) => ArticleRows::liveRun($r) ? 'heroicon-o-arrow-path' : StatusPresenter::icon($state, 'article'))
                    ->extraAttributes(fn (Article $r) => ArticleRows::liveRun($r) ? ['class' => 'ss-badge-live', 'data-running' => '1'] : [])),
                // Khung hẹp (< xl): cột Chiến dịch đầy đủ bị ẩn nên giữ một huy hiệu ngắn để vẫn thấy bài thuộc chiến dịch hay là bài lẻ.
                static::narrow(Tables\Columns\TextColumn::make('campaign_short')
                    ->label('Chiến dịch')
                    ->state(fn (Article $r) => static::campaignOf($r)?->name ?? 'Bài lẻ')
                    ->badge()
                    ->color(fn (Article $r) => static::campaignOf($r) ? 'info' : 'gray')
                    ->limit(10)
                    ->tooltip(fn (Article $r) => static::campaignOf($r)?->name ?? 'Không thuộc chiến dịch nào')
                    ->hiddenFrom('xl')),
                static::narrow(Tables\Columns\TextColumn::make('campaign_name')
                    ->label('Chiến dịch')
                    ->state(fn (Article $r) => static::campaignOf($r)?->name ?? 'Bài lẻ')
                    ->badge()
                    ->color(fn (Article $r) => static::campaignOf($r) ? 'info' : 'gray')
                    ->limit(16)
                    ->tooltip(fn (Article $r) => static::campaignOf($r) ? 'Mở chiến dịch' : 'Không thuộc chiến dịch nào')
                    ->url(fn (Article $r) => ($c = static::campaignOf($r)) && CampaignResource::canViewAny() ? CampaignResource::getUrl('view', ['record' => $c]) : null)
                    ->visibleFrom('xl')),
                static::narrow(Tables\Columns\TextColumn::make('brains')
                    ->label('Trí tuệ')
                    ->state(fn (Article $r) => ($n = BrainContext::forArticle($r, ArticleRows::lastRun($r)?->campaign_id)->names()) !== '' ? $n : '—')
                    ->limit(18)
                    ->tooltip(fn (Article $r) => 'Trí tuệ đang dùng: '.BrainContext::forArticle($r, ArticleRows::lastRun($r)?->campaign_id)->names())
                    ->visibleFrom('2xl')),
                static::narrow(Tables\Columns\TextColumn::make('last_run')
                    ->label('Phiên gần nhất')
                    ->state(fn (Article $r) => ($run = ArticleRows::lastRun($r)) ? static::runSummary($run) : '—')
                    ->limit(40)
                    ->tooltip(fn (Article $r) => ($run = ArticleRows::lastRun($r)) ? static::runSummary($run).' · bấm để xem diễn biến' : null)
                    ->url(fn (Article $r) => ($run = ArticleRows::lastRun($r)) && AgentRunResource::canViewAny() ? AgentRunResource::getUrl('view', ['record' => $run]) : null)
                    ->color('gray')
                    ->visibleFrom('2xl')),
                static::narrow(Tables\Columns\TextColumn::make('updated_at')->label('Cập nhật')->since()->sortable()
                    ->tooltip(fn (Article $r) => $r->updated_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i'))
                    ->visibleFrom('xl')),
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
                    ->options(fn () => ['solo' => 'Bài lẻ (không chiến dịch)'] + Campaign::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn ($query, array $data) => match (true) {
                        ! filled($data['value'] ?? null) => $query,
                        $data['value'] === 'solo' => $query->whereNull('articles.campaign_id'),
                        default => $query->where('articles.campaign_id', $data['value']),
                    }),
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
            // Bấm vào dòng là vào không gian bài viết (AG-S27), không phải form sửa.
            ->recordUrl(fn (Article $r) => static::getUrl('view', ['record' => $r]))
            ->actions([
                static::submitAction(),
                static::approveAction(),
                static::scheduleAction(),
                static::viewVersionAction(),
                Tables\Actions\ActionGroup::make([
                    static::rejectAction(),
                    Tables\Actions\Action::make('redo_agent')
                        ->label('Giao làm lại')
                        ->icon('heroicon-o-arrow-path')
                        ->visible(fn (Article $record) => static::canRedoWithAgent($record))
                        ->modalHeading('Giao bài này làm lại')
                        ->modalDescription('Chưa vừa ý với bản hiện tại? Gửi lời nhắn cho điều phối: đội AI làm lại theo đó và giữ những phần đã đạt. Mọi quy tắc an toàn, nguồn và từ cấm vẫn áp dụng.')
                        ->form(static::redoForm())
                        ->modalSubmitActionLabel('Giao làm lại')
                        ->action(function (Article $record, array $data, Tables\Actions\Action $action) {
                            $url = static::assignToAgent($record, background: true, redoMessage: (string) $data['message']);
                            $url ? $action->redirect($url) : null;
                        }),
                    Tables\Actions\Action::make('run_agent')
                        ->label('Giao đội AI')
                        ->icon('heroicon-o-cpu-chip')
                        ->visible(fn (Article $record) => static::canAssignToAgent($record))
                        ->modalHeading('Giao bài này cho đội AI')
                        ->modalDescription(fn (Article $record) => static::assignContent($record))
                        ->modalSubmitActionLabel('Giao việc')
                        ->action(function (Article $record, Tables\Actions\Action $action) {
                            $url = static::assignToAgent($record, background: true);
                            $url ? $action->redirect($url) : null;
                        }),
                ])->label('⋯')->icon('heroicon-m-ellipsis-horizontal')->iconButton()->tooltip('Thao tác khác'),
            ], position: Tables\Enums\ActionsPosition::AfterColumns)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::bulkAssignAction(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function viewVersionAction(): Tables\Actions\Action
    {
        // Cùng đích với việc bấm vào dòng: mọi lối "xem bài" ở màn này đều vào không gian bài viết.
        return Tables\Actions\Action::make('view_version')->size('sm')->label('Xem')->icon('heroicon-o-eye')->color('gray')
            ->visible(fn (Article $r) => static::primaryActionFor($r) === 'view_version' && $r->versions()->exists())
            ->url(fn (Article $r) => static::getUrl('view', ['record' => $r]));
    }

    /** Kiểm chất lượng bản mới nhất; null nếu bài chưa có bản nào. */
    private static function qualityOf(Article $record): ?QualityReport
    {
        $v = $record->versions()->orderByDesc('version')->first();

        return $v ? ReviewActions::qualityFor($v) : null;
    }

    private static function latest(Article $record): ArticleVersion
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
            ->disabled(fn (Article $r) => ArticleRows::liveRun($r) !== null)
            ->tooltip(fn (Article $r) => ArticleRows::liveRun($r) ? 'Đội AI đang viết lại bài, chờ xong rồi duyệt bản mới.' : ReviewActions::blockedHint(static::qualityOf($r)))
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
            ->disabled(fn (Article $r) => ArticleRows::liveRun($r) !== null)
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
            ->fillForm(fn (Article $r) => ['website_id' => $r->website_id])
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
            'view' => Pages\ViewArticle::route('/{record}'),
            // Đường dẫn sửa cũ cũng mở không gian bài viết: thông tin bài được sửa ngay trong tab Thông tin bài.
            'edit' => Pages\ViewArticle::route('/{record}/edit'),
        ];
    }
}
