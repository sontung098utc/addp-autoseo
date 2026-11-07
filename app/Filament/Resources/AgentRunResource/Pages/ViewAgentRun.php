<?php

namespace App\Filament\Resources\AgentRunResource\Pages;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Filament\Support\Agent\RunActivity;
use Filament\Resources\Pages\ViewRecord;

class ViewAgentRun extends ViewRecord
{
    protected static string $resource = AgentRunResource::class;

    protected static string $view = 'filament.agent.run-view';

    public function getTitle(): string
    {
        return 'Diễn biến phiên chạy #'.$this->record->id;
    }

    protected function getViewData(): array
    {
        // Làm mới mỗi lần poll để thấy bước mới và trạng thái mới.
        $this->record->refresh()->load(['steps', 'article', 'website', 'finalVersion']);
        $run = $this->record;
        $live = RunActivity::isLive($run);

        return [
            'run' => $run,
            'blueprint' => AgentFlowBlueprint::byStage(),
            'statusMap' => AgentRunResource::STATUS,
            'live' => $live,
            'stale' => RunActivity::isStale($run),
            'currentStage' => $live ? RunActivity::currentStage($run->steps) : null,
            'elapsed' => RunActivity::formatElapsed(RunActivity::elapsedSeconds($run)),
            'pollSeconds' => $live ? RunActivity::POLL_LIVE : null,
        ];
    }
}
