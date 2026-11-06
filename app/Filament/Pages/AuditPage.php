<?php

namespace App\Filament\Pages;

use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Schedule\VnTime;
use App\Filament\Support\SeoAudit\BriefBuilder;
use App\Filament\Support\SeoAudit\IssueCatalog;
use App\Filament\Support\SeoAudit\ScanBlocked;
use App\Filament\Support\SeoAudit\ScanGuard;
use App\Filament\Support\SeoAudit\SeoAuditService;
use App\Models\User;
use App\Models\Website;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Audit website (task 1.3). Dữ liệu thu thập là MINH HỌA (crawler 1.1 chưa có); phân tích on-page, luật allowlist/6 giờ/quyền,
 * sinh brief là THẬT. Xem SeoAuditService / SeoAuditSource.
 */
class AuditPage extends Page
{
    public const PER_PAGE = 5;

    public const DEMO_STATES = ['dang-quet', 'quet-loi', 'chua-quet', 'chua-ket-noi', 'ngoai-allowlist'];

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Audit website';

    protected static ?string $navigationGroup = 'Phân tích';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Audit website';

    protected static ?string $slug = 'audit-website';

    protected static string $view = 'filament.pages.seo-audit';

    public ?int $websiteId = null;

    public ?string $groupType = null;

    public int $urlPage = 1;

    #[Url(as: 'demo')]
    public ?string $demo = null;

    public bool $briefOpen = false;

    /** @var array{title: string, body: string}|null */
    public ?array $brief = null;

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isWriter();
    }

    public function mount(): void
    {
        $this->websiteId = $this->activeWebsites()->first()?->id;
    }

    /** Chỉ website active (thuộc allowlist) được chọn. */
    public function updatedWebsiteId(): void
    {
        if (! $this->activeWebsites()->contains('id', (int) $this->websiteId)) {
            Notification::make()->title('Không chọn được website này')->body('Chỉ website đang active mới quét được.')->danger()->send();
            $this->websiteId = $this->activeWebsites()->first()?->id;
        }
        $this->groupType = null;
        $this->urlPage = 1;
        $this->briefOpen = false;
        $this->demo = null;
    }

    public function selectGroup(string $type): void
    {
        if (! IssueCatalog::exists($type)) {
            return;
        }
        $this->groupType = $type;
        $this->urlPage = 1;
    }

    public function setUrlPage(int $page): void
    {
        $this->urlPage = max(1, $page);
    }

    public function rescan(): void
    {
        $user = Auth::user();
        $site = $this->site();
        if (! $user instanceof User || ! $site) {
            return;
        }
        if ($this->demoState() === 'dang-quet') {
            Notification::make()->title('Đang quét')->body('Chờ lần quét hiện tại xong.')->warning()->send();

            return;
        }
        if ($this->demoState() === 'ngoai-allowlist') {
            Notification::make()->title('Không quét được')->body($this->outsideReason($site))->danger()->send();

            return;
        }
        try {
            SeoAuditService::make()->rescan($site, $user);
        } catch (ScanBlocked $e) {
            Notification::make()->title('Không quét được')->body($e->getMessage())->danger()->send();

            return;
        }
        $this->demo = null;
        $this->groupType = null;
        $this->urlPage = 1;
        Notification::make()->title('Đã quét lại (dữ liệu mẫu)')->body('Phân tích chạy lại trên bộ trang mẫu; mốc quét đã cập nhật.')->success()->send();
    }

    public function createBrief(): void
    {
        $user = Auth::user();
        $site = $this->site();
        if (! $user instanceof User || ! $site || ! ScanGuard::canAct($user)) {
            Notification::make()->title('Không tạo được brief')->body('Chỉ approver trở lên được tạo brief từ lỗi.')->danger()->send();

            return;
        }
        $state = $this->demoState();
        if ($state === 'dang-quet') {
            Notification::make()->title('Không tạo được brief')->body('Đang quét; chờ quét xong để brief không lấy số liệu nửa chừng.')->warning()->send();

            return;
        }
        $run = $this->run($site);
        $group = $this->selectedGroup($run);
        if (! $run || ! $group) {
            Notification::make()->title('Không tạo được brief')->body('Chưa có nhóm lỗi nào được chọn.')->warning()->send();

            return;
        }
        $this->brief = BriefBuilder::build($site->name, $run['at'], $group, (bool) ($run['sample'] ?? true));
        SeoAuditService::make()->saveBrief($site, $user, $group['type'], $group['urls'], $this->brief);
        $this->briefOpen = true;
    }

    public function closeBrief(): void
    {
        $this->briefOpen = false;
    }

    /** @return \Illuminate\Support\Collection<int, Website> */
    private function activeWebsites()
    {
        return Website::where('status', 'active')->orderBy('name')->get();
    }

    private function site(): ?Website
    {
        $s = $this->websiteId ? Website::find($this->websiteId) : null;

        return $s && $s->status === 'active' ? $s : null;
    }

    private function demoState(): string
    {
        return DemoMode::state($this->demo, self::DEMO_STATES, 'ok');
    }

    private function outsideReason(Website $site): string
    {
        $host = parse_url((string) $site->base_url, PHP_URL_HOST) ?: 'domain này';

        return "{$host} không thuộc danh sách domain được phép quét (minh họa trạng thái ngoài allowlist). Chỉ quản trị viên thêm được domain vào danh sách. Crawler cũng chặn địa chỉ IP nội bộ và endpoint metadata.";
    }

    /** @return array<string, mixed>|null */
    private function run(?Website $site): ?array
    {
        if (! $site) {
            return null;
        }
        $snap = SeoAuditService::make()->snapshot($site);
        if ($snap['status'] !== 'completed' || $this->demoState() === 'chua-quet') {
            return null;
        }

        return $snap['run'];
    }

    /** @param array<string, mixed>|null $run @return array<string, mixed>|null */
    private function selectedGroup(?array $run): ?array
    {
        if (! $run || ! $run['groups']) {
            return null;
        }
        foreach ($run['groups'] as $g) {
            if ($g['type'] === $this->groupType) {
                return $g;
            }
        }

        return $run['groups'][0];
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $service = SeoAuditService::make();
        $websites = $this->activeWebsites();
        $site = $this->site();
        $state = $websites->isEmpty() ? 'chua-ket-noi' : $this->demoState();
        $snap = $site ? $service->snapshot($site) : ['status' => 'none', 'run' => null];
        $crawlerOff = $snap['status'] === 'crawler_off';
        $run = $this->run($site);
        $group = $this->selectedGroup($run);
        $user = Auth::user();
        $canAct = $user instanceof User && ScanGuard::canAct($user);

        $guard = $site ? $service->canScan($site, $user instanceof User ? $user : null) : ['ok' => false, 'code' => 'none', 'reason' => 'Chưa chọn website.', 'next_at' => null];
        if ($state === 'ngoai-allowlist' && $site) {
            $guard = ['ok' => false, 'code' => 'not_allowlisted', 'reason' => $this->outsideReason($site), 'next_at' => null];
        }
        $scanning = $state === 'dang-quet';
        $canRescan = $guard['ok'] && ! $scanning;
        $items = $group['items'] ?? [];
        $pages = max(1, (int) ceil(count($items) / self::PER_PAGE));
        $page = min(max(1, $this->urlPage), $pages);
        $nextAt = $guard['next_at'] ?? null;

        return [
            'websites' => $websites,
            'site' => $site,
            'state' => $state,
            'crawlerOff' => $crawlerOff,
            'run' => $run,
            'group' => $group,
            'canAct' => $canAct,
            'guard' => $guard,
            'canRescan' => $canRescan,
            'scanning' => $scanning,
            'canBrief' => $canAct && $group !== null && ! $scanning,
            'stale' => in_array($state, ['dang-quet', 'quet-loi'], true),
            'pageItems' => array_slice($items, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'pageNo' => $page,
            'pageCount' => $pages,
            'fixSteps' => $group ? IssueCatalog::fixSteps($group['type'], $group['items']) : [],
            'nextAtLabel' => $nextAt ? 'Quét lại được từ '.VnTime::format($nextAt, 'H:i d/m/Y').' (giờ VN).' : null,
            'briefs' => $site ? $service->briefs($site) : [],
            'vn' => fn ($t) => VnTime::format($t, 'H:i · d/m/Y'),
            'utc' => fn ($t) => VnTime::formatUtc($t, 'H:i'),
        ];
    }
}
