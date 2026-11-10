<?php

namespace App\Filament\Pages;

use App\Models\AgentRun;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Brand\BrandProfileData;
use App\Services\Crawler\SitemapCrawlerService;
use App\Services\Knowledge\Build\BrainBuildService;
use App\Services\Knowledge\Build\BrandProfileDrafter;
use App\Services\Knowledge\Build\SafePageFetcher;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Knowledge\KnowledgeReview;
use App\Services\Knowledge\Suggest\KnowledgeSuggestions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** G2: xây kho trí tuệ từ chính website. Chạy đồng bộ trong trang (chưa có chạy nền). Chỉ người duyệt trở lên. */
class BrainBuildPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Xây kho từ website';

    protected static ?string $navigationGroup = 'Khám phá';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Xây kho từ website';

    protected static ?string $slug = 'xay-kho';

    protected static string $view = 'filament.pages.brain-build';

    public ?int $websiteId = null;

    public $maxPages = BrainBuildService::DEFAULT_PAGES;

    public string $urlsText = '';

    /** @var array<string,mixed>|null kết quả lượt vừa chạy */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        return KnowledgeReview::canManage(Auth::user());
    }

    private function guard(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $this->guard();
        $this->websiteId = Website::query()->where('status', 'active')->orderBy('id')->value('id');
    }

    public function note(): string
    {
        return 'Đọc tối đa '.BrainBuildService::clampPages((int) $this->maxPages).' trang trên chính website của bạn, tốn tối đa '.number_format(BrainBuildService::totalBudgetVnd(), 0, ',', '.').' ₫. '
            .'Thông tin thường của công ty tự nhận (có nhãn, hoàn tác được). Giá, chính sách, sản phẩm sức khỏe và mọi nội dung y khoa luôn chờ người duyệt. '
            .'Chạy ngay trong trang, có thể mất vài chục giây đến vài phút.';
    }

    protected function getViewData(): array
    {
        $av = (new BrainBuildService)->availability();
        $site = $this->websiteId ? Website::find($this->websiteId) : null;
        $off = ! $av['ok'] ? $av['reason'] : ($site === null ? 'Chọn một website.' : ($site->status !== 'active' ? 'Website không hoạt động.' : null));
        $runs = AgentRun::query()->with('steps')->where('job_type', 'brain_build')->when($this->websiteId, fn ($q) => $q->where('website_id', $this->websiteId))->latest('id')->limit(8)->get()->map(function (AgentRun $r) {
            $pages = $r->steps->where('stage', 'page');

            return ['id' => $r->id, 'at' => $r->started_at?->format('d/m/Y H:i'), 'status' => $r->status, 'pages' => $pages->count(), 'items' => (int) $pages->sum(fn ($s) => (int) ($s->data['items'] ?? 0)), 'cost' => (float) $r->cost_vnd, 'reason' => $r->outcome_reason];
        })->all();
        $auto = KnowledgeItem::query()->where('review_mode', 'auto')->where('status', 'approved')->when($this->websiteId, fn ($q) => $q->where('website_id', $this->websiteId))->latest('id')->limit(30)->get();

        $brand = $site ? BrandProfileDrafter::last($site->id) : null;
        $suggestions = $site ? (new KnowledgeSuggestions)->forWebsite($site, 20) : [];
        $brandProfile = $site ? BrandProfileData::forWebsite($site->id) : null;

        return ['brandAuto' => $brand, 'brandMissing' => BrandProfileData::completeness($brandProfile)['missing'], 'suggestions' => $suggestions, 'sites' => Website::query()->orderBy('name')->get(['id', 'name', 'base_url', 'status']), 'off' => $off, 'avLabel' => $av['label'], 'avDemo' => $av['demo'], 'runs' => $runs, 'autoItems' => $auto, 'statusLabels' => ['running' => 'Đang chạy', 'ready_for_review' => 'Xong', 'blocked' => 'Bị chặn', 'failed' => 'Lỗi', 'held' => 'Tạm giữ'], 'reviewUrl' => KnowledgePage::getUrl()];
    }

    /** Nút "Bắt đầu xây kho" chỉ mở hộp xác nhận; việc chạy thật nằm ở build(). */
    public function confirmBuild(): void
    {
        $this->guard();
        $this->mountAction('buildConfirm');
    }

    public function buildConfirmAction(): Action
    {
        return Action::make('buildConfirm')
            ->requiresConfirmation()
            ->modalHeading('Bắt đầu xây kho?')
            ->modalDescription(fn () => $this->note())
            ->modalSubmitActionLabel('Bắt đầu xây kho')
            ->action(fn () => $this->build());
    }

    public function build(): void
    {
        $this->guard();
        $site = $this->websiteId ? Website::find($this->websiteId) : null;
        if ($site === null) {
            Notification::make()->title('Chưa chạy được')->body('Chọn một website.')->danger()->send();

            return;
        }
        @set_time_limit(300);
        try {
            $this->result = (new BrainBuildService)->run($site, Auth::user(), ['max_pages' => (int) $this->maxPages, 'urls' => $this->urlsText]);
        } catch (KnowledgeError $e) {
            $this->result = null;
            Notification::make()->title('Chưa chạy được')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title('Đã xây xong lượt này')->body($this->result['items_auto'].' mục tự nhận, '.$this->result['items_proposed'].' mục chờ duyệt.')->success()->send();
    }

    public function undo(int $id): void
    {
        $this->guard();
        $item = KnowledgeItem::find($id);
        if ($item === null) {
            return;
        }
        try {
            (new BrainBuildService)->undoAuto($item, Auth::user());
        } catch (KnowledgeError $e) {
            Notification::make()->title('Chưa hoàn tác được')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title('Đã hoàn tác')->body('Mục về trạng thái chờ duyệt.')->success()->send();
    }

    public function undoBrand(): void
    {
        $this->guard();
        $site = $this->websiteId ? Website::find($this->websiteId) : null;
        if ($site === null) {
            return;
        }
        try {
            $cleared = (new BrandProfileDrafter)->undo($site, Auth::user());
        } catch (KnowledgeError $e) {
            Notification::make()->title('Chưa hoàn tác được')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title('Đã hoàn tác hồ sơ tự điền')->body($cleared === [] ? 'Các trường đã được sửa tay nên được giữ nguyên.' : 'Đã xóa: '.implode(', ', $cleared).'.')->success()->send();
    }

    public function dismissSuggestion(string $key): void
    {
        $this->guard();
        if ($this->websiteId) {
            KnowledgeSuggestions::dismiss($this->websiteId, $key);
        }
    }

    /**
     * Giới hạn: chưa có cách xây kho "theo từ khóa". Chọn tối đa 5 URL trong sitemap có đường dẫn chứa từ của từ khóa, rồi chạy xây kho chỉ trên các URL đó.
     * Không có URL phù hợp thì không chạy.
     */
    public function fillGap(string $key): void
    {
        $this->guard();
        $site = $this->websiteId ? Website::find($this->websiteId) : null;
        $gap = $site ? collect((new KnowledgeSuggestions)->forWebsite($site, 100))->firstWhere('key', $key) : null;
        if ($site === null || $gap === null || $gap['type'] !== 'gap' || $gap['query'] === null) {
            Notification::make()->title('Chưa chạy được')->body('Gợi ý không còn hiệu lực.')->danger()->send();

            return;
        }
        $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower($gap['query']))) ?: [], fn ($w) => strlen($w) >= 3));
        $urls = [];
        try {
            foreach ((new SitemapCrawlerService)->discoverUrls($site, null, 500) as $row) {
                $u = is_array($row) ? ($row['url'] ?? null) : $row;
                $path = is_string($u) ? Str::ascii(mb_strtolower((string) parse_url($u, PHP_URL_PATH))) : '';
                if ($path !== '' && SafePageFetcher::hostAllowed((string) parse_url($u, PHP_URL_HOST), SafePageFetcher::allowedHostsFor($site)) && array_filter($tokens, fn ($t) => str_contains($path, $t))) {
                    $urls[] = $u;
                }
            }
        } catch (\Throwable) {
            $urls = [];
        }
        $urls = array_slice(array_values(array_unique($urls)), 0, 5);
        if ($urls === []) {
            Notification::make()->title('Chưa tìm thấy trang liên quan')->body('Sitemap không có trang nào có đường dẫn chứa từ khóa «'.$gap['query'].'». Dán URL ở ô "Thêm URL" rồi xây kho.')->warning()->send();

            return;
        }
        @set_time_limit(300);
        try {
            $this->result = (new BrainBuildService)->run($site, Auth::user(), ['max_pages' => count($urls), 'urls' => $urls]);
        } catch (KnowledgeError $e) {
            Notification::make()->title('Chưa chạy được')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title('Đã bổ sung từ '.count($urls).' trang liên quan')->body('Chỉ đọc các trang có đường dẫn chứa từ khóa (không tìm theo nghĩa). '.$this->result['items_auto'].' mục tự nhận, '.$this->result['items_proposed'].' mục chờ duyệt.')->success()->send();
    }
}
