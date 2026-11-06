<?php

namespace App\Filament\Pages;

use App\Filament\Support\Competitors\ClusterSuggester;
use App\Filament\Support\Competitors\CompetitorDomain;
use App\Filament\Support\Competitors\CompetitorService;
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
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Đối thủ';

    protected static ?string $navigationGroup = 'Phân tích';

    protected static ?int $navigationSort = 3;

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

    public string $intentFilter = '';

    public int $minVolume = 0;

    public bool $hideExisting = false;

    public string $sortBy = 'score';

    /** @var list<array{keyword:string, ok:bool, message:string}> */
    public array $results = [];

    public ?string $refreshError = null;

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
        if ($site && $this->demo === 'ket-noi' && DemoMode::enabled() && CompetitorService::domains($site) === []) {
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

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $site = $this->website();
        $user = Auth::user();
        $snap = $site ? CompetitorService::snapshot($site) : null;
        $domains = $site ? CompetitorService::domains($site) : [];
        $all = $this->gaps();

        $rows = array_values(array_filter($all, function (array $g): bool {
            return ($this->intentFilter === '' || $g['intent'] === $this->intentFilter)
                && $g['volume'] >= $this->minVolume
                && ! ($this->hideExisting && $g['existing'] !== null);
        }));
        usort($rows, function (array $a, array $b): int {
            if (($a['existing'] === null) !== ($b['existing'] === null)) {
                return $a['existing'] === null ? -1 : 1;
            }

            return match ($this->sortBy) {
                'volume' => $b['volume'] <=> $a['volume'],
                'difficulty' => $a['difficulty'] <=> $b['difficulty'],
                default => ($b['score']['score'] ?? 0) <=> ($a['score']['score'] ?? 0),
            };
        });

        $perDomain = [];
        foreach ($domains as $d) {
            $ranked = array_filter($all, fn ($g) => isset($g['ranks'][$d]));
            $inSystem = array_filter($ranked, fn ($g) => $g['existing'] !== null);
            $perDomain[$d] = ['ranked' => count($ranked), 'in_system' => count($inSystem), 'gap' => count($ranked) - count($inSystem)];
        }

        $fetchedAt = $snap ? Carbon::parse($snap['fetched_at']) : null;

        return [
            'state' => $this->state(),
            'demoOn' => DemoMode::enabled(),
            'site' => $site,
            'websites' => Website::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'domains' => $domains,
            'perDomain' => $perDomain,
            'rows' => $rows,
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
            'log' => (array) DemoStore::get('competitors.log', []),
            'formula' => PriorityScorer::FORMULA,
            'maxCompetitors' => CompetitorDomain::MAX_COMPETITORS,
            'canWrite' => $user instanceof User && $user->isWriter(),
            'intents' => self::INTENTS,
            'ghost' => range(1, 5),
        ];
    }
}
