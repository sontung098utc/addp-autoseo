<?php

namespace App\Filament\Resources\AgentRunResource\Pages;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Support\Agent\AgentFlowBlueprint;
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
        $this->record->loadMissing(['steps', 'article', 'website', 'finalVersion']);

        return ['run' => $this->record, 'blueprint' => AgentFlowBlueprint::byStage(), 'statusMap' => AgentRunResource::STATUS];
    }
}
