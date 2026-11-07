<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Models\User;
use App\Services\Agent\Pipeline\PipelineBlueprint;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;

/** AG-S12: tab "Pipeline" của Đội AI: bản đồ 9 giai đoạn với trạng thái thật. Chi tiết mỗi giai đoạn mở trong cửa sổ trượt. */
class PipelineMap extends Page
{
    protected static ?string $cluster = AgentTeam::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Pipeline';

    protected static ?string $title = 'Pipeline tự động';

    protected static ?string $slug = 'pipeline';

    protected static ?int $navigationSort = 9;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string $view = 'filament.clusters.agent-team.pipeline';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    /** @return array<string, mixed>|null */
    private function stage(string $id): ?array
    {
        foreach (PipelineBlueprint::stages() as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }

        return null;
    }

    public function stageAction(): Action
    {
        return Action::make('stage')
            ->modalHeading(fn (array $arguments): string => ($s = $this->stage($arguments['stage'] ?? '')) ? "{$s['n']} · {$s['title']}" : 'Giai đoạn')
            ->slideOver()
            ->modalContent(fn (array $arguments) => view('filament.clusters.agent-team.pipeline-stage', ['s' => $this->stage($arguments['stage'] ?? ''), 'controls' => PipelineBlueprint::controlsWired()]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng');
    }

    protected function getViewData(): array
    {
        return ['stages' => PipelineBlueprint::stages(), 'controls' => PipelineBlueprint::controlsWired()];
    }
}
