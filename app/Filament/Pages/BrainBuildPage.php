<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\KhoTriTue;
use App\Models\AgentRun;
use App\Models\KnowledgeItem;
use App\Models\Website;
use App\Services\Brand\BrandProfileData;
use App\Services\Crawler\SitemapCrawlerService;
use App\Services\Knowledge\Build\BrainBuildService;
use App\Services\Knowledge\Build\BrandProfileDrafter;
use App\Services\Knowledge\Build\SafePageFetcher;
use App\Services\Knowledge\Discover\SourceDiscoveryService;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Knowledge\KnowledgeReview;
use App\Services\Knowledge\Suggest\KnowledgeSuggestions;
use App\Services\Onboarding\WebsiteOnboarding;
use App\Services\Search\WebSearchResolver;
use App\Services\Search\WebSearchUnavailable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/** G2: xây kho trí tuệ từ chính website. Chạy đồng bộ trong trang (chưa có chạy nền). Chỉ người duyệt trở lên. */
class BrainBuildPage extends Page
{
    /** Z2: thành tab "Nạp kiến thức" của cụm Kho trí tuệ (gồm cả Khởi tạo nhanh, trước là trang Khởi tạo website). URL cũ /admin/xay-kho chuyển hướng ở routes/web.php. */
    protected static ?string $cluster = KhoTriTue::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Nạp kiến thức';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Nạp kiến thức';

    protected static ?string $slug = 'nap-kien-thuc';

    protected static string $view = 'filament.pages.brain-build';

    public ?int $websiteId = null;

    public $maxPages = BrainBuildService::DEFAULT_PAGES;

    public string $urlsText = '';

    /** @var array<string,mixed>|null kết quả lượt vừa chạy */
    public ?array $result = null;

    /** Khám phá nguồn trên web: chủ đề (có thể đến từ ?topic= của bài), ứng viên tìm được, nguồn đã chọn (URL), báo cáo lần thêm gần nhất. */
    #[Url]
    public string $topic = '';

    /** @var list<array<string,mixed>> */
    public array $candidates = [];

    /** @var array<string,mixed>|null */
    public ?array $searchMeta = null;

    /** @var list<string> */
    public array $picked = [];

    /** @var array<string,mixed>|null */
    public ?array $webReport = null;

    /** @var array<string,mixed>|null Z2 Khởi tạo nhanh (trước là WebsiteOnboardingPage): kết quả các bước của lần chạy vừa xong */
    public ?array $onboardResult = null;

    public static function canAccess(): bool
    {
        return KnowledgeReview::canManage(Auth::user());
    }

    /** Khởi tạo nhanh giữ quyền cũ của trang Khởi tạo website: người duyệt trở lên. */
    public static function canOnboard(): bool
    {
        return WebsiteOnboardingPage::canAccess();
    }

    /** Khởi tạo nhanh: kho + hồ sơ + Trí tuệ + từ khóa + gợi ý kế hoạch, một nút. Chạy đồng bộ như trước. */
    public function startOnboarding(): void
    {
        abort_unless(static::canAccess() && static::canOnboard(), 403);
        $site = Website::query()->where('status', 'active')->find($this->websiteId);
        if (! $site) {
            Notification::make()->title('Chọn một website đang hoạt động')->warning()->send();

            return;
        }
        @set_time_limit(300);
        $this->onboardResult = (new WebsiteOnboarding)->run($site, Auth::user());
        $ok = count(array_filter($this->onboardResult['steps'], fn ($s) => $s['status'] === 'ok'));
        Notification::make()->title("Khởi tạo xong: {$ok}/".count($this->onboardResult['steps']).' bước hoàn tất')->success()->send();
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
        $auto = KnowledgeItem::query()->with('source')->where('review_mode', 'auto')->where('status', 'approved')->when($this->websiteId, fn ($q) => $q->where('website_id', $this->websiteId))->latest('id')->limit(30)->get();

        $brand = $site ? BrandProfileDrafter::last($site->id) : null;
        $suggestions = $site ? (new KnowledgeSuggestions)->forWebsite($site, 20) : [];
        $brandProfile = $site ? BrandProfileData::forWebsite($site->id) : null;

        return ['brandAuto' => $brand, 'brandMissing' => BrandProfileData::completeness($brandProfile)['missing'], 'suggestions' => $suggestions, 'sites' => Website::query()->orderBy('name')->get(['id', 'name', 'base_url', 'status']), 'off' => $off, 'avLabel' => $av['label'], 'avDemo' => $av['demo'], 'runs' => $runs, 'autoItems' => $auto, 'statusLabels' => ['running' => 'Đang chạy', 'ready_for_review' => 'Xong', 'blocked' => 'Bị chặn', 'failed' => 'Lỗi', 'held' => 'Tạm giữ'], 'reviewUrl' => KnowledgePage::getUrl(), 'pendingCount' => KnowledgeItem::query()->where('status', 'proposed')->when($this->websiteId, fn ($q) => $q->where('website_id', $this->websiteId))->count(), 'canOnboard' => static::canOnboard(), 'onboardLinks' => ['keywords' => KeywordsPage::getUrl(), 'plan' => ContentPlanPage::getUrl()], 'webAv' => (new WebSearchResolver)->availability()];
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

    /** "Tìm nguồn": soạn truy vấn bằng luật, tìm trên web, hiện danh sách nguồn có nhãn tin cậy và lý do. Chưa tải trang nào. */
    public function discover(): void
    {
        $this->guard();
        $this->webReport = null;
        $site = $this->websiteId ? Website::find($this->websiteId) : null;
        if ($site === null || trim($this->topic) === '') {
            Notification::make()->title('Chưa tìm được')->body('Chọn website và nhập chủ đề cần tìm tài liệu.')->warning()->send();

            return;
        }
        try {
            $d = app(SourceDiscoveryService::class)->discover($site, $this->topic);
        } catch (WebSearchUnavailable $e) {
            $this->candidates = [];
            $this->searchMeta = null;
            Notification::make()->title('Chưa tìm được')->body($e->getMessage())->warning()->send();

            return;
        }
        $this->candidates = $d['candidates'];
        $this->searchMeta = ['query' => $d['query'], 'results' => count($d['candidates']), 'cost_vnd' => $d['cost_vnd'], 'provider' => $d['provider'], 'field' => $d['field'], 'demo' => $d['demo'], 'label' => $d['label']];
        $this->picked = [];
        if ($this->candidates === []) {
            Notification::make()->title('Không có nguồn phù hợp')->body('Thử chủ đề khác hoặc cụ thể hơn.')->warning()->send();
        }
    }

    public function addPicked(): void
    {
        $this->importWeb(array_values(array_filter($this->candidates, fn ($c) => in_array($c['url'], $this->picked, true))));
    }

    /** Một cú bấm: thêm mọi nguồn tin cậy, không hỏi thêm. */
    public function addAllTrusted(): void
    {
        $this->importWeb(array_values(array_filter($this->candidates, fn ($c) => ($c['trust']['level'] ?? '') === 'trusted')));
    }

    /** @param list<array<string,mixed>> $chosen */
    private function importWeb(array $chosen): void
    {
        $this->guard();
        $site = $this->websiteId ? Website::find($this->websiteId) : null;
        if ($site === null || $chosen === []) {
            Notification::make()->title('Chưa có nguồn nào được chọn')->warning()->send();

            return;
        }
        @set_time_limit(300);
        try {
            $r = app(SourceDiscoveryService::class)->import($site, $chosen, Auth::user(), ['field' => $this->searchMeta['field'] ?? null, 'search' => $this->searchMeta ?? []]);
        } catch (KnowledgeError $e) {
            Notification::make()->title('Chưa thêm được')->body($e->getMessage())->danger()->send();

            return;
        }
        $this->webReport = $r;
        $this->searchMeta = null; // chi phí tìm chỉ tính vào lần thêm đầu
        $this->picked = [];
        Notification::make()->title('Đã thêm nguồn')->body($r['imported'].' nguồn đã đọc, '.$r['items_auto'].' mục tự nhận, '.$r['items_proposed'].' mục chờ duyệt.')->success()->send();
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
