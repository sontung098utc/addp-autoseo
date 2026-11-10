<?php

namespace App\Filament\Pages;

use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Schedule\VnTime;
use App\Filament\Support\SeoAudit\BriefBuilder;
use App\Filament\Support\SeoAudit\IssueCatalog;
use App\Filament\Support\SeoAudit\ScanBlocked;
use App\Filament\Support\SeoAudit\ScanGuard;
use App\Filament\Support\SeoAudit\SeoAuditService;
use App\Models\AuditSuggestion;
use App\Models\User;
use App\Services\Audit\AuditSuggestionException;
use App\Services\Audit\AuditSuggestionService;
use App\Services\Audit\RealScanService;
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
    public const DEMO_STATES = ['dang-quet', 'quet-loi', 'chua-quet', 'chua-ket-noi', 'ngoai-allowlist'];

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Audit website';

    protected static ?string $navigationGroup = 'Khám phá';

    protected static ?int $navigationSort = 4;

    protected static ?string $title = 'Audit website';

    protected static ?string $slug = 'audit-website';

    protected static string $view = 'filament.pages.seo-audit';

    public ?int $websiteId = null;

    public ?string $groupType = null;

    #[Url(as: 'demo')]
    public ?string $demo = null;

    public bool $briefOpen = false;

    public bool $realScanOpen = false;

    public int $realScanLimit = RealScanService::DEFAULT_LIMIT;

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
        $this->briefOpen = false;
        $this->demo = null;
    }

    public function selectGroup(string $type): void
    {
        if (! IssueCatalog::exists($type)) {
            return;
        }
        $this->groupType = $type;
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
        Notification::make()->title('Đã quét lại (dữ liệu mẫu)')->body('Phân tích chạy lại trên bộ trang mẫu; mốc quét đã cập nhật.')->success()->send();
    }

    public function openRealScan(): void
    {
        $user = Auth::user();
        if ($user instanceof User && ScanGuard::canAct($user)) {
            $this->realScanOpen = true;
        }
    }

    public function closeRealScan(): void
    {
        $this->realScanOpen = false;
    }

    public function realScan(): void
    {
        $user = Auth::user();
        $site = $this->site();
        if (! $user instanceof User || ! $site || ! ScanGuard::canAct($user)) {
            Notification::make()->title('Không quét được')->body('Chỉ approver trở lên được quét thật.')->danger()->send();

            return;
        }
        $limit = RealScanService::clamp($this->realScanLimit);
        $this->realScanLimit = $limit;
        try {
            $audit = (new RealScanService())->run($site, $user, $limit);
        } catch (ScanBlocked $e) {
            Notification::make()->title('Không quét được')->body($e->getMessage())->danger()->send();

            return;
        }
        $this->realScanOpen = false;
        $this->demo = null;
        $this->groupType = null;
        $n = (int) $audit->total_urls_scanned;
        $note = $n <= 1 ? ' Chỉ quét được 1 trang (không tìm thấy sitemap hoặc sitemap trống, đã quét trang chủ).' : '';
        Notification::make()->title("Đã quét thật {$n} trang (giới hạn {$limit})")->body('Kết quả là dữ liệu quét thật.'.$note)->success()->send();
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


    /** AG-S26: chỉ dữ liệu quét THẬT mới sinh đề xuất; dữ liệu minh họa không tạo việc. */
    private function realRun(?array $run): bool
    {
        return $run !== null && ($run['sample'] ?? true) === false;
    }

    public function suggestRefresh(): void
    {
        $user = Auth::user();
        $site = $this->site();
        $run = $this->run($site);
        if (! $user instanceof User || ! $site || ! ScanGuard::canAct($user)) {
            Notification::make()->title('Không tạo được đề xuất')->body('Chỉ approver trở lên được tạo đề xuất làm mới.')->danger()->send();

            return;
        }
        if (! $this->realRun($run)) {
            Notification::make()->title('Dữ liệu minh họa: không tạo việc')->body('Đề xuất chỉ sinh từ kết quả quét thật của crawler.')->warning()->send();

            return;
        }
        $r = (new AuditSuggestionService())->generate($site);
        if ($r['skipped_reason']) {
            Notification::make()->title('Chưa tạo đề xuất')->body($r['skipped_reason'])->warning()->send();

            return;
        }
        Notification::make()->title("Đã tạo {$r['created']} đề xuất mới")->body("{$r['existing']} URL đã có đề xuất trước đó. Đề xuất chưa thành việc cho đến khi bạn chấp nhận.")->success()->send();
    }

    public function acceptSuggestion(int $id): void
    {
        $this->decide(fn (AuditSuggestionService $svc, User $u, AuditSuggestion $s) => $svc->accept($s, $u), $id, 'Đã chấp nhận: bài làm mới được thêm vào Kế hoạch (chờ chiến dịch giao cho đội AI).');
    }

    public function dismissSuggestion(int $id): void
    {
        $this->decide(fn (AuditSuggestionService $svc, User $u, AuditSuggestion $s) => $svc->dismiss($s, $u), $id, 'Đã bỏ qua đề xuất.');
    }

    public function acceptAllSuggestions(): void
    {
        $user = Auth::user();
        $site = $this->site();
        if (! $user instanceof User || ! $site) {
            return;
        }
        try {
            $ids = AuditSuggestion::query()->where('website_id', $site->id)->where('status', 'de_xuat')->whereNotNull('article_id')->pluck('id')->all();
            $r = (new AuditSuggestionService())->acceptMany($ids, $user);
        } catch (AuditSuggestionException $e) {
            Notification::make()->title('Không chấp nhận được')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title("Đã chấp nhận {$r['accepted']} đề xuất")->body($r['failed'] ? "{$r['failed']} đề xuất không xử lý được." : null)->success()->send();
    }

    private function decide(\Closure $do, int $id, string $ok): void
    {
        $user = Auth::user();
        $s = AuditSuggestion::query()->where('website_id', (int) $this->websiteId)->find($id);
        if (! $user instanceof User || ! $s) {
            return;
        }
        try {
            $do(new AuditSuggestionService(), $user, $s);
        } catch (AuditSuggestionException $e) {
            Notification::make()->title('Không thực hiện được')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($ok)->success()->send();
    }

    /** @return list<array<string, mixed>> */
    private function suggestionRows(?Website $site): array
    {
        if (! $site) {
            return [];
        }
        $label = ['de_xuat' => 'Đề xuất', 'da_chap_nhan' => 'Đã chấp nhận', 'bo_qua' => 'Bỏ qua', 'da_xong' => 'Đã xong'];

        return AuditSuggestion::query()->where('website_id', $site->id)->with(['article', 'task', 'decider'])->orderByDesc('priority')->orderBy('id')->limit(200)->get()
            ->map(fn (AuditSuggestion $s) => [
                'id' => $s->id, 'url' => $s->url, 'severity' => $s->severity, 'status' => $s->status, 'status_label' => $label[$s->status] ?? $s->status,
                'issues' => array_map(fn ($t) => IssueCatalog::exists((string) $t) ? IssueCatalog::label((string) $t) : (string) $t, (array) $s->issue_codes),
                'types' => (array) $s->issue_codes,
                'article' => $s->article ? '#'.$s->article->id.' '.$s->article->primary_keyword : null,
                'task' => $s->task ? '#'.$s->task->id : null,
                'decided' => $s->decided_at ? ($s->decider?->name ?? '—').' · '.VnTime::format($s->decided_at, 'd/m H:i') : null,
            ])->all();
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

    /** @param array<string, mixed>|null $run @return list<array<string, mixed>> */
    private function groupRows(?array $run): array
    {
        $tones = ['P0' => 'danger', 'P1' => 'warn', 'P2' => 'muted'];

        return array_map(fn ($g) => [
            'id' => $g['type'],
            'severity' => ['text' => $g['severity'], 'tone' => $tones[$g['severity']] ?? 'muted'],
            'label' => $g['label'],
            'sub' => $g['sub'],
            'urls' => $g['urls'],
            'delta' => $g['delta'],
            '_tone' => $g['severity'] === 'P0' ? 'danger' : null,
        ], $run['groups'] ?? []);
    }

    /** @param array<string, mixed>|null $group @return list<array<string, mixed>> */
    private function evidenceRows(?array $group): array
    {
        $rows = [];
        foreach ($group['items'] ?? [] as $i => $it) {
            $rows[] = ['id' => $i + 1, 'url' => $it['url'], 'measured' => (string) $it['measured'], 'evidence' => (string) $it['evidence']];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $briefs @return list<array<string, mixed>> */
    private function briefRows(array $briefs): array
    {
        return array_map(fn ($b) => [
            'id' => $b['id'],
            'title' => $b['title'],
            'by' => $b['created_by_name'],
            'at' => VnTime::format($b['created_at'], 'd/m/Y H:i'),
        ], $briefs);
    }

    /** Số URL-lỗi và chênh lệch so với lần quét trước theo mức (P0/P1/P2). @param array<string, mixed>|null $run @return array<string, array{n:int, delta:int|null}> */
    private function sevStats(?array $run): array
    {
        $out = ['P0' => ['n' => 0, 'delta' => null], 'P1' => ['n' => 0, 'delta' => null], 'P2' => ['n' => 0, 'delta' => null]];
        foreach ($run['groups'] ?? [] as $g) {
            $out[$g['severity']]['n'] += $g['urls'];
            if ($g['delta'] !== null) {
                $out[$g['severity']]['delta'] = ($out[$g['severity']]['delta'] ?? 0) + $g['delta'];
            }
        }

        return $out;
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
        $nextAt = $guard['next_at'] ?? null;

        return [
            'canRealScan' => $canAct && $site !== null && $state !== 'chua-ket-noi',
            'realScanMax' => RealScanService::MAX_LIMIT,
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
            'groupRows' => $this->groupRows($run),
            'evidenceRows' => $this->evidenceRows($group),
            'briefRows' => $this->briefRows($site ? $service->briefs($site) : []),
            'sevStats' => $this->sevStats($run),
            'realRun' => $this->realRun($run),
            'suggestions' => $this->realRun($run) ? $this->suggestionRows($site) : [],
            'suggestBlocker' => $this->realRun($run) && $site ? (new AuditSuggestionService())->blocker($site) : null,
            'fixSteps' => $group ? IssueCatalog::fixSteps($group['type'], $group['items']) : [],
            'nextAtLabel' => $nextAt ? 'Quét lại được từ '.VnTime::format($nextAt, 'H:i d/m/Y').' (giờ VN).' : null,
            'briefs' => $site ? $service->briefs($site) : [],
            'vn' => fn ($t) => VnTime::format($t, 'H:i · d/m/Y'),
            'utc' => fn ($t) => VnTime::formatUtc($t, 'H:i'),
        ];
    }
}
