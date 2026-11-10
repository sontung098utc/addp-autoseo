<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Models\User;
use App\Services\Agent\Pipeline\JobTypeRegistry;
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

    /** Cửa sổ trượt của một loại việc (thẻ dưới mắt xích). */
    public function jobAction(): Action
    {
        return Action::make('job')
            ->modalHeading(fn (array $arguments): string => ($j = JobTypeRegistry::find($arguments['job'] ?? '')) ? 'Loại việc · '.$j['title'] : 'Loại việc')
            ->slideOver()
            ->modalContent(fn (array $arguments) => view('filament.clusters.agent-team.pipeline-job', [
                'j' => JobTypeRegistry::find($arguments['job'] ?? ''),
                'names' => $this->jobNames(),
                'chain' => JobTypeRegistry::CHAIN,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng');
    }

    /** @return array<string, string> mã loại việc => tên; 'human' => 'Người' */
    private function jobNames(): array
    {
        return ['human' => 'Người'] + array_column(JobTypeRegistry::all(), 'title', 'id');
    }

    protected function getViewData(): array
    {
        $chain = [];
        foreach (JobTypeRegistry::CHAIN as $key => $label) {
            $chain[] = ['key' => $key, 'label' => $label, 'jobs' => JobTypeRegistry::forLink($key)];
        }

        return [
            'stages' => PipelineBlueprint::stages(),
            'controls' => PipelineBlueprint::controlsWired(),
            'chain' => $chain,
            'names' => $this->jobNames(),
        ];
    }
}
