<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\Review\ArticleRows;
use App\Filament\Support\Review\ReviewActions;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Campaign;
use App\Models\KnowledgeItemUsage;
use App\Models\Publication;
use App\Models\Website;
use App\Services\Brain\BrainContext;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * AG-S27: không gian bài viết. Bấm vào một bài ở màn Bài viết sẽ vào đây: một nơi có đủ nội dung, SEO, chất lượng,
 * phiên bản, phiên chạy của đội AI, trí tuệ và nguồn đã dùng, ảnh, lịch xuất bản, cùng các thao tác của bài.
 * Trang chỉ ĐỌC dữ liệu sẵn có; mọi thao tác đi qua ReviewWorkflow / AgentRunOrchestrator như các màn cũ.
 */
class ViewArticle extends ViewRecord
{
    protected static string $resource = ArticleResource::class;

    protected static string $view = 'filament.article.workspace';

    /** Tiêu đề trang là tên bài (tiêu đề bản mới nhất nếu có, không thì từ khóa chính). */
    public function getTitle(): string
    {
        return (string) ($this->latest()?->title ?: $this->record->primary_keyword);
    }

    private function latest(): ?ArticleVersion
    {
        return $this->record->versions()->orderByDesc('version')->first();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('run_agent')->label('Giao đội AI')->icon('heroicon-o-cpu-chip')
                ->visible(fn () => ArticleResource::canAssignToAgent($this->record))
                ->requiresConfirmation()
                ->modalHeading('Giao bài này cho đội AI')
                ->modalDescription(ArticleResource::ASSIGN_DESCRIPTION)
                ->modalSubmitActionLabel('Giao việc')
                ->action(function () {
                    ArticleResource::assignToAgent($this->record);
                    $this->record->refresh();
                }),
            Actions\Action::make('submit_review')->label('Gửi duyệt')->icon('heroicon-o-paper-airplane')
                ->visible(fn () => ArticleResource::primaryActionFor($this->record) === 'submit_review')
                ->authorize(fn () => ReviewActions::canSubmit())
                ->requiresConfirmation()
                ->action(function () {
                    abort_unless(ReviewActions::canSubmit(), 403);
                    ReviewActions::run(fn () => (new ReviewWorkflow)->submitForReview(ReviewActions::user(), $this->latest()), 'Đã gửi duyệt');
                    $this->record->refresh();
                }),
            Actions\Action::make('approve')->label('Duyệt')->icon('heroicon-o-check')->color('primary')
                ->visible(fn () => $this->record->workflow_status === 'needs_review' && $this->latest() !== null)
                ->authorize(fn () => ReviewActions::canApprove())
                ->tooltip(fn () => ReviewActions::blockedHint(ReviewActions::qualityFor($this->latest())))
                ->modalHeading(fn () => ReviewActions::qualityFor($this->latest())->hasBlocking() ? 'Duyệt ngoại lệ: bài còn lỗi P0' : 'Duyệt nội dung')
                ->form(fn () => ReviewActions::approveForm((int) $this->record->created_by === (int) ReviewActions::user()?->id, ReviewActions::qualityFor($this->latest())))
                ->action(function (array $data) {
                    abort_unless(ReviewActions::canApprove(), 403);
                    ReviewActions::run(fn () => (new ReviewWorkflow)->approve(ReviewActions::user(), $this->latest(), $data['note'] ?? null, $data['override_reason'] ?? null), 'Đã duyệt nội dung');
                    $this->record->refresh();
                }),
            Actions\Action::make('reject')->label('Từ chối')->icon('heroicon-o-x-mark')->color('danger')->outlined()
                ->visible(fn () => $this->record->workflow_status === 'needs_review' && $this->latest() !== null)
                ->authorize(fn () => ReviewActions::canApprove())
                ->form(ReviewActions::rejectForm())
                ->action(function (array $data) {
                    abort_unless(ReviewActions::canApprove(), 403);
                    ReviewActions::run(fn () => (new ReviewWorkflow)->reject(ReviewActions::user(), $this->latest(), $data['reason'] ?? null), 'Đã từ chối bản thảo');
                    $this->record->refresh();
                }),
            Actions\Action::make('schedule')->label('Xếp lịch')->icon('heroicon-o-clock')->color('primary')
                ->visible(fn () => $this->record->workflow_status === 'approved' && $this->latest() !== null)
                ->authorize(fn () => ReviewActions::canApprove())
                ->form(ReviewActions::scheduleForm())
                ->action(function (array $data) {
                    abort_unless(ReviewActions::canApprove(), 403);
                    ReviewActions::run(function () use ($data) {
                        (new ReviewWorkflow)->schedule(ReviewActions::user(), $this->latest(), ReviewActions::parseVn($data['scheduled_at_vn']), Website::findOrFail($data['website_id']));
                    }, 'Đã xếp lịch xuất bản');
                    $this->record->refresh();
                }),
            Actions\EditAction::make()->label('Sửa thông tin bài')->color('gray'),
        ];
    }

    protected function getViewData(): array
    {
        /** @var Article $article */
        $article = $this->record->refresh()->load('website');
        $versions = $article->versions()->with('reviewer')->orderByDesc('version')->get();
        $version = $versions->first();
        $runs = AgentRun::query()->where('article_id', $article->id)->with('steps')->orderByDesc('id')->get();
        $lastRun = $runs->first();
        $campaignId = $article->campaign_id ?: $lastRun?->campaign_id;
        $publications = Publication::query()->whereIn('article_version_id', $versions->pluck('id'))->with(['website', 'articleVersion'])->orderByDesc('id')->get();

        return [
            'article' => $article,
            'version' => $version,
            'versions' => $versions,
            'runs' => $runs,
            'live' => $lastRun && RunActivity::isLive($lastRun) ? $lastRun : null,
            'liveStage' => $lastRun && RunActivity::isLive($lastRun) ? ArticleRows::stageLabel($lastRun) : null,
            'campaign' => $campaignId ? Campaign::find($campaignId) : null,
            'brains' => BrainContext::forArticle($article, $campaignId)->brains,
            'usages' => $version ? KnowledgeItemUsage::query()->where('article_version_id', $version->id)->with('item')->get() : collect(),
            'publications' => $publications,
            'quality' => $version ? ReviewActions::qualityFor($version) : null,
            'totalCost' => (float) $runs->sum('cost_vnd'),
            'totalTokens' => (int) $runs->sum('tokens_used'),
            'reviewUrl' => $version && ArticleVersionResource::canViewAny() ? ArticleVersionResource::getUrl('view', ['record' => $version]) : null,
            'pollSeconds' => RunActivity::POLL_LIVE,
        ];
    }
}
