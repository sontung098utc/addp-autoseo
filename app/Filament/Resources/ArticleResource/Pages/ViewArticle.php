<?php

namespace App\Filament\Resources\ArticleResource\Pages;

use App\Filament\Resources\ArticleResource;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\Review\ReviewActions;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\BrandProfile;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KnowledgeItemUsage;
use App\Models\Publication;
use App\Models\Website;
use App\Services\Brain\BrainContext;
use Filament\Actions;

/**
 * AG-S27: không gian bài viết. Bấm vào một bài ở màn Bài viết sẽ vào đây. Các tab đi theo đúng chuỗi làm ra một bài:
 * Tổng quan (chuỗi 6 mắt xích + sửa thông tin bài) → Từ khóa và kế hoạch (bài sinh ra từ đâu) → Nghiên cứu và nguồn (AI dựa vào gì để viết)
 * → Nội dung (danh sách phiên bản, bấm Xem sang trang chi tiết phiên bản) → Hoạt động (phiên chạy của đội AI, có hiệu ứng khi đang chạy) → Xuất bản.
 * Chỉ hiện dữ liệu có thật trong hệ thống; mắt xích nào chưa có dữ liệu thì nói rõ là chưa có.
 * Kế thừa EditArticle để dùng lại form và luật khóa trường; mọi thao tác duyệt đi qua ReviewWorkflow như các màn cũ.
 */
class ViewArticle extends EditArticle
{
    protected static string $view = 'filament.article.workspace';

    public const ORIGINS = [
        'plan' => 'Kế hoạch nội dung', 'keyword' => 'Kho từ khóa', 'audit' => 'Kiểm tra SEO', 'competitor' => 'Phân tích đối thủ',
        'gsc' => 'Search Console', 'manual' => 'Tạo tay',
    ];

    public const KEYWORD_SOURCES = ['csv' => 'CSV', 'tay' => 'Nhập tay', 'ai' => 'AI đề xuất', 'gsc' => 'Search Console', 'crawl' => 'Trang đã quét', 'competitor' => 'Đối thủ'];

    public const KEYWORD_STATUSES = ['new' => 'Mới', 'planned' => 'Đã lên kế hoạch', 'has_article' => 'Đã có bài'];

    /** Chặng của phiên chạy, theo thứ tự, cho dải chặng ở tab Hoạt động. */
    public const RUN_STAGES = [
        'guard' => 'Kiểm điều kiện', 'risk' => 'Xét rủi ro', 'brief' => 'Lập dàn ý', 'write' => 'Viết',
        'grade' => 'Chấm điểm', 'repair' => 'Sửa', 'handoff' => 'Bàn giao',
    ];

    public function getBreadcrumb(): string
    {
        return 'Chi tiết';
    }

    /** Lưu thông tin xong ở lại trang bài. */
    protected function getRedirectUrl(): ?string
    {
        return null;
    }

    protected function getFormActions(): array
    {
        return [$this->getSaveFormAction()->label('Lưu thông tin')];
    }

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
            Actions\DeleteAction::make()->visible(fn () => ArticleResource::canDeleteAny()),
        ];
    }

    protected function getViewData(): array
    {
        /** @var Article $article */
        $article = $this->record->refresh()->load('website');
        $versions = $article->versions()->with(['reviewer', 'mediaAssets'])->orderByDesc('version')->get();
        $latest = $versions->first();
        $runs = AgentRun::query()->where('article_id', $article->id)->with('steps')->orderByDesc('id')->get();
        $lastRun = $runs->first();
        $live = $lastRun && RunActivity::isLive($lastRun) ? $lastRun : null;
        $campaignId = $article->campaign_id ?: $lastRun?->campaign_id;
        $publications = Publication::query()->whereIn('article_version_id', $versions->pluck('id'))->with(['website', 'articleVersion'])->orderByDesc('id')->get();

        // Từ khóa: ưu tiên liên kết thật (keyword_id); không có thì dò theo tên trong kho từ khóa của website.
        $keyword = $article->keyword;
        $keywordMatched = false;
        if (! $keyword) {
            $keyword = Keyword::query()->where('website_id', $article->website_id)->where('keyword_key', Keyword::keyFor((string) $article->primary_keyword))->first();
            $keywordMatched = $keyword !== null;
        }
        $clusterId = $article->cluster_id ?: $keyword?->cluster_id;
        $cluster = $clusterId ? KeywordCluster::find($clusterId) : null;
        $clusterKeywords = $cluster
            ? Keyword::query()->where('cluster_id', $cluster->id)->with(['articles' => fn ($q) => $q->select('id', 'keyword_id', 'workflow_status')])
                ->orderByRaw('volume is null')->orderByDesc('volume')->limit(10)->get()
            : collect();
        $clusterArticles = $cluster
            ? Article::query()->where('cluster_id', $cluster->id)->whereKeyNot($article->id)->orderByDesc('id')->limit(10)->get()
            : collect();

        // Nghiên cứu: lấy từ phiên chạy gần nhất có dàn ý (bước brief) và bước viết/sửa cuối (kiến thức, ảnh, liên kết nội bộ).
        $briefStep = $runs->flatMap->steps->where('stage', 'brief')->sortByDesc('id')->first();
        $writeStep = $runs->flatMap->steps->whereIn('stage', ['write', 'repair'])->sortByDesc('id')->first();

        return [
            'article' => $article,
            'latest' => $latest,
            'versions' => $versions,
            'versionQuality' => $versions->mapWithKeys(fn (ArticleVersion $v) => [$v->id => ReviewActions::qualityFor($v)->counts()])->all(),
            'versionRun' => $runs->whereNotNull('final_version_id')->keyBy('final_version_id'),
            'canOpenVersion' => ArticleVersionResource::canViewAny(),
            'runs' => $runs,
            'live' => $live,
            'liveStage' => $live ? RunActivity::currentStage($live->steps) : null,
            'campaign' => $campaignId ? Campaign::find($campaignId) : null,
            'keyword' => $keyword,
            'keywordMatched' => $keywordMatched,
            'cluster' => $cluster,
            'clusterKeywords' => $clusterKeywords,
            'clusterArticles' => $clusterArticles,
            'brains' => BrainContext::forArticle($article, $campaignId)->brains,
            'usages' => $latest ? KnowledgeItemUsage::query()->where('article_version_id', $latest->id)->with('item.source')->get() : collect(),
            'brand' => BrandProfile::query()->where('website_id', $article->website_id)->first(),
            'outline' => array_values(array_filter((array) ($briefStep?->data['outline'] ?? []), 'is_string')),
            'research' => (array) ($writeStep?->data ?? []),
            'publications' => $publications,
            'quality' => $latest ? ReviewActions::qualityFor($latest) : null,
            'totalCost' => (float) $runs->sum('cost_vnd'),
            'totalTokens' => (int) $runs->sum('tokens_used'),
            'pollSeconds' => RunActivity::POLL_LIVE,
        ];
    }
}
