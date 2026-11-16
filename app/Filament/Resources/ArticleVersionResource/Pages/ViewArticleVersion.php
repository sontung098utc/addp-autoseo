<?php

namespace App\Filament\Resources\ArticleVersionResource\Pages;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Support\Article\VersionCompareAction;
use App\Filament\Support\Explain\WhyAction;
use App\Filament\Support\Review\ReviewActions;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Models\Website;
use App\Services\Agent\Handoff\CmsDraftHandoff;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewArticleVersion extends ViewRecord
{
    protected static string $resource = ArticleVersionResource::class;

    /** Tiêu đề ngắn: các nút hành động bên phải chiếm chỗ, "Xem Phiên bản bài" bị xuống nhiều dòng ở khung hẹp. */
    public function getTitle(): string|Htmlable
    {
        return 'Bản bài v'.($this->record->version ?? '');
    }

    public function compareAction(): Actions\Action
    {
        return VersionCompareAction::make(fn () => $this->record)
            ->visible(fn () => VersionCompareAction::previous($this->record) !== null);
    }

    protected function getHeaderActions(): array
    {
        return [
            // AG-S27: quay về không gian bài (từ khóa, nguồn, các phiên bản khác, phiên AI, lịch xuất bản của bài này).
            Actions\Action::make('back_to_article')->label('Về bài viết')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->url(fn () => ArticleResource::getUrl('view', ['record' => $this->record->article_id]).'#tab-content'),
            $this->compareAction(),
            WhyAction::page(),
            Actions\Action::make('approve')->label('Duyệt')->icon('heroicon-o-check')->color('primary')
                ->visible(fn () => $this->record->review_status === 'needs_review')
                ->authorize(fn () => ReviewActions::canApprove())
                ->tooltip(fn () => ReviewActions::blockedHint(ReviewActions::qualityFor($this->record)))
                ->modalHeading(fn () => ReviewActions::qualityFor($this->record)->hasBlocking() ? 'Duyệt ngoại lệ: bài còn lỗi P0' : 'Duyệt nội dung')
                ->form(fn () => ReviewActions::approveForm((int) $this->record->article?->created_by === (int) ReviewActions::user()?->id, ReviewActions::qualityFor($this->record)))
                ->action(function (array $data) {
                    abort_unless(ReviewActions::canApprove(), 403);
                    ReviewActions::run(fn () => (new ReviewWorkflow)->approve(ReviewActions::user(), $this->record, $data['note'] ?? null, $data['override_reason'] ?? null), 'Đã duyệt nội dung');
                    $this->record->refresh();
                }),
            Actions\Action::make('reject')->label('Từ chối')->icon('heroicon-o-x-mark')->color('danger')->outlined()
                ->visible(fn () => $this->record->review_status === 'needs_review')
                ->authorize(fn () => ReviewActions::canApprove())
                ->form(ReviewActions::rejectForm())
                ->action(function (array $data) {
                    abort_unless(ReviewActions::canApprove(), 403);
                    ReviewActions::run(fn () => (new ReviewWorkflow)->reject(ReviewActions::user(), $this->record, $data['reason'] ?? null), 'Đã từ chối bản thảo');
                    $this->record->refresh();
                }),
            Actions\Action::make('cancelCmsDraft')->label('Huỷ nháp trên CMS')->icon('heroicon-o-trash')->color('danger')->outlined()
                ->visible(fn () => $this->record->cms_draft_status === 'pushed')
                ->authorize(fn () => ReviewActions::canApprove())
                ->requiresConfirmation()->modalDescription('Bài nháp trên CMS sẽ được đưa vào thùng rác. Khi đăng chính thức hệ thống sẽ tạo bài như bình thường.')
                ->action(function () {
                    abort_unless(ReviewActions::canApprove(), 403);
                    try {
                        $msg = app(CmsDraftHandoff::class)->cancel($this->record);
                        Notification::make()->title($msg)->success()->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                    $this->record->refresh();
                }),
            Actions\Action::make('schedule')->label('Xếp lịch')->icon('heroicon-o-clock')->color('primary')
                ->visible(fn () => $this->record->review_status === 'approved' && $this->record->article?->workflow_status === 'approved')
                ->authorize(fn () => ReviewActions::canApprove())
                ->form(ReviewActions::scheduleForm())
                ->fillForm(fn () => ['website_id' => $this->record->article?->website_id])
                ->action(function (array $data) {
                    abort_unless(ReviewActions::canApprove(), 403);
                    ReviewActions::run(function () use ($data) {
                        (new ReviewWorkflow)->schedule(ReviewActions::user(), $this->record, ReviewActions::parseVn($data['scheduled_at_vn']), Website::findOrFail($data['website_id']));
                    }, 'Đã xếp lịch xuất bản');
                    $this->record->refresh();
                }),
        ];
    }
}
