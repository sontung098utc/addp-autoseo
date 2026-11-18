<?php

namespace App\Filament\Resources\AgentRunResource\Pages;

use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Filament\Support\Agent\RunViewData;
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

    /**
     * Phiên của một bài không còn trang riêng: chuyển sang tab "Phiên chạy" của trang bài (đúng phiên đó) để chi tiết nằm cùng chỗ với nội dung, từ khóa, xuất bản.
     * Liên kết cũ (thông báo, đường dẫn đã lưu) vẫn dùng được. Phiên không gắn bài (xây kho, khảo sát...) vẫn xem ở trang này.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);
        $url = AgentRunResource::viewUrl($this->record);
        if ($this->record->article_id && ! str_contains($url, '/phien-chay/')) {
            $this->redirect($url);
        }
    }

    /** AG-S15: tiêu đề trang là TÊN BÀI VIẾT (phiên không gắn bài: tên loại việc) (tiêu đề bản bài nếu có, không thì từ khóa chính), không phải số phiên. */
    public function getTitle(): string
    {
        return self::articleName($this->record);
    }

    public static function articleName(AgentRun $run): string
    {
        return RunViewData::articleName($run);
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
                        Notification::make()->title(\App\Support\UserFacingError::from($e))->danger()->send();
                    }
                    $this->record->refresh();
                }),
        ];
    }

    protected function getViewData(): array
    {
        return RunViewData::for($this->record);
    }
}
