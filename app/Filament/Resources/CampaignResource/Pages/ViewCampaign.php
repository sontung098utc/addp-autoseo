<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\CampaignResource;
use App\Filament\Support\ContentPlan\HasPlanProposals;
use App\Filament\Support\Planning\DuplicateKeywordException;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Website;
use App\Services\Planning\ContentPlanner;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Trang một chiến dịch (cha): thấy ngay trạng thái, chế độ, tiến độ các bài con, nút chính;
 * tab Bài viết (bài con), Kế hoạch (bài đã lên kế hoạch + đề xuất), Cấu hình (tóm tắt, sửa ở form riêng).
 */
class ViewCampaign extends Page
{
    use HasPlanProposals;
    use InteractsWithRecord;

    protected static string $resource = CampaignResource::class;

    protected static string $view = 'filament.agent.campaign-view';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(CampaignResource::canViewAny(), 403);
    }

    public function getTitle(): string|Htmlable
    {
        return new HtmlString(e($this->record->name));
    }

    public function getBreadcrumb(): string
    {
        return $this->record->name;
    }

    protected function planSite(): ?Website
    {
        return $this->record->website;
    }

    protected function planFixedTarget(): ?string
    {
        return (string) $this->record->id;
    }

    protected function getHeaderActions(): array
    {
        /** @var Campaign $c */
        $c = $this->record;

        return [
            Action::make('start')->label('Bắt đầu chạy')->icon('heroicon-o-play')
                ->visible(fn () => $c->status === 'paused' && CampaignResource::canEdit($c))
                ->requiresConfirmation()->modalHeading('Bắt đầu chạy chiến dịch')->modalDescription(fn () => CampaignResource::startDescription($c->refresh()))
                ->action(function () use ($c) {
                    CampaignResource::start($c->refresh());
                    $this->record = $c->refresh();
                }),
            Action::make('tick')->label('Giao bài ngay')->icon('heroicon-o-forward')->color('gray')
                ->visible(fn () => $c->status === 'active')
                ->requiresConfirmation()->modalHeading('Giao bài ngay')
                ->modalDescription('Lấp ngay các chỗ trống: giao các bài kế tiếp cho đội AI viết song song (tối đa theo cấu hình chiến dịch), không chờ lượt tự động. Vẫn trong giới hạn bài/ngày.')
                ->action(function () use ($c) {
                    CampaignResource::runTick($c->refresh());
                }),
            Action::make('addArticle')->label('Thêm bài')->icon('heroicon-o-plus')->color('gray')
                ->modalHeading('Thêm bài vào chiến dịch')->modalSubmitActionLabel('Thêm bài')
                ->form([
                    TextInput::make('keyword')->label('Từ khóa chính')->required()->maxLength(255),
                    Select::make('intent')->label('Ý định tìm kiếm')->default('informational')->required()
                        ->options(['informational' => 'Thông tin', 'commercial' => 'Thương mại', 'transactional' => 'Giao dịch', 'navigational' => 'Điều hướng']),
                ])
                ->action(fn (array $data) => $this->addArticle((string) $data['keyword'], (string) $data['intent'])),
            Action::make('pause')->label('Tạm dừng')->icon('heroicon-o-pause')->color('gray')
                ->visible(fn () => $c->status === 'active' && CampaignResource::canEdit($c))
                ->action(function () use ($c) {
                    CampaignResource::pause($c->refresh());
                    $this->record = $c->refresh();
                }),
            Action::make('edit')->label('Cấu hình')->icon('heroicon-o-cog-6-tooth')->color('gray')
                ->visible(fn () => CampaignResource::canEdit($c))
                ->url(CampaignResource::getUrl('edit', ['record' => $c])),
        ];
    }

    /** Tạo bài con của chiến dịch (đã gắn campaign_id, trạng thái Đã lên kế hoạch). */
    public function addArticle(string $keyword, string $intent = 'informational'): ?Article
    {
        abort_unless(ContentPlanner::canPlan(Auth::user()), 403);
        $c = $this->record;
        try {
            $a = (new PlannedArticleCreator)->create(Auth::user(), $c->website, $keyword, null, $intent, [], null, 'manual');
        } catch (DuplicateKeywordException) {
            Notification::make()->title('Không thêm được bài')->body('Website đã có bài dùng từ khóa này.')->danger()->send();

            return null;
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Không thêm được bài')->body(\App\Support\UserFacingError::from($e))->danger()->send();

            return null;
        }
        $a->update(['campaign_id' => $c->id]);
        Notification::make()->title('Đã thêm bài vào chiến dịch')->body('Bài ở trạng thái Đã lên kế hoạch.')->success()->send();

        return $a;
    }

    /** @return array<string,mixed> */
    protected function getViewData(): array
    {
        /** @var Campaign $c */
        $c = $this->record->loadMissing('website');
        $counts = CampaignResource::articleCounts($c);
        $articles = Article::query()->with('latestVersion')->where('campaign_id', $c->id)->orderByDesc('id')->limit(100)->get();
        $planner = new ContentPlanner;

        return [
            'c' => $c,
            'counts' => $counts,
            'total' => array_sum($counts),
            'today' => CampaignResource::todayCount($c),
            'live' => CampaignResource::liveRunCount($c),
            'state' => CampaignResource::stateOf($c),
            'left' => CampaignResource::remaining($c),
            'articles' => $articles,
            'planned' => $articles->where('workflow_status', 'planned')->sortByDesc(fn (Article $a) => (int) $a->priority)->values(),
            'proposals' => $c->website ? $planner->propose($c->website, 10) : [],
            'pendingAudit' => $c->website ? $planner->pendingAuditCount($c->website) : 0,
            'showTarget' => false,
            'targetCampaigns' => collect(),
            'articlesUrl' => ArticleResource::getUrl('index'),
        ];
    }
}
