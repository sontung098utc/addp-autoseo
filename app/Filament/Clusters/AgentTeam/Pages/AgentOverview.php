<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Filament\Pages\AiUsagePage;
use App\Filament\Pages\BrainPage;
use App\Filament\Pages\BrandProfilePage;
use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\PublicationResource;
use App\Filament\Resources\WebsiteResource;
use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\ArticleVersion;
use App\Models\BrandProfile;
use App\Models\Campaign;
use App\Models\CampaignWait;
use App\Models\CrawledUrl;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Brain\BrainService;
use App\Services\Brand\BrandProfileData;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/** Tab "Tổng quan" của Đội AI: trạng thái một dòng, việc CẦN bạn, các phiên đang chạy, số liệu hôm nay. Luồng và phần Dev1 cần xây để trong cửa sổ phụ. */
class AgentOverview extends Page
{
    protected static ?string $cluster = AgentTeam::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Tổng quan';

    protected static ?string $title = 'Đội AI: tổng quan';

    protected static ?string $slug = 'tong-quan';

    protected static ?int $navigationSort = 0;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string $view = 'filament.clusters.agent-team.overview';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('flow')->label('Luồng & phần còn thiếu')->icon('heroicon-o-map')->color('gray')
                ->modalHeading('Luồng của đội AI')->modalWidth('5xl')
                ->modalContent(fn () => view('filament.clusters.agent-team.flow-modal', $this->flowData()))
                ->modalSubmitAction(false)->modalCancelActionLabel('Đóng'),
        ];
    }

    /** Dữ liệu cho cửa sổ "Luồng & phần còn thiếu" (chỉ để tham khảo, không nằm trên trang chính). */
    protected function flowData(): array
    {
        $since = now()->subDays(30);
        $byStatus = AgentRun::query()->where('created_at', '>=', $since)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->all();
        $stepStats = AgentRunStep::query()->whereHas('run', fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw('stage, status, count(*) c')->groupBy('stage', 'status')->get()->groupBy('stage');
        $nodes = array_map(function (array $b) use ($stepStats): array {
            $b['stats'] = ($stepStats->get($b['stage']) ?? collect())->pluck('c', 'status')->all();

            return $b;
        }, AgentFlowBlueprint::stages());
        $active = Campaign::query()->where('status', 'active')->get();

        return [
            'nodes' => $nodes,
            'total' => array_sum($byStatus),
            'life' => [
                ['label' => 'Từ khóa chưa giao', 'n' => $active->sum(fn ($c) => CampaignResource::remaining($c)), 'url' => CampaignResource::getUrl('index'), 'who' => 'Chiến dịch'],
                ['label' => 'Đội AI giữ nháp', 'n' => $byStatus['held'] ?? 0, 'url' => AgentRunResource::getUrl('index'), 'who' => 'Cần người xem'],
                ['label' => 'Chờ duyệt', 'n' => ArticleVersion::query()->where('review_status', 'needs_review')->count(), 'url' => ArticleVersionResource::getUrl('index'), 'who' => 'Người duyệt'],
                ['label' => 'Đã xếp lịch', 'n' => Publication::query()->where('status', 'scheduled')->count(), 'url' => PublicationResource::getUrl('index'), 'who' => 'Hệ thống đăng'],
                ['label' => 'Đã đăng', 'n' => Publication::query()->where('status', 'published')->count(), 'url' => PublicationResource::getUrl('index'), 'who' => 'CMS'],
            ],
        ];
    }

    protected function getViewData(): array
    {
        $ai = AgentAi::status();
        $liveCount = RunActivity::liveCount();
        $steps = $this->setupSteps($ai);
        [$from, $to] = VnTime::todayRangeUtc();
        $today = AgentRun::query()->where('started_at', '>=', $from)->where('started_at', '<', $to);
        $costCap = Schema::hasColumn('campaigns', 'daily_cost_cap_vnd') ? (float) Campaign::query()->where('status', 'active')->sum('daily_cost_cap_vnd') : 0.0;

        $week = now()->subDays(7);
        $failed = AgentRun::query()->where('status', 'failed')->where('created_at', '>=', $week)->count();
        $held = AgentRun::query()->where('status', 'held')->where('created_at', '>=', $week)->count();
        $stale = AgentRun::query()->where('status', 'running')->get()->filter(fn ($r) => RunActivity::isStale($r))->count();
        $drafts = ArticleVersion::query()->where('review_status', 'needs_review')->count();
        $waits = Schema::hasTable('campaign_waits') ? CampaignWait::query()->where('status', 'open')->where('expires_at', '>', now())->count() : 0;

        // Chỉ liệt kê điều CẦN bạn; xếp theo mức nghiêm trọng. Không có gì thì trang chỉ báo bình thường, không nhồi số liệu.
        $attention = array_values(array_filter([
            $failed > 0 ? ['tone' => 'danger', 'text' => "{$failed} phiên bị lỗi trong 7 ngày qua", 'action' => 'Xem', 'url' => AgentRunResource::getUrl('index')] : null,
            $stale > 0 ? ['tone' => 'danger', 'text' => "{$stale} phiên có thể bị treo (lâu không có bước mới)", 'action' => 'Xem', 'url' => AgentRunResource::getUrl('index')] : null,
            ! $ai['connected'] ? ['tone' => 'warning', 'text' => 'Chưa kết nối AI, lượt tự động không chạy', 'action' => 'Nhập khóa', 'url' => AiUsagePage::configUrl()] : null,
            $held > 0 ? ['tone' => 'warning', 'text' => "{$held} phiên giữ nháp, cần người xem", 'action' => 'Xem', 'url' => AgentRunResource::getUrl('index')] : null,
            $waits > 0 ? ['tone' => 'info', 'text' => "{$waits} việc đang chờ bạn bổ sung (không chặn việc khác)", 'action' => 'Xem', 'url' => CampaignResource::getUrl('index')] : null,
            $drafts > 0 ? ['tone' => 'primary', 'text' => "{$drafts} bản nháp chờ bạn duyệt", 'action' => 'Duyệt', 'url' => ArticleVersionResource::getUrl('index')] : null,
        ]));

        return [
            'ai' => $ai,
            'liveCount' => $liveCount,
            'live' => RunActivity::liveRuns(5),
            'pollSeconds' => $liveCount > 0 ? RunActivity::POLL_LIVE : RunActivity::POLL_IDLE,
            'attention' => $attention,
            'today' => [
                'runs' => (clone $today)->count(),
                'toReview' => (clone $today)->where('status', 'ready_for_review')->count(),
                'cost' => (float) (clone $today)->sum('cost_vnd'),
                'cap' => $costCap,
            ],
            'steps' => $steps,
            'stepsLeft' => collect($steps)->where('state', '!=', 'done')->count(),
            'runsUrl' => AgentRunResource::getUrl('index'),
            'stages' => AgentFlowBlueprint::byStage(),
        ];
    }

    /** AG-S19: tính THẬT theo hồ sơ của các website đang hoạt động: done khi mọi website hoạt động đều có hồ sơ đủ trường bắt buộc. */
    private function brandStep(int $activeSites): array
    {
        // AG-B2: hồ sơ doanh nghiệp nằm trong Trí tuệ Thương hiệu. Một website đã có Trí tuệ thương hiệu → mở thẳng tab; còn lại → URL cũ (tự chuyển hướng, tạo khi cần).
        $url = BrandProfilePage::getUrl();
        if ($activeSites === 1 && Schema::hasTable('brains')) {
            $site = Website::query()->where('status', 'active')->first();
            $brain = $site ? (new BrainService)->findBrandBrain($site) : null;
            $url = $brain ? BrainPage::getUrl(['brain' => $brain->id]).'#tab-brand' : $url;
        }
        $profiles = Schema::hasTable('brand_profiles')
            ? BrandProfile::query()->whereIn('website_id', Website::query()->where('status', 'active')->select('id'))->get()
            : collect();
        $complete = $profiles->filter(fn ($p) => BrandProfileData::isComplete($p))->count();
        $done = $activeSites > 0 && $complete >= $activeSites;
        $note = $activeSites === 0 ? 'Cần có website hoạt động trước.'
            : ($done ? "Cả {$complete} website hoạt động đã có hồ sơ đủ." : "Mới {$complete}/{$activeSites} website có hồ sơ đủ ({$profiles->count()} đã bắt đầu điền). Bấm để điền.");

        return ['n' => 2, 'title' => 'Hồ sơ doanh nghiệp', 'state' => $done ? 'done' : 'todo', 'note' => $note, 'url' => $url];
    }

    /**
     * "Hoàn tất cài đặt" 5 bước. state: done (có dữ liệu thật) | todo (chưa làm) | default (đang dùng mặc định, chưa chọn).
     * Bước chưa có dữ liệu (nguồn AI AG-A03) luôn là todo, không tick giả.
     *
     * @param  array<string, mixed>  $ai
     * @return list<array<string, mixed>>
     */
    private function setupSteps(array $ai): array
    {
        $sites = Website::query()->where('status', 'active')->count();
        $crawled = CrawledUrl::query()->count();
        $firstRun = AgentRun::query()->exists();

        // Cột campaigns.mode do AG-S02 thêm. Chưa có cột thì chỉ báo mặc định, không tự thêm.
        $hasMode = Schema::hasColumn('campaigns', 'mode');
        $modeChosen = $hasMode && Campaign::query()->whereNotNull('mode')->exists();

        return [
            ['n' => 1, 'title' => 'Nối website', 'state' => $sites > 0 ? 'done' : 'todo',
                'note' => $sites > 0 ? "{$sites} website đang hoạt động." : 'Chưa có website nào ở trạng thái hoạt động.',
                'url' => WebsiteResource::getUrl('index')],
            $this->brandStep($sites),
            ['n' => 3, 'title' => 'Nguồn AI được dùng', 'state' => 'todo',
                'note' => 'Chưa làm. Chọn nguồn (AG-A03) chưa có. Hiện có: '
                    .($crawled > 0 ? "trang website sẵn sàng ({$crawled} trang đã quét)" : 'trang website chưa quét')
                    .'; Search Console chưa kết nối (đang là dữ liệu minh họa).',
                'url' => null],
            ['n' => 4, 'title' => 'Chọn chế độ', 'state' => $modeChosen ? 'done' : 'default',
                'note' => $modeChosen ? 'Đã chọn chế độ cho chiến dịch.' : 'Cân bằng (mặc định).',
                'url' => CampaignResource::getUrl('index')],
            ['n' => 5, 'title' => 'Chạy thử 1 bài có xem trước', 'state' => $firstRun ? 'done' : 'todo',
                'note' => $firstRun ? 'Đã có phiên chạy. Bấm để xem.' : 'Chưa có phiên chạy nào.'.($ai['connected'] ? '' : ' Cần khóa AI trước.'),
                'url' => AgentRunResource::getUrl('index')],
        ];
    }
}
