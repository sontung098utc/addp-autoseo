<?php

namespace App\Filament\Resources\AgentRunResource\Pages;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\Agent\RunFlow;
use App\Filament\Support\Review\ReviewActions;
use App\Models\Campaign;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAgentRun extends ViewRecord
{
    protected static string $resource = AgentRunResource::class;

    protected static string $view = 'filament.agent.run-view';

    /** AG-S15: tiêu đề trang là TÊN BÀI VIẾT (tiêu đề bản bài nếu có, không thì từ khóa chính), không phải số phiên. */
    public function getTitle(): string
    {
        return self::articleName($this->record);
    }

    public static function articleName(\App\Models\AgentRun $run): string
    {
        $title = trim((string) $run->finalVersion?->title);

        return $title !== '' ? $title : (trim((string) $run->article?->primary_keyword) ?: 'Phiên chạy #'.$run->id);
    }

    public function getBreadcrumbs(): array
    {
        $crumbs = parent::getBreadcrumbs();
        // Cluster đã thêm "Đội AI"; thay mục cuối ("Xem") bằng tên bài để có Đội AI › Phiên chạy › <tên bài>.
        array_pop($crumbs);
        $crumbs[] = \Illuminate\Support\Str::limit($this->getTitle(), 60);

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
                        $msg = app(\App\Services\Agent\Handoff\CmsDraftHandoff::class)->cancel($version);
                        \Filament\Notifications\Notification::make()->title($msg)->success()->send();
                    } catch (\RuntimeException $e) {
                        \Filament\Notifications\Notification::make()->title($e->getMessage())->danger()->send();
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
        $current = ($live || $stale) ? RunActivity::currentStage($run->steps) : null;

        return [
            'run' => $run,
            'name' => self::articleName($run),
            'campaign' => $run->campaign_id ? Campaign::query()->find($run->campaign_id) : null,
            'blueprint' => AgentFlowBlueprint::byStage(),
            'statusMap' => AgentRunResource::STATUS,
            'live' => $live,
            'stale' => $stale,
            'currentStage' => $live ? $current : null,
            'flow' => RunFlow::build($run, $run->steps, $live, $stale, $current),
            'elapsed' => RunActivity::formatElapsed(RunActivity::elapsedSeconds($run)),
            'pollSeconds' => $live ? RunActivity::POLL_LIVE : null,
        ];
    }
}
