<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CampaignResource\Pages;
use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Services\Agent\Run\CampaignRunner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** AG-S01: chiến dịch của đội AI. Người điều phối đặt từ khóa và giới hạn/ngày; hệ thống tự giao từng bài. */
class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationLabel = 'Chiến dịch';

    protected static ?string $cluster = \App\Filament\Clusters\AgentTeam::class;

    protected static ?string $slug = 'chien-dich';

    protected static \Filament\Pages\SubNavigationPosition $subNavigationPosition = \Filament\Pages\SubNavigationPosition::Top;

    protected static ?string $modelLabel = 'Chiến dịch';

    protected static ?string $pluralModelLabel = 'Chiến dịch tự động';

    protected static ?int $navigationSort = 2;

    protected static function allowed(): bool
    {
        $u = Auth::user();

        return $u instanceof \App\Models\User && $u->is_active && $u->isApprover();
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
        return fn () => \App\Services\Agent\Settings\AgentAi::forInteractiveUse();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Mục tiêu')->schema([
                Forms\Components\TextInput::make('name')->label('Tên chiến dịch')->required()->maxLength(120),
                Forms\Components\Select::make('website_id')->label('Website')->relationship('website', 'name')->required(),
                Forms\Components\TagsInput::make('keywords')->label('Từ khóa cần viết')->required()
                    ->helperText('Mỗi từ khóa một bài. Hệ thống giao lần lượt, bỏ qua từ khóa website đã có bài.'),
                Forms\Components\TextInput::make('daily_limit')->label('Tối đa bài mỗi ngày')->numeric()->minValue(1)->maxValue(50)->default(3)->required()
                    ->helperText('Rào chắn nội dung hàng loạt: quá giới hạn thì hệ thống dừng giao việc tới ngày mai (giờ Việt Nam).'),
                Forms\Components\Select::make('status')->label('Trạng thái')->options(['paused' => 'Tạm dừng', 'active' => 'Đang chạy'])->default('paused')->required()
                    ->helperText('Đang chạy: hệ thống tự giao việc theo lịch. Kết quả luôn là bản nháp chờ người duyệt.'),
            ])->columns(2),
            Forms\Components\Section::make('Chế độ')->description('Chọn một chế độ là đủ. AI tự chạy; kết quả luôn là bản nháp, chưa có gì tự đăng.')->schema([
                Forms\Components\Radio::make('mode')->hiddenLabel()->default('balanced')->required()
                    ->options([
                        'cautious' => 'Thận trọng · ít tự động nhất',
                        'balanced' => 'Cân bằng · khuyến nghị',
                        'autonomous' => 'Tự chủ · chưa mở',
                    ])
                    ->descriptions([
                        'cautious' => 'AI viết và tự sửa, chỉ lưu nội bộ. Bạn bấm để đẩy lên website.',
                        'balanced' => 'AI tự đẩy bài lên website ở dạng nháp. Bạn xem và bấm đăng. (Hiện mới gắn nhãn chế độ; đẩy nháp lên CMS đang phát triển.)',
                        'autonomous' => 'AI tự đăng chủ đề an toàn, kiểm mẫu sau đăng, tự gỡ khi lỗi. Cần: văn bản chính sách của bạn + connector hỗ trợ gỡ bài.',
                    ])
                    ->disableOptionWhen(fn (string $value) => $value === 'autonomous')
                    ->in(['cautious', 'balanced'])
                    ->helperText('Tự chủ đang khóa: cần văn bản chính sách của bạn + connector hỗ trợ gỡ bài.'),
                Forms\Components\Select::make('source')->label('Nguồn bài cần viết')->options(['plan' => 'Ưu tiên bài đã lên kế hoạch, hết thì dùng từ khóa nhập tay', 'keywords' => 'Chỉ dùng danh sách từ khóa nhập tay'])->default('plan')->required(),
                Forms\Components\TextInput::make('daily_cost_cap_vnd')->label('Trần chi phí AI mỗi ngày (đ)')->numeric()->minValue(0)->maxValue(100000000)->default(150000)->required()
                    ->helperText('Chạm trần thì chiến dịch dừng giao việc tới ngày mai (giờ Việt Nam).'),
                Forms\Components\TextInput::make('daily_draft_cap')->label('Trần bài đẩy nháp mỗi ngày')->numeric()->minValue(1)->maxValue(50)->default(5)->required(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('website'))
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Chiến dịch')->searchable()->description(fn (Campaign $r) => $r->website?->name),
                Tables\Columns\TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'active' ? 'Đang chạy' : 'Tạm dừng')
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('today')->label('Hôm nay')->state(fn (Campaign $r) => self::todayCount($r).' / '.$r->daily_limit)->alignCenter(),
                Tables\Columns\TextColumn::make('left')->label('Từ khóa còn lại')->state(fn (Campaign $r) => self::remaining($r))->alignCenter(),
                Tables\Columns\TextColumn::make('last_tick_at')->label('Nhịp gần nhất (giờ VN)')->placeholder('Chưa chạy')
                    ->formatStateUsing(fn (Campaign $r) => VnTime::format(VnTime::fromRaw($r, 'last_tick_at'))),
            ])
            ->actions([
                Tables\Actions\Action::make('tick')->label('Chạy một nhịp ngay')->icon('heroicon-o-play')
                    ->visible(fn (Campaign $r) => $r->status === 'active' && static::allowed())
                    ->requiresConfirmation()->modalDescription('Giao từ khóa kế tiếp cho đội AI ngay bây giờ, vẫn trong giới hạn bài/ngày. Kết quả là bản nháp chờ duyệt.')
                    ->action(function (Campaign $r) {
                        abort_unless(static::allowed(), 403);
                        $res = (new CampaignRunner(static::providerFactory()))->tick($r);
                        $n = Notification::make()->title($res['message']);
                        $res['result'] === 'ran' ? $n->success() : $n->warning();
                        $n->send();
                    }),
                Tables\Actions\EditAction::make()->label('Sửa'),
            ]);
    }

    public static function todayCount(Campaign $c): int
    {
        [$from, $to] = VnTime::todayRangeUtc();

        return AgentRun::query()->where('campaign_id', $c->id)->where('started_at', '>=', $from)->where('started_at', '<', $to)->count();
    }

    public static function remaining(Campaign $c): int
    {
        $used = \App\Models\Article::query()->where('website_id', $c->website_id)->pluck('primary_keyword')->map(fn ($k) => mb_strtolower(trim($k)))->all();

        return count(array_filter((array) $c->keywords, fn ($k) => trim((string) $k) !== '' && ! in_array(mb_strtolower(trim((string) $k)), $used, true)));
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\CampaignWait> */
    public static function openWaits()
    {
        return \App\Models\CampaignWait::query()->with('campaign')->where('status', 'open')->where('expires_at', '>', now())->orderBy('expires_at')->get();
    }

    public static function recentExpired()
    {
        return \App\Models\CampaignWait::query()->where('status', 'expired')->where('updated_at', '>=', now()->subDays(2))->latest('updated_at')->limit(5)->get();
    }

    /** Hạn mức hôm nay (giờ VN) cộng các chiến dịch đang chạy. */
    public static function budget(): array
    {
        [$from, $to] = VnTime::todayRangeUtc();
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
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }
}
