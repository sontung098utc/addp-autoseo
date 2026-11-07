<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\PublicationResource;
use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\ArticleVersion;
use App\Models\Campaign;
use App\Models\Publication;
use App\Services\Agent\Settings\AgentAi;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;

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

        $steps = [
            ['n' => 1, 'title' => 'Kết nối AI', 'done' => $ai['connected'], 'note' => $ai['connected'] ? 'Đang dùng model '.$ai['model'].' ('.($ai['source'] === 'db' ? 'khóa trong hệ thống' : 'khóa từ biến môi trường').')' : 'Chưa có khóa AI. Không có khóa thì lượt tự động không chạy.', 'url' => AgentConnection::getUrl()],
            ['n' => 2, 'title' => 'Tạo chiến dịch', 'done' => Campaign::query()->exists(), 'note' => 'Chọn website, nhập từ khóa và giới hạn bài mỗi ngày.', 'url' => CampaignResource::getUrl('index')],
            ['n' => 3, 'title' => 'Bật chạy', 'done' => $active->isNotEmpty(), 'note' => $active->isNotEmpty() ? $active->count().' chiến dịch đang chạy, hệ thống tự giao việc 15 phút một lần.' : 'Chưa có chiến dịch nào ở trạng thái "Đang chạy".', 'url' => CampaignResource::getUrl('index')],
            ['n' => 4, 'title' => 'Duyệt bản nháp', 'done' => null, 'note' => $drafts > 0 ? "{$drafts} bản nháp đang chờ bạn duyệt." : 'Chưa có bản nháp nào chờ duyệt.', 'url' => ArticleVersionResource::getUrl('index')],
        ];

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
            'life' => $life,
            'nodes' => $nodes,
            'total' => array_sum($byStatus),
            'byStatus' => $byStatus,
            'statusMap' => AgentRunResource::STATUS,
            'attention' => AgentRun::query()->with('article')->whereIn('status', ['held', 'failed'])->latest('id')->limit(5)->get(),
            'runsUrl' => AgentRunResource::getUrl('index'),
        ];
    }
}
