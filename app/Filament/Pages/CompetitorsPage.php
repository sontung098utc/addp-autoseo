<?php

namespace App\Filament\Pages;

use App\Filament\Support\Competitors\ClusterSuggester;
use App\Filament\Support\Competitors\CompetitorDomain;
use App\Filament\Support\Competitors\CompetitorService;
use App\Filament\Support\Competitors\CompetitorStore;
use App\Filament\Support\Keywords\CsvKeywordParser;
use App\Models\Competitor;
use App\Models\KeywordGap;
use Livewire\WithFileUploads;
use App\Filament\Support\Competitors\CostPolicy;
use App\Filament\Support\Competitors\GapCalculator;
use App\Filament\Support\Competitors\KeywordGapSource;
use App\Filament\Support\Competitors\PriorityScorer;
use App\Filament\Support\Competitors\SourceUnavailableException;
use App\Filament\Support\Demo\CompetitorsDemoData;
use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Demo\DemoStore;
use App\Filament\Support\Planning\DuplicateKeywordException;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Models\User;
use App\Models\Website;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Url;

class CompetitorsPage extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Đối thủ';

    protected static ?string $navigationGroup = 'Khám phá';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Đối thủ và khoảng trống nội dung';

    protected static ?string $slug = 'doi-thu';

    protected static string $view = 'filament.pages.competitors';

    public const INTENTS = ['informational' => 'Thông tin', 'commercial' => 'Thương mại', 'transactional' => 'Giao dịch', 'navigational' => 'Điều hướng'];

    public const DEMO_STATES = ['chua-ket-noi', 'dang-tai', 'loi-api', 'khong-doi-thu', 'ket-noi'];

    #[Url(as: 'demo')]
    public ?string $demo = null;

    public ?int $websiteId = null;

    public string $newDomain = '';

    /** @var list<string> từ khóa (đã chuẩn hóa) được chọn để thêm vào kế hoạch */
    public array $selected = [];

    /** @var array<string,string> từ khóa chuẩn hóa => tên cụm (khi chọn cả cụm) */
    public array $clusterPick = [];

    /** @var list<array{keyword:string, ok:bool, message:string}> */
    public array $results = [];

    public ?string $refreshError = null;

    // --- Bản thật (AG-S24) ---
    public string $newBrand = '';

    public string $newKind = 'truc_tiep';

    public string $csvText = '';

    public $csvFile = null;

    /** @var ?array{rows:list<array>, errors:list<array>, duplicates:list<array>, fatal:?string} */
    public ?array $csvPreview = null;

    public function isReal(): bool
    {
        return CompetitorStore::useReal();
    }

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    public function mount(): void
    {
        $this->websiteId = Website::where('status', 'active')->orderBy('name')->value('id');
        // ?demo=ket-noi: nạp sẵn 3 đối thủ + dữ liệu mẫu (chỉ khi chưa có và không phải production).
        $site = $this->website();
        if (! $this->isReal() && $site && $this->demo === 'ket-noi' && DemoMode::enabled() && CompetitorService::domains($site) === []) {
            $d = CompetitorsDemoData::sampleDomains();
            CompetitorService::saveDomains($site, $d);
            CompetitorService::saveSnapshot($site, $d, CompetitorsDemoData::rankings($d));
        }
    }

    public function updatedWebsiteId(): void
    {
        $this->selected = [];
        $this->clusterPick = [];
        $this->results = [];
        $this->refreshError = null;
        $this->csvPreview = null;
        $this->csvText = '';
    }

    public function website(): ?Website
    {
        return $this->websiteId ? Website::find($this->websiteId) : null;
    }

    public function state(): string
    {
        if (CompetitorService::source() === null) {
            return 'chua-ket-noi';
        }

        return DemoMode::state($this->demo, self::DEMO_STATES, 'ket-noi');
    }

    private function actor(): User
    {
        $u = Auth::user();
        abort_unless($u instanceof User && $u->is_active && $u->isApprover(), 403);

        return $u;
    }

    private function source(): ?KeywordGapSource
    {
        return CompetitorService::source($this->state() === 'loi-api');
    }

    public function addCompetitor(): void
    {
        $this->actor();
        $site = $this->website();
        if (! $site) {
            return;
        }
        if ($this->isReal()) {
            try {
                CompetitorStore::add($site, $this->actor(), $this->newDomain, $this->newBrand, $this->newKind);
                $this->newDomain = '';
                $this->newBrand = '';
                Notification::make()->title('Đã thêm đối thủ')->body('Chỉ lưu tên miền; hệ thống không truy cập website đối thủ.')->success()->send();
            } catch (InvalidArgumentException $e) {
                Notification::make()->title('Không thêm được')->body($e->getMessage())->danger()->send();
            }

            return;
        }
        try {
            if ($this->source() === null) {
                throw new InvalidArgumentException('Chưa kết nối nguồn từ khóa nên chưa thêm đối thủ được.');
            }
            $list = CompetitorService::domains($site);
            $list[] = CompetitorDomain::validate($this->newDomain, $list, $site->base_url);
            CompetitorService::saveDomains($site, $list);
            $this->newDomain = '';
            Notification::make()->title('Đã thêm đối thủ')->body('Chỉ lưu tên miền; hệ thống không truy cập website đối thủ.')->success()->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Không thêm được')->body($e->getMessage())->danger()->send();
        }
    }

    public function removeCompetitor(string $domain): void
    {
        $this->actor();
        if (! $site = $this->website()) {
            return;
        }
        CompetitorService::saveDomains($site, array_values(array_diff(CompetitorService::domains($site), [$domain])));
    }

    public function removeCompetitorById(int|string $id): void
    {
        $this->actor();
        if ($site = $this->website()) {
            CompetitorStore::remove($site, (int) $id);
        }
    }

    public function updatedCsvFile(): void
    {
        $this->csvPreview = null;
        if ($this->csvFile) {
            if ($this->csvFile->getSize() > CsvKeywordParser::MAX_BYTES) {
                $this->csvText = '';
                $this->csvPreview = ['rows' => [], 'errors' => [], 'duplicates' => [], 'fatal' => 'Tệp vượt quá 1 MB. Hãy tách thành nhiều tệp nhỏ hơn.'];

                return;
            }
            $this->csvText = (string) file_get_contents($this->csvFile->getRealPath());
            $this->previewCsv();
        }
    }

    public function previewCsv(): void
    {
        $this->actor();
        if ($site = $this->website()) {
            $this->csvPreview = CompetitorStore::previewCsv($site, $this->csvText);
        }
    }

    public function importCsv(): void
    {
        $this->actor();
        $site = $this->website();
        if (! $site) {
            return;
        }
        $this->csvPreview = CompetitorStore::previewCsv($site, $this->csvText);
        if ($this->csvPreview['fatal'] || $this->csvPreview['rows'] === []) {
            Notification::make()->title('Chưa nhập được')->body($this->csvPreview['fatal'] ?? 'Không có dòng hợp lệ để nhập.')->danger()->send();

            return;
        }
        $n = CompetitorStore::importRows($site, $this->csvPreview['rows']);
        $bad = count($this->csvPreview['errors']) + count($this->csvPreview['duplicates']);
        $this->csvPreview = null;
        $this->csvText = '';
        $this->csvFile = null;
        Notification::make()->title("Đã nhập $n khoảng trống".($bad ? ", bỏ qua $bad dòng lỗi/trùng" : ''))->success()->send();
    }

    public function addGapKeyword(int|string $id): void
    {
        $user = $this->actor();
        if (! $site = $this->website()) {
            return;
        }
        try {
            $kw = CompetitorStore::addKeyword($site, $user, (int) $id);
            Notification::make()->title('Đã thêm vào Từ khóa')->body('"'.$kw->keyword.'" nguồn Đối thủ. Vào trang Từ khóa để Thêm vào kế hoạch.')->success()->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Không thêm được')->body($e->getMessage())->warning()->send();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            Notification::make()->title('Không tìm thấy khoảng trống')->danger()->send();
        }
    }

    public function ignoreGap(int|string $id): void
    {
        $this->actor();
        if ($site = $this->website()) {
            CompetitorStore::ignore($site, (int) $id);
        }
    }

    public function restoreGap(int|string $id): void
    {
        $this->actor();
        if ($site = $this->website()) {
            CompetitorStore::restore($site, (int) $id);
        }
    }

    /** @return array<string,mixed> */
    private function realViewData(): array
    {
        $site = $this->website();
        $comps = $site ? CompetitorStore::competitors($site) : collect();
        $gaps = $site ? KeywordGap::where('website_id', $site->id)->with('competitor:id,domain')->get() : collect();
        $existing = $site ? GapCalculator::existingFor($site) : [];
        $byComp = $gaps->groupBy('competitor_id');
        $sorted = $gaps->sortBy([fn ($a, $b) => ($b->priority_score ?? -1) <=> ($a->priority_score ?? -1), fn ($a, $b) => ($b->volume ?? -1) <=> ($a->volume ?? -1)])->values();
        $gapRows = [];
        foreach ($sorted as $g) {
            $ex = $existing[PlannedArticleCreator::normalize($g->keyword)] ?? null;
            $gapRows[] = [
                'id' => $g->id,
                'keyword' => $g->keyword,
                'competitor' => $g->competitor?->domain ?? '—',
                'crank' => $g->competitor_rank,
                'orank' => $g->our_rank,
                'volume' => $g->volume,
                'source' => KeywordGap::SOURCES[$g->source] ?? $g->source,
                'score' => $g->priority_score === null ? ['text' => '—', 'tone' => 'muted'] : ['text' => (string) $g->priority_score, 'tone' => $g->priority_score >= 70 ? 'ok' : ($g->priority_score >= 40 ? 'accent' : 'muted')],
                'status' => ['text' => KeywordGap::STATUSES[$g->status].($ex ? ' · đã có bài #'.$ex['id'] : ''), 'tone' => $g->status === 'moi' ? 'accent' : ($g->status === 'da_them_tu_khoa' ? 'ok' : 'muted')],
            ];
        }
        $compRows = $comps->map(fn (Competitor $c) => [
            'id' => $c->id, 'domain' => $c->domain, 'brand' => $c->brand_name ?? '—', 'kind' => Competitor::KINDS[$c->kind] ?? $c->kind,
            'gaps' => ($byComp[$c->id] ?? collect())->where('status', 'moi')->count(),
        ])->all();

        return [
            'site' => $site,
            'websites' => Website::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'compRows' => $compRows,
            'compCount' => $comps->count(),
            'gapRows' => $gapRows,
            'openCount' => $gaps->where('status', 'moi')->count(),
            'addedCount' => $gaps->where('status', 'da_them_tu_khoa')->count(),
            'maxCompetitors' => CompetitorDomain::MAX_COMPETITORS,
            'kinds' => Competitor::KINDS,
            'formulaReal' => PriorityScorer::FORMULA_REAL,
            'maxRows' => CsvKeywordParser::MAX_ROWS,
        ];
    }

    /** @return array{rows:int, cost_vnd:int, remaining:int, blocked:?string} */
    public function estimate(): array
    {
        $n = $this->website() ? count(CompetitorService::domains($this->website())) : 0;

        return CostPolicy::estimate(($this->source()?->estimateRows($n)) ?? 0);
    }

    public function refreshBlockedReason(): ?string
    {
        if ($this->source() === null) {
            return 'Chưa kết nối nguồn từ khóa trả phí.';
        }
        if (! $this->website() || CompetitorService::domains($this->website()) === []) {
            return 'Chưa có đối thủ nào để lấy dữ liệu.';
        }

        return $this->estimate()['blocked'];
    }

    public function refreshAction(): Action
    {
        return Action::make('refresh')
            ->label('Làm mới dữ liệu')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->disabled(fn (): bool => $this->refreshBlockedReason() !== null)
            ->requiresConfirmation()
            ->modalHeading('Xác nhận làm mới dữ liệu (tốn phí)')
            ->modalDescription(function (): string {
                $e = $this->estimate();

                return 'Ước tính: '.number_format($e['rows'], 0, ',', '.').' dòng, khoảng '.number_format($e['cost_vnd'], 0, ',', '.')
                    .' VND (giá giả định). Hạn mức còn lại: '.number_format($e['remaining'], 0, ',', '.').' dòng. Chỉ truy vấn nhà cung cấp, không truy cập website đối thủ.';
            })
            ->modalSubmitActionLabel('Xác nhận và làm mới')
            ->action(fn () => $this->doRefresh());
    }

    public function doRefresh(): void
    {
        $user = $this->actor();
        $site = $this->website();
        if ($reason = $this->refreshBlockedReason()) {
            Notification::make()->title('Không làm mới được')->body($reason)->danger()->send();

            return;
        }
        $domains = CompetitorService::domains($site);
        $est = $this->estimate();
        try {
            $rows = $this->source()->fetch($domains);
        } catch (SourceUnavailableException $e) {
            // KHÔNG đụng bản lưu cũ và KHÔNG trừ hạn mức; bản cũ chỉ hiện kèm nhãn "dữ liệu cũ".
            $this->refreshError = $e->getMessage();
            Notification::make()->title('Không lấy được dữ liệu')->body($e->getMessage())->danger()->send();

            return;
        }
        $this->refreshError = null;
        CostPolicy::charge($est['rows']);
        CompetitorService::saveSnapshot($site, $domains, $rows);
        $log = (array) DemoStore::get('competitors.log', []);
        array_unshift($log, ['by' => $user->name, 'at' => now()->toIso8601String(), 'rows' => $est['rows'], 'cost_vnd' => $est['cost_vnd'], 'website' => $site->name]);
        DemoStore::put('competitors.log', array_slice($log, 0, 10));
        Notification::make()->title('Đã làm mới dữ liệu')->body('Đã trừ '.number_format($est['rows'], 0, ',', '.').' dòng khỏi hạn mức minh họa.')->success()->send();
    }

    /** Chọn cả cụm gợi ý (chỉ chọn, chưa tạo gì). */
    public function selectCluster(int $index): void
    {
        $cl = ClusterSuggester::suggest($this->gaps())[$index] ?? null;
        if (! $cl) {
            return;
        }
        foreach ($cl['keywords'] as $k) {
            $n = PlannedArticleCreator::normalize($k);
            $this->selected[] = $n;
            $this->clusterPick[$n] = $cl['name'];
        }
        $this->selected = array_values(array_unique($this->selected));
    }

    public function addToPlan(): void
    {
        $user = $this->actor();
        $site = $this->website();
        $this->results = [];
        if (! $site || ! $user->isWriter()) {
            return;
        }
        if ($this->refreshError !== null || $this->state() !== 'ket-noi') {
            Notification::make()->title('Chưa thêm được')->body('Nguồn dữ liệu đang lỗi hoặc chưa kết nối; không thêm vào kế hoạch từ dữ liệu cũ.')->danger()->send();

            return;
        }
        $byNorm = [];
        foreach ($this->gaps() as $g) {
            $byNorm[$g['norm']] = $g;
        }
        $creator = new PlannedArticleCreator();
        $created = 0;
        foreach (array_unique($this->selected) as $n) {
            $g = $byNorm[$n] ?? null;
            if (! $g) {
                $this->results[] = ['keyword' => $n, 'ok' => false, 'message' => 'Không còn trong danh sách khoảng trống, tải lại trang.'];

                continue;
            }
            if ($g['existing'] !== null) {
                $this->results[] = ['keyword' => $g['keyword'], 'ok' => false, 'message' => 'Bị chặn vì trùng: đã có bài #'.$g['existing']['id'].' dùng từ khóa này.'];

                continue;
            }
            try {
                $a = $creator->create($user, $site, $g['keyword'], $this->clusterPick[$n] ?? null, $g['intent']);
                $created++;
                $this->results[] = ['keyword' => $g['keyword'], 'ok' => true, 'message' => "Đã tạo bài #{$a->id} (Đã lên kế hoạch)."];
            } catch (DuplicateKeywordException $e) {
                $this->results[] = ['keyword' => $g['keyword'], 'ok' => false, 'message' => 'Bị chặn vì trùng: đã có bài #'.$e->existingArticleId.' dùng từ khóa này.'];
            } catch (InvalidArgumentException $e) {
                $this->results[] = ['keyword' => $g['keyword'], 'ok' => false, 'message' => $e->getMessage()];
            }
        }
        $failed = count($this->results) - $created;
        $this->selected = [];
        $this->clusterPick = [];
        $n = Notification::make()->title("Đã thêm $created vào kế hoạch".($failed ? ", $failed không thêm được" : ''));
        ($failed ? ($created ? $n->warning() : $n->danger()) : $n->success())->send();
    }

    /** Khoảng trống theo bản lưu hiện tại (rỗng nếu chưa có hoặc nguồn đang lỗi). @return list<array<string,mixed>> */
    public function gaps(): array
    {
        $site = $this->website();
        $snap = $site ? CompetitorService::snapshot($site) : null;
        if (! $snap || $this->refreshError !== null) {
            return [];
        }
        $domains = CompetitorService::domains($site);
        $rows = [];
        foreach ($snap['rows'] as $r) {
            $r['ranks'] = array_intersect_key($r['ranks'], array_flip($domains));
            $rows[] = $r;
        }

        return GapCalculator::calculate($rows, GapCalculator::existingFor($site), count($domains));
    }

    /** @return list<array<string,mixed>> */
    private function gapColumns(): array
    {
        return [
            ['key' => 'keyword', 'label' => 'Từ khóa', 'type' => 'text', 'sortable' => true],
            ['key' => 'intent', 'label' => 'Ý định', 'type' => 'badge', 'sortable' => true, 'filter' => true, 'tones' => ['Thông tin' => 'info', 'Thương mại' => 'accent', 'Giao dịch' => 'warn', 'Điều hướng' => 'muted']],
            ['key' => 'volume', 'label' => 'Volume / tháng', 'type' => 'number', 'sortable' => true],
            ['key' => 'difficulty', 'label' => 'Độ khó', 'type' => 'number', 'sortable' => true],
            ['key' => 'ranks', 'label' => 'Đối thủ xếp hạng', 'type' => 'text'],
            ['key' => 'status', 'label' => 'Trong kế hoạch', 'type' => 'badge', 'sortable' => true, 'tones' => ['Chưa có bài' => 'muted']],
            ['key' => 'score', 'label' => 'Điểm ưu tiên', 'type' => 'badge', 'sortable' => true],
            ['key' => 'reason', 'label' => 'Lý do điểm', 'type' => 'text', 'visible' => false],
        ];
    }

    /**
     * Dòng bảng khoảng trống (mảng thuần). id = từ khóa chuẩn hóa, khớp $selected.
     *
     * @param  list<array<string,mixed>>  $all
     * @return list<array<string,mixed>>
     */
    private function gapRows(array $all): array
    {
        $rows = [];
        foreach ($all as $g) {
            $ranks = [];
            foreach ($g['ranks'] as $dom => $pos) {
                $ranks[] = $dom.' #'.$pos;
            }
            $score = $g['score']['score'] ?? null;
            $rows[] = [
                'id' => $g['norm'],
                'keyword' => $g['keyword'],
                'intent' => self::INTENTS[$g['intent']] ?? (string) $g['intent'],
                'volume' => $g['volume'],
                'difficulty' => $g['difficulty'],
                'ranks' => implode('; ', $ranks),
                'status' => $g['existing']
                    ? ['text' => ($g['existing']['status'] === 'planned' ? 'Đã lên kế hoạch' : 'Đã có bài').' #'.$g['existing']['id'], 'tone' => 'info']
                    : 'Chưa có bài',
                'score' => $score === null ? ['text' => '—', 'tone' => 'muted'] : ['text' => (string) $score, 'tone' => $score >= 70 ? 'ok' : ($score >= 40 ? 'accent' : 'muted')],
                'reason' => $g['score']['reason'] ?? 'Đã có trong hệ thống nên không chấm điểm.',
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function clusterRows(array $all): array
    {
        $rows = [];
        foreach (ClusterSuggester::suggest($all) as $i => $c) {
            $rows[] = ['id' => $i, 'name' => $c['name'], 'count' => count($c['keywords']), 'volume' => $c['volume'], 'keywords' => implode('; ', $c['keywords'])];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function domainRows(array $domains, array $perDomain): array
    {
        $rows = [];
        foreach ($domains as $d) {
            $p = $perDomain[$d] ?? ['ranked' => 0, 'in_system' => 0, 'gap' => 0];
            $rows[] = ['id' => $d, 'domain' => $d, 'ranked' => $p['ranked'], 'in_system' => $p['in_system'], 'overlap' => $p['ranked'] ? round(100 * $p['in_system'] / $p['ranked'], 1) : null, 'gap' => $p['gap']];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function logRows(): array
    {
        $rows = [];
        foreach ((array) DemoStore::get('competitors.log', []) as $i => $l) {
            $rows[] = ['id' => $i, 'at' => Carbon::parse($l['at'])->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i'), 'by' => $l['by'], 'website' => $l['website'], 'rows' => $l['rows'], 'cost' => $l['cost_vnd']];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        if ($this->isReal()) {
            return ['real' => true, 'canWrite' => Auth::user() instanceof User && Auth::user()->isWriter()] + $this->realViewData();
        }
        $site = $this->website();
        $user = Auth::user();
        $snap = $site ? CompetitorService::snapshot($site) : null;
        $domains = $site ? CompetitorService::domains($site) : [];
        $all = $this->gaps();

        $perDomain = [];
        foreach ($domains as $d) {
            $ranked = array_filter($all, fn ($g) => isset($g['ranks'][$d]));
            $inSystem = array_filter($ranked, fn ($g) => $g['existing'] !== null);
            $perDomain[$d] = ['ranked' => count($ranked), 'in_system' => count($inSystem), 'gap' => count($ranked) - count($inSystem)];
        }

        $fetchedAt = $snap ? Carbon::parse($snap['fetched_at']) : null;

        return [
            'real' => false,
            'state' => $this->state(),
            'demoOn' => DemoMode::enabled(),
            'site' => $site,
            'websites' => Website::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'domains' => $domains,
            'perDomain' => $perDomain,
            'gapColumns' => $this->gapColumns(),
            'gapRows' => $this->gapRows($all),
            'clusterRows' => $this->clusterRows($all),
            'domainRows' => $this->domainRows($domains, $perDomain),
            'logRows' => $this->logRows(),
            'totalGaps' => count($all),
            'openCount' => count(array_filter($all, fn ($g) => $g['existing'] === null)),
            'clusters' => ClusterSuggester::suggest($all),
            'estimate' => $this->estimate(),
            'blockedReason' => $this->refreshBlockedReason(),
            'monthlyLimit' => CostPolicy::MONTHLY_ROW_LIMIT,
            'used' => CostPolicy::used(),
            'vndPer1000' => CostPolicy::VND_PER_1000_ROWS,
            'fetchedLabel' => $fetchedAt?->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i'),
            'staleDays' => $fetchedAt ? (int) $fetchedAt->diffInDays(now()) : null,
            'logCount' => count((array) DemoStore::get('competitors.log', [])),
            'formula' => PriorityScorer::FORMULA,
            'maxCompetitors' => CompetitorDomain::MAX_COMPETITORS,
            'canWrite' => $user instanceof User && $user->isWriter(),
        ];
    }
}
