<?php

namespace App\Filament\Resources\AgentRunResource\Pages;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\Agent\RunFlow;
use App\Filament\Support\Review\ReviewActions;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Services\Agent\Handoff\CmsDraftHandoff;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Str;

class ViewAgentRun extends ViewRecord
{
    protected static string $resource = AgentRunResource::class;

    protected static string $view = 'filament.agent.run-view';

    /** AG-S15: tiêu đề trang là TÊN BÀI VIẾT (phiên không gắn bài: tên loại việc) (tiêu đề bản bài nếu có, không thì từ khóa chính), không phải số phiên. */
    public function getTitle(): string
    {
        return self::articleName($this->record);
    }

    public static function articleName(AgentRun $run): string
    {
        if (! $run->article_id && $run->job_type && $run->job_type !== 'article_write') {
            return JobTypeRegistry::find($run->job_type)['title'] ?? $run->job_type;
        }
        $title = trim((string) $run->finalVersion?->title);

        return $title !== '' ? $title : (trim((string) $run->article?->primary_keyword) ?: 'Phiên chạy #'.$run->id);
    }

    public function getBreadcrumbs(): array
    {
        $crumbs = parent::getBreadcrumbs();
        // Cluster đã thêm "Đội AI"; thay mục cuối ("Xem") bằng tên bài để có Đội AI › Phiên chạy › <tên bài>.
        array_pop($crumbs);
        $crumbs[] = Str::limit($this->getTitle(), 60);

        return $crumbs;
    }

    protected function getHeaderActions(): array
    {
        $version = $this->record->finalVersion;

        return [
            Actions\Action::make('viewDraft')->label('Xem bản nháp')->icon('heroicon-o-document-text')->color('primary')
                ->visible(fn () => $version !== null)
                ->url(fn () => ArticleVersionResource::getUrl('view', ['record' => $version])),
            Actions\Action::make('cancelCmsDraft')->label('Huỷ nháp CMS')->icon('heroicon-o-trash')->color('danger')->outlined()
                ->visible(fn () => $version?->cms_draft_status === 'pushed')
                ->authorize(fn () => ReviewActions::canApprove())
                ->requiresConfirmation()->modalDescription('Bài nháp trên CMS sẽ được đưa vào thùng rác. Khi đăng chính thức hệ thống sẽ tạo bài như bình thường.')
                ->action(function () use ($version) {
                    abort_unless(ReviewActions::canApprove(), 403);
                    try {
                        $msg = app(CmsDraftHandoff::class)->cancel($version);
                        Notification::make()->title($msg)->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                    $this->record->refresh();
                }),
        ];
    }

    protected function getViewData(): array
    {
        // Làm mới mỗi lần poll để thấy bước mới và trạng thái mới.
        $this->record->refresh()->load(['steps', 'article', 'website', 'finalVersion']);
        $run = $this->record;
        $live = RunActivity::isLive($run);
        $stale = RunActivity::isStale($run);
        $current = ($live || $stale) ? RunActivity::currentStageOf($run, $run->steps) : null;

        $flow = RunFlow::build($run, $run->steps, $live, $stale, $current);
        $problem = $live || $stale || in_array($run->status, ['failed', 'blocked', 'held'], true) || collect($flow['nodes'])->contains('state', 'waiting');

        return [
            'run' => $run,
            'verdict' => RunFlow::verdict($run, $flow, $live, $stale),
            'focus' => RunFlow::focusStage($flow['nodes']),
            'tab' => $problem ? 'dien-bien' : 'bao-cao',
            'name' => self::articleName($run),
            'campaign' => $run->campaign_id ? Campaign::query()->find($run->campaign_id) : null,
            'blueprint' => AgentFlowBlueprint::byStage(),
            'statusMap' => AgentRunResource::STATUS,
            'live' => $live,
            'stale' => $stale,
            'currentStage' => $live ? $current : null,
            'flow' => $flow,
            'elapsed' => RunActivity::formatElapsed(RunActivity::elapsedSeconds($run)),
            'pollSeconds' => $live ? RunActivity::POLL_LIVE : null,
        ];
    }
}
