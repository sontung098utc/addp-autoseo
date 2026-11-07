<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\PublicationResource;
use App\Filament\Resources\WebsiteResource;
use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\ArticleVersion;
use App\Models\Campaign;
use App\Models\CrawledUrl;
use App\Models\Publication;
use App\Models\Website;
use App\Services\Agent\Settings\AgentAi;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Schema;

/** Tab "Tổng quan" của Đội AI: bước cần làm, vòng đời một bài (số liệu thật), việc cần bạn, bản đồ chặng. */
class AgentOverview extends Page
{
    protected static ?string $cluster = AgentTeam::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Tổng quan';

    protected static ?string $title = 'Đội AI: tổng quan';

    protected static ?string $slug = 'tong-quan';

    protected static ?int $navigationSort = 1;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string $view = 'filament.clusters.agent-team.overview';

    public static function canAccess(): bool
    {
        $u = \Illuminate\Support\Facades\Auth::user();

        return $u instanceof \App\Models\User && $u->is_active && $u->isApprover();
    }

    protected function getViewData(): array
    {
        $ai = AgentAi::status();
        $active = Campaign::query()->where('status', 'active')->get();
        $drafts = ArticleVersion::query()->where('review_status', 'needs_review')->count();
        $since = now()->subDays(30);
        $runs = AgentRun::query()->where('created_at', '>=', $since);
        $byStatus = (clone $runs)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->all();
        $stepStats = AgentRunStep::query()->whereHas('run', fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw('stage, status, count(*) c')->groupBy('stage', 'status')->get()->groupBy('stage');

        $nodes = array_map(function (array $b) use ($stepStats): array {
            $b['stats'] = ($stepStats->get($b['stage']) ?? collect())->pluck('c', 'status')->all();

            return $b;
        }, AgentFlowBlueprint::stages());

        $steps = $this->setupSteps($ai);

        $life = [
            ['label' => 'Từ khóa chưa giao', 'n' => $active->sum(fn ($c) => CampaignResource::remaining($c)), 'url' => CampaignResource::getUrl('index'), 'who' => 'Chiến dịch'],
            ['label' => 'Đội AI giữ nháp', 'n' => $byStatus['held'] ?? 0, 'url' => AgentRunResource::getUrl('index'), 'who' => 'Cần người xem'],
            ['label' => 'Chờ duyệt', 'n' => $drafts, 'url' => ArticleVersionResource::getUrl('index'), 'who' => 'Người duyệt'],
            ['label' => 'Đã xếp lịch', 'n' => Publication::query()->where('status', 'scheduled')->count(), 'url' => PublicationResource::getUrl('index'), 'who' => 'Hệ thống đăng'],
            ['label' => 'Đã đăng', 'n' => Publication::query()->where('status', 'published')->count(), 'url' => PublicationResource::getUrl('index'), 'who' => 'CMS'],
        ];

        return [
            'ai' => $ai,
            'steps' => $steps,
            'stepsLeft' => collect($steps)->where('state', '!=', 'done')->count(),
            'life' => $life,
            'nodes' => $nodes,
            'total' => array_sum($byStatus),
            'byStatus' => $byStatus,
            'statusMap' => AgentRunResource::STATUS,
            'attention' => AgentRun::query()->with('article')->whereIn('status', ['held', 'failed'])->latest('id')->limit(5)->get(),
            'runsUrl' => AgentRunResource::getUrl('index'),
        ];
    }

    /**
     * "Hoàn tất cài đặt" 5 bước. state: done (có dữ liệu thật) | todo (chưa làm) | default (đang dùng mặc định, chưa chọn).
     * Bước chưa có dữ liệu (hồ sơ doanh nghiệp AG-K11, nguồn AI AG-A03) luôn là todo, không tick giả.
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
            ['n' => 2, 'title' => 'Hồ sơ doanh nghiệp', 'state' => 'todo',
                'note' => 'Chưa làm. Chức năng hồ sơ doanh nghiệp (AG-K11) chưa có.',
                'url' => null],
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
