<?php

namespace App\Filament\Pages;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Resources\CampaignResource;
use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/** AG-S01: bản đồ luồng của đội AI. Mỗi chặng lấy số liệu THẬT từ các phiên chạy 30 ngày gần nhất và ghi rõ phần Dev1 cần xây. */
class AgentFlowMapPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-share';

    protected static ?string $navigationLabel = 'Bản đồ luồng đội AI';

    protected static ?string $navigationGroup = 'Chiến dịch';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Bản đồ luồng đội AI';

    protected static ?string $slug = 'luong-doi-ai';

    protected static string $view = 'filament.pages.agent-flow-map';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof \App\Models\User && $u->is_active && $u->isApprover();
    }

    protected function getViewData(): array
    {
        $since = now()->subDays(30);
        $runs = AgentRun::query()->where('created_at', '>=', $since);
        $byStatus = (clone $runs)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->all();
        $steps = AgentRunStep::query()->whereHas('run', fn ($q) => $q->where('created_at', '>=', $since))
            ->selectRaw('stage, status, count(*) c')->groupBy('stage', 'status')->get()->groupBy('stage');

        $nodes = array_map(function (array $b) use ($steps): array {
            $b['stats'] = ($steps->get($b['stage']) ?? collect())->pluck('c', 'status')->all();
            $b['total'] = array_sum($b['stats']);

            return $b;
        }, AgentFlowBlueprint::stages());

        return [
            'nodes' => $nodes,
            'total' => array_sum($byStatus),
            'byStatus' => $byStatus,
            'statusMap' => AgentRunResource::STATUS,
            'campaignUrl' => CampaignResource::getUrl('index'),
            'runsUrl' => AgentRunResource::getUrl('index'),
            'reviewUrl' => ArticleVersionResource::getUrl('index'),
        ];
    }
}
