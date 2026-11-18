<?php

namespace App\Filament\Resources;

use App\Filament\Clusters\AgentTeam;
use App\Filament\Resources\CampaignResource\Pages;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Brain;
use App\Models\Campaign;
use Carbon\CarbonImmutable;
use App\Models\CampaignWait;
use App\Models\User;
use App\Services\Agent\Run\CampaignRunner;
use App\Services\Agent\Settings\AgentAi;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\SubNavigationPosition;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

/** AG-S01: chiến dịch của đội AI. Người điều phối đặt từ khóa và giới hạn/ngày; hệ thống tự giao từng bài. */
class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationLabel = 'Chiến dịch';

    protected static ?string $cluster = AgentTeam::class;

    protected static ?string $slug = 'chien-dich';

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static ?string $modelLabel = 'Chiến dịch';

    protected static ?string $pluralModelLabel = 'Chiến dịch';

    /** Quy ước giao diện: câu thường ("Chiến dịch tự động"), không để Filament tự Viết Hoa Từng Chữ ở tiêu đề và breadcrumb. */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static ?int $navigationSort = 1;

    public const MODE_LABELS = ['cautious' => 'Duyệt trước · thận trọng', 'balanced' => 'Duyệt trước · cân bằng', 'autonomous' => 'Toàn quyền'];

    /** Mô tả một dòng cho từng điểm dừng chờ người (khóa lấy từ Campaign::CHECKPOINTS). */
    public const CHECKPOINT_HINTS = [
        'plan' => 'Đội AI đề xuất kế hoạch xong thì dừng, bạn duyệt danh sách bài rồi mới viết.',
        'outline' => 'Có dàn ý thì dừng, bạn xem dàn ý rồi mới viết tiếp.',
        'draft' => 'Có bản nháp thì dừng, bạn xem bản nháp rồi mới chấm và sửa tiếp.',
        'publish' => 'Bài đã duyệt vẫn chờ bạn bấm mới được đăng.',
    ];

    protected static function allowed(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    public static function canViewAny(): bool
    {
        return static::allowed();
    }

    public static function canCreate(): bool
    {
        return static::allowed();
    }

    public static function canEdit(Model $record): bool
    {
        return static::allowed();
    }

    public static function canDelete(Model $record): bool
    {
        return false; // có nhật ký phiên chạy gắn với chiến dịch; tạm dừng thay vì xóa
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Bộ cấp AI cho lượt chạy từ giao diện: AI thật nếu có khóa; chỉ môi trường local mới được dùng bản giả lập kịch bản. */
    public static function providerFactory(): \Closure
    {
        return fn () => AgentAi::forInteractiveUse();
    }

    /** Phiên bản văn bản docs/seo-agent/CHINH_SACH_TU_DONG_HOA.md mà người dùng xác nhận khi chọn Toàn quyền. */
    public const POLICY_VERSION = 'CHINH_SACH_TU_DONG_HOA@2026-11';

    private const POLICY_BOX = '<div class="ss-alert ss-alert--info" style="padding:.75rem 1rem"><strong>Toàn quyền cho đội AI nghĩa là:</strong><ul style="list-style:disc;margin:.4rem 0 0 1.25rem">'
        .'<li>Đội AI tự viết và tự duyệt các bài thường, bạn không cần bấm duyệt từng bài.</li>'
        .'<li>Bài sức khỏe hoặc rủi ro cao vẫn chờ người có chuyên môn xem; hệ thống không tự duyệt.</li>'
        .'<li>Hạn mức bài mỗi ngày, trần chi phí AI, trần bài nháp và nút dừng khẩn cấp luôn được áp dụng.</li>'
        .'<li>Ở bậc tin cậy T1, kết quả chỉ là bản nháp trên website của bạn, chưa công khai.</li></ul></div>';

    /** Ghi nhật ký (không có cột lưu riêng) ai xác nhận chính sách, lúc nào, phiên bản nào. */
    public static function logPolicyAck(Campaign $c): void
    {
        Log::info('campaign.autonomous_policy_ack', ['campaign_id' => $c->id, 'user_id' => Auth::id(), 'at' => now()->toIso8601String(), 'policy_version' => self::POLICY_VERSION]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Mục tiêu')->schema([
                Forms\Components\TextInput::make('name')->label('Tên chiến dịch')->required()->maxLength(120),
                Forms\Components\Select::make('website_id')->label('Website')->relationship('website', 'name')->required(),
                Forms\Components\TagsInput::make('keywords')->label('Từ khóa cần viết')->default([])->required(fn (Get $get): bool => $get('source') === 'keywords')
                    ->hintIcon('heroicon-o-information-circle', 'Mỗi từ khóa một bài, giao lần lượt; từ khóa website đã có bài sẽ bị bỏ qua. Chỉ bắt buộc khi nguồn từ khóa là danh sách nhập tay.'),
                Forms\Components\Select::make('brain_ids')->label('Trí tuệ được dùng')->multiple()->options(fn () => Brain::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->hintIcon('heroicon-o-information-circle', 'Để trống: dùng các Trí tuệ của website theo thứ tự ưu tiên. Chọn nhiều thì thứ tự chọn là thứ tự ưu tiên; AI lấy giọng, khung bài và kiến thức từ các Trí tuệ này.')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('settings.concurrency')->label('Số bài viết song song')->numeric()->minValue(1)->maxValue(5)->default((int) config('agent.campaign_concurrency', 3))
                    ->hintIcon('heroicon-o-information-circle', 'Số bài đội AI viết CÙNG LÚC cho chiến dịch này (1–5). Nhiều hơn thì nhanh hơn nhưng tốn hạn mức AI nhanh hơn và dễ gặp giới hạn tần suất. Vẫn bị chặn bởi "Tối đa bài mỗi ngày" và trần chi phí.'),
                Forms\Components\TextInput::make('daily_limit')->label('Tối đa bài mỗi ngày')->numeric()->minValue(1)->maxValue(50)->default(3)->required()
                    ->hintIcon('heroicon-o-information-circle', 'Chống sản xuất hàng loạt: quá giới hạn thì dừng giao bài tới ngày mai (giờ Việt Nam).'),
            ])->columns(2),
            Forms\Components\Section::make('Chế độ')->schema([
                Forms\Components\Radio::make('mode')->hiddenLabel()->default('balanced')->required()->live()
                    ->options([
                        'cautious' => 'Duyệt trước · thận trọng (ít tự động nhất)',
                        'balanced' => 'Duyệt trước · cân bằng (khuyến nghị)',
                        'autonomous' => 'Toàn quyền cho đội AI',
                    ])
                    ->descriptions([
                        'cautious' => 'AI viết và tự sửa, chỉ lưu nội bộ. Bạn xem và bấm đẩy lên website.',
                        'balanced' => 'AI viết, đẩy bản nháp lên website (không công khai). Bạn xem rồi duyệt.',
                        'autonomous' => 'AI tự lấy bài, tự viết và tự duyệt bài thường. Bài sức khỏe/rủi ro cao vẫn chờ người có chuyên môn.',
                    ])
                    ->in(['cautious', 'balanced', 'autonomous']),
                Forms\Components\Placeholder::make('autonomous_policy')->label('Tóm tắt chính sách Toàn quyền')->hiddenLabel()->columnSpanFull()
                    ->visible(fn (Get $get) => $get('mode') === 'autonomous')
                    ->content(new HtmlString(self::POLICY_BOX)),
                Forms\Components\Checkbox::make('policy_ack')->label('Tôi đã đọc và đồng ý với tóm tắt chính sách trên')->columnSpanFull()
                    ->visible(fn (Get $get) => $get('mode') === 'autonomous')
                    ->dehydrated(false)
                    ->accepted(fn (Get $get, ?Model $record) => $get('mode') === 'autonomous' && $record?->mode !== 'autonomous')
                    ->validationMessages(['accepted' => 'Cần tích "Tôi đã đọc" để bật chế độ Toàn quyền.'])
                    ->helperText(fn (?Model $record) => $record?->mode === 'autonomous' ? 'Chiến dịch này đã xác nhận chính sách trước đó.' : null)
                    ->default(fn (?Model $record) => $record?->mode === 'autonomous'),
                Forms\Components\Select::make('source')->label('Nguồn từ khóa')->options(['plan' => 'Kế hoạch nội dung (hết thì dùng danh sách nhập tay)', 'keywords' => 'Danh sách nhập tay'])->default('plan')->live()->required()
                    ->hintIcon('heroicon-o-information-circle', 'Kế hoạch nội dung: lấy bài đã lên kế hoạch trước. Danh sách nhập tay: chỉ dùng các từ khóa bạn nhập ở trên.'),
                Forms\Components\TextInput::make('daily_cost_cap_vnd')->label('Trần chi phí AI mỗi ngày (đ)')->numeric()->minValue(0)->maxValue(100000000)->default(150000)->required()
                    ->hintIcon('heroicon-o-information-circle', 'Chạm trần thì chiến dịch dừng giao bài tới ngày mai (giờ Việt Nam).'),
                Forms\Components\TextInput::make('daily_draft_cap')->label('Trần bài đẩy nháp mỗi ngày')->numeric()->minValue(1)->maxValue(50)->default(5)->required()
                    ->hintIcon('heroicon-o-information-circle', 'Số bản nháp tối đa được đẩy sang hàng chờ duyệt mỗi ngày.'),
            ])->columns(2),
            Forms\Components\Section::make('Điểm dừng chờ người')->schema([
                Forms\Components\CheckboxList::make('checkpoints')->label('Dừng ở giai đoạn')->hintIcon('heroicon-o-information-circle', 'Không tick: đội AI chạy liền mạch trong hạn mức. Tick giai đoạn nào thì dừng ở đó chờ bạn.')->options(Campaign::CHECKPOINTS)->descriptions(self::CHECKPOINT_HINTS)->default([])
                    ->columns(1)->bulkToggleable(false),
            ])->compact(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('website')->withCount(['articles as articles_total', 'articles as articles_planned' => fn ($q) => $q->where('workflow_status', 'planned'), 'articles as articles_review' => fn ($q) => $q->where('workflow_status', 'needs_review'), 'runs as runs_total', 'runs as runs_review' => fn ($q) => $q->where('status', 'ready_for_review'), 'runs as runs_held' => fn ($q) => $q->where('status', 'held')]))
            ->defaultSort('id', 'desc')
            ->poll(fn () => RunActivity::anyRunning() ? '3s' : '20s')
            ->recordClasses('ss-sticky-row')
            ->recordUrl(fn (Campaign $r) => static::getUrl('view', ['record' => $r]))
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Chiến dịch')->searchable()->wrap()->description(fn (Campaign $r) => $r->website?->name.' · Đã giao '.(int) $r->runs_total.' bài · '.(int) $r->runs_review.' chờ duyệt · '.(int) $r->runs_held.' giữ nháp')
                    ->extraHeaderAttributes(['class' => 'ss-col-main'])->extraCellAttributes(['class' => 'ss-col-main']),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (string $state, Campaign $r) => self::stateOf($r)['label'])
                    ->color(fn (string $state, Campaign $r) => self::stateOf($r)['tone'])
                    ->icon(fn (Campaign $r) => self::liveRunCount($r) > 0 ? 'heroicon-o-arrow-path' : null)
                    ->extraAttributes(fn (Campaign $r) => self::liveRunCount($r) > 0 ? ['class' => 'ss-badge-live', 'data-running' => '1'] : [])
                    ->description(fn (Campaign $r) => self::stateOf($r)['hint'])
                    ->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'white-space:nowrap']),
                Tables\Columns\TextColumn::make('progress')->label('Bài con')->state(fn (Campaign $r) => (int) $r->articles_total.' bài · '.(int) $r->articles_planned.' kế hoạch · '.(int) $r->articles_review.' chờ duyệt')->description(fn (Campaign $r) => self::MODE_LABELS[$r->mode] ?? $r->mode)->visibleFrom('md')
                    ->extraHeaderAttributes(['style' => 'white-space:nowrap'])->extraCellAttributes(['style' => 'white-space:nowrap']),
                Tables\Columns\TextColumn::make('today')->label('Hôm nay')->visibleFrom('xl')->state(fn (Campaign $r) => self::todayCount($r).' / '.$r->daily_limit)->alignCenter()
                    ->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'width:1%;white-space:nowrap']),
                Tables\Columns\TextColumn::make('left')->label('Từ khóa còn lại')->visibleFrom('2xl')->state(fn (Campaign $r) => self::remaining($r))->alignCenter()
                    ->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'width:1%;white-space:nowrap']),
                Tables\Columns\TextColumn::make('last_tick_at')->label('Nhịp gần nhất (giờ VN)')->visibleFrom('2xl')->placeholder('Chưa chạy')
                    ->formatStateUsing(fn (Campaign $r) => VnTime::format(VnTime::fromRaw($r, 'last_tick_at')))
                    ->extraHeaderAttributes(['style' => 'width:1%;white-space:nowrap'])->extraCellAttributes(['style' => 'white-space:nowrap']),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Cấu hình'),
                Tables\Actions\Action::make('start')->label('Bắt đầu chạy')->icon('heroicon-o-play')
                    ->visible(fn (Campaign $r) => $r->status === 'paused' && static::allowed())
                    ->requiresConfirmation()->modalHeading('Bắt đầu chạy chiến dịch')->modalDescription(fn (Campaign $r) => self::startDescription($r))
                    ->action(fn (Campaign $r) => static::start($r)),
                Tables\Actions\Action::make('pause')->label('Tạm dừng')->icon('heroicon-o-pause')->color('gray')
                    ->visible(fn (Campaign $r) => $r->status === 'active' && static::allowed())
                    ->action(fn (Campaign $r) => static::pause($r)),
                Tables\Actions\Action::make('tick')->label('Giao bài ngay')->icon('heroicon-o-forward')->color('gray')
                    ->visible(fn (Campaign $r) => $r->status === 'active' && static::allowed())
                    ->requiresConfirmation()->modalDescription('Lấp ngay các chỗ trống: giao các bài kế tiếp cho đội AI viết song song (tối đa theo cấu hình chiến dịch), không chờ lượt tự động. Vẫn trong giới hạn bài/ngày. Bài thường ở chế độ Toàn quyền được đội AI tự duyệt; còn lại là bản nháp chờ duyệt.')
                    ->action(fn (Campaign $r) => static::runTick($r)),
            ]);
    }

    /** Lời giải thích ở hộp xác nhận "Bắt đầu chạy": nói rõ sẽ viết bài nào, tối đa bao nhiêu bài/ngày, và hệ thống sẽ làm gì tiếp. */
    public static function startDescription(Campaign $c): string
    {
        $next = $c->source === 'plan' ? Article::query()->where('campaign_id', $c->id)->where('workflow_status', 'planned')->orderByDesc('priority')->orderBy('id')->value('primary_keyword') : null;

        return 'Giao ngay '.($next ? 'bài «'.$next.'»' : 'bài kế tiếp').' cho đội AI viết, cùng lúc tối đa '.CampaignRunner::concurrency($c).' bài song song. Bài nào xong thì tự giao bài kế tiếp vào chỗ trống, tối đa '.(int) $c->daily_limit.' bài mỗi ngày và trong trần chi phí AI '.number_format((int) $c->daily_cost_cap_vnd, 0, ',', '.').' đ/ngày. Bạn có thể tạm dừng bất cứ lúc nào. Chưa có gì tự đăng công khai.';
    }

    /** Hệ thống tự giao một bài mỗi chừng này phút (khớp lịch agent:tick trong routes/console.php). */
    public const TICK_MINUTES = 15;

    /**
     * Trạng thái THẬT của chiến dịch cho người dùng. "Đang bật" chỉ nghĩa là được phép tự giao bài; chỉ "Đang viết N bài" mới là đội AI đang làm.
     *
     * @return array{key:string, label:string, tone:string, hint:?string}
     */
    public static function stateOf(Campaign $c): array
    {
        if ($c->status !== 'active') {
            return ['key' => 'paused', 'label' => 'Tạm dừng', 'tone' => 'gray', 'hint' => null];
        }
        if (($live = self::liveRunCount($c)) > 0) {
            return ['key' => 'writing', 'label' => 'Đang viết '.$live.' bài', 'tone' => 'success', 'hint' => null];
        }
        if (self::todayCount($c) >= (int) $c->daily_limit) {
            return ['key' => 'limit', 'label' => 'Đã đủ bài hôm nay', 'tone' => 'warning', 'hint' => 'Tiếp tục vào ngày mai (giờ Việt Nam).'];
        }
        $last = $c->last_tick_at ? CarbonImmutable::parse($c->last_tick_at) : null;
        if ($last === null || $last->lt(CarbonImmutable::now()->subMinutes(self::TICK_MINUTES * 2))) {
            return ['key' => 'no_tick', 'label' => 'Đã bật, chưa có lượt tự động', 'tone' => 'warning',
                'hint' => 'Chưa thấy lịch chạy nền hoạt động'.($last ? ' từ '.VnTime::format(VnTime::fromRaw($c, 'last_tick_at')) : '').'. Bấm "Giao 1 bài ngay" để chạy, hoặc bật lịch chạy nền (scheduler).'];
        }

        return ['key' => 'waiting', 'label' => 'Đã bật, chờ giao bài tiếp', 'tone' => 'info', 'hint' => 'Hệ thống tự giao bài kế tiếp mỗi '.self::TICK_MINUTES.' phút.'];
    }

    /** Bấm "Bắt đầu chạy": bật chiến dịch VÀ giao ngay bài kế tiếp cho đội AI (không chờ lịch); sau đó hệ thống tiếp tục tự giao. */
    public static function start(Campaign $c): void
    {
        abort_unless(static::canEdit($c) && static::allowed() && in_array($c->status, ['active', 'paused'], true), 403);
        $c->update(['status' => 'active']);
        static::runTick($c->refresh());
    }

    public static function pause(Campaign $c): void
    {
        abort_unless(static::canEdit($c) && in_array($c->status, ['active', 'paused'], true), 403);
        $c->update(['status' => 'paused']);
        Notification::make()->title('Đã tạm dừng chiến dịch')->body('Không giao thêm bài. Bài đang viết dở vẫn chạy nốt.')->success()->send();
    }

    /** Chạy một nhịp ngay (dùng chung cho bảng và trang chiến dịch); kết quả báo qua thông báo. */
    public static function runTick(Campaign $r): void
    {
        abort_unless(static::allowed(), 403);
        $res = (new CampaignRunner(static::providerFactory()))->tick($r);
        $n = Notification::make()->title($res['message']);
        in_array($res['result'], ['ran', 'auto_approved'], true) ? $n->success() : $n->warning();
        $n->send();
    }

    /** Số bài của chiến dịch theo trạng thái. @return array<string,int> */
    public static function articleCounts(Campaign $c): array
    {
        return Article::query()->where('campaign_id', $c->id)->selectRaw('workflow_status, count(*) as n')->groupBy('workflow_status')->pluck('n', 'workflow_status')->map(fn ($n) => (int) $n)->all();
    }

    /** Số phiên đang chạy thật (chưa quá hạn treo) của chiến dịch; cache theo request để không truy vấn lặp. */
    public static function liveRunCount(Campaign $c): int
    {
        static $cache = [];

        return $cache[$c->id.'|'.spl_object_id($c)] ??= AgentRun::query()->where('campaign_id', $c->id)->where('status', 'running')->with('steps')->get()
            ->filter(fn (AgentRun $r) => RunActivity::isLive($r))->count();
    }

    public static function todayCount(Campaign $c): int
    {
        [$from, $to] = VnTime::todayRangeApp();

        return AgentRun::query()->where('campaign_id', $c->id)->where('started_at', '>=', $from)->where('started_at', '<', $to)->count();
    }

    public static function remaining(Campaign $c): int
    {
        $used = Article::query()->where('website_id', $c->website_id)->pluck('primary_keyword')->map(fn ($k) => mb_strtolower(trim($k)))->all();

        return count(array_filter((array) $c->keywords, fn ($k) => trim((string) $k) !== '' && ! in_array(mb_strtolower(trim((string) $k)), $used, true)));
    }

    /** @return Collection<int, CampaignWait> */
    public static function openWaits()
    {
        return CampaignWait::query()->with('campaign')->where('status', 'open')->where('expires_at', '>', now())->orderBy('expires_at')->get();
    }

    public static function recentExpired()
    {
        return CampaignWait::query()->where('status', 'expired')->where('updated_at', '>=', now()->subDays(2))->latest('updated_at')->limit(5)->get();
    }

    /** Hạn mức hôm nay (giờ VN) cộng các chiến dịch đang chạy. */
    public static function budget(): array
    {
        [$from, $to] = VnTime::todayRangeApp();
        $active = Campaign::query()->where('status', 'active');
        $ids = (clone $active)->pluck('id');
        $runs = AgentRun::query()->whereIn('campaign_id', $ids)->where('started_at', '>=', $from)->where('started_at', '<', $to);
        $cost = (float) (clone $runs)->sum('cost_vnd');
        $drafts = (clone $runs)->where('status', 'ready_for_review')->count();
        $costCap = (int) (clone $active)->sum('daily_cost_cap_vnd');
        $draftCap = (int) (clone $active)->sum('daily_draft_cap');

        return [
            'cost' => $cost, 'cost_cap' => $costCap, 'cost_pct' => $costCap > 0 ? min(100, round($cost / $costCap * 100)) : 0,
            'drafts' => $drafts, 'draft_cap' => $draftCap, 'draft_pct' => $draftCap > 0 ? min(100, round($drafts / $draftCap * 100)) : 0,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'view' => Pages\ViewCampaign::route('/{record}'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }
}
