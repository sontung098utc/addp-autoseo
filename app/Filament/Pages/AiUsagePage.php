<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\ManagesAiAccounts;
use App\Filament\Resources\ArticleResource;
use App\Filament\Support\AiUsage\UsageAggregator;
use App\Filament\Support\AiUsage\UsageReader;
use App\Filament\Support\AiUsage\UsageThresholds;
use App\Filament\Support\AiUsage\Vnd;
use App\Filament\Support\Demo\AiUsageDemoData;
use App\Filament\Support\Schedule\VnTime;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Throwable;

/** Chi phí AI: hai tab ngang. "Chi phí" (số liệu) và "Cấu hình AI" (tài khoản/khóa/model, trước là trang Tích hợp AI; URL cũ chuyển về ?tab=cau-hinh). */
class AiUsagePage extends Page
{
    use ManagesAiAccounts;

    public const TABS = ['chi-phi' => 'Chi phí', 'cau-hinh' => 'Cấu hình AI'];

    #[Url(as: 'tab')]
    public string $tab = 'chi-phi';

    public static function configUrl(): string
    {
        return static::getUrl(['tab' => 'cau-hinh']);
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'chi-phi';
        $this->showPicker = false;
    }

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Chi phí AI';

    protected static ?string $navigationGroup = 'Cài đặt';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Chi phí AI';

    protected static ?string $slug = 'chi-phi-ai';

    protected static string $view = 'filament.pages.ai-usage';

    public const PERIODS = ['thang-nay' => 'Tháng này', 'thang-truoc' => 'Tháng trước', '30-ngay' => '30 ngày gần nhất', 'tuy-chon' => 'Tùy chọn'];

    public string $period = 'thang-nay';

    public ?string $customFrom = null;

    public ?string $customTo = null;

    public string|int|null $websiteId = null;

    private function siteId(): ?int
    {
        return is_numeric($this->websiteId) && (int) $this->websiteId > 0 ? (int) $this->websiteId : null;
    }

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->customTo = VnTime::now()->format('Y-m-d');
        $this->customFrom = VnTime::now()->subDays(6)->format('Y-m-d');
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable,2:string,3:?string} [từ (gồm), đến (không gồm), nhãn, lỗi] theo giờ VN */
    public function range(): array
    {
        $now = VnTime::now();
        $tomorrow = $now->startOfDay()->addDay();
        $error = null;
        switch ($this->period) {
            case 'thang-truoc':
                $from = $now->startOfMonth()->subMonth();
                $to = $now->startOfMonth();
                break;
            case '30-ngay':
                $from = $now->startOfDay()->subDays(29);
                $to = $tomorrow;
                break;
            case 'tuy-chon':
                try {
                    $f = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->customFrom, VnTime::TZ);
                    $t = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->customTo, VnTime::TZ);
                } catch (Throwable) {
                    $f = $t = false;
                }
                if (! $f || ! $t || $f->gt($t)) {
                    $error = 'Khoảng ngày không hợp lệ (ngày bắt đầu phải trước hoặc bằng ngày kết thúc). Đang hiện Tháng này.';
                } elseif ($f->diffInDays($t) > 92) {
                    $error = 'Khoảng tùy chọn tối đa 93 ngày. Đang hiện Tháng này.';
                } else {
                    $from = $f;
                    $to = $t->addDay();
                    break;
                }
                // no break: lỗi thì rơi về Tháng này
            default:
                $from = $now->startOfMonth();
                $to = $tomorrow;
        }
        $label = $from->format('d/m/Y').' – '.$to->subDay()->format('d/m/Y');

        return [$from, $to, $label, $error];
    }

    protected function getHeaderActions(): array
    {
        if ($this->tab === 'cau-hinh') {
            // addProvider được mở từ bộ chọn nhà cung cấp trong trang (nút ẩn chỉ để Filament nhận diện action).
            return [$this->editLimitsAction(), $this->addProviderAction()->extraAttributes(['style' => 'display:none', 'aria-hidden' => 'true', 'tabindex' => '-1'])];
        }

        return [
            Action::make('setThreshold')
                ->label('Đặt ngưỡng cảnh báo')
                ->color('primary')
                ->slideOver()
                ->modalHeading('Đặt ngưỡng cảnh báo')
                ->modalSubmitActionLabel('Lưu ngưỡng')
                ->fillForm(fn (): array => UsageThresholds::get())
                ->form([
                    TextInput::make('article')->label('Ngưỡng theo bài (₫/bài)')->required()->numeric()->minValue(1)->integer()
                        ->helperText('Tổng chi phí mọi phiên bản của một bài. Mặc định 3.000 ₫ theo báo cáo PoC.'),
                    TextInput::make('month')->label('Ngưỡng theo tháng (₫/tháng)')->numeric()->minValue(1)->integer()
                        ->helperText('Tháng dương lịch, giờ Việt Nam, toàn hệ thống. Để trống = không cảnh báo theo tháng.'),
                ])
                ->action(function (array $data, Action $action): void {
                    $this->actor();
                    try {
                        UsageThresholds::save($data['article'] ?? null, $data['month'] ?? null);
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->title('Chưa lưu được ngưỡng')->body($e->getMessage())->danger()->send();
                        $action->halt();
                    }
                    Notification::make()->title('Đã lưu ngưỡng cảnh báo')->success()->send();
                }),
        ];
    }

    private function actor(): User
    {
        $u = Auth::user();
        abort_unless($u instanceof User && $u->is_active && $u->isApprover(), 403);

        return $u;
    }

    public function getViewData(): array
    {
        if ($this->tab === 'cau-hinh') {
            return ['tab' => 'cau-hinh'] + $this->configViewData();
        }

        [$from, $to, $label, $rangeError] = $this->range();
        $rows = UsageReader::rows($this->siteId());
        $agg = new UsageAggregator($rows);
        $th = UsageThresholds::get();
        $summary = $agg->summary($from, $to);
        $span = (int) $from->diffInDays($to);
        $prev = $agg->summary($from->subDays($span), $from);

        $monthAgg = $this->siteId() === null ? $agg : new UsageAggregator(UsageReader::rows());
        $monthCost = $monthAgg->monthCost(VnTime::now());
        $monthExceeds = UsageThresholds::exceeds($monthCost, $th['month']);

        $articles = $summary ? $agg->topArticles($from, $to, PHP_INT_MAX) : [];
        $overArticles = array_values(array_filter($articles, fn ($a) => UsageThresholds::exceeds($a['cost'], $th['article'])));
        $days = $summary ? $agg->daily($from, $to) : [];
        $peak = 0.0;
        $peakDay = null;
        foreach ($days as $d) {
            if ($d['cost'] > $peak) {
                $peak = $d['cost'];
                $peakDay = $d['date'];
            }
        }
        $now = VnTime::now();
        $pace = $th['month'] !== null ? $th['month'] / $now->daysInMonth : null;

        return [
            'tab' => 'chi-phi',
            'websites' => Website::orderBy('name')->get(['id', 'name']),
            'label' => $label,
            'rangeError' => $rangeError,
            'summary' => $summary,
            'th' => $th,
            'avgExceeds' => $summary && UsageThresholds::exceeds($summary['avg_per_article'], $th['article']),
            'monthCost' => $monthCost,
            'monthExceeds' => $monthExceeds,
            'monthLabel' => 'tháng '.$now->format('m/Y'),
            'overArticles' => $overArticles,
            'topArticles' => array_slice($articles, 0, 10),
            'days' => $days,
            'peak' => $peak,
            'peakDay' => $peakDay,
            'pace' => $pace,
            'byModel' => $summary ? $agg->groupBy('model', $from, $to) : [],
            'byPrompt' => $summary ? $agg->groupBy('prompt', $from, $to) : [],
            'bySite' => $summary ? $agg->groupBy('website', $from, $to) : [],
            'hasDemo' => AiUsageDemoData::hasSample(),
            'deltas' => $this->deltas($summary, $prev),
            'modelRows' => $this->groupRows($summary ? $agg->groupBy('model', $from, $to) : [], $th['article'], true),
            'promptRows' => $this->groupRows($summary ? $agg->groupBy('prompt', $from, $to) : [], $th['article']),
            'siteRows' => $this->groupRows($summary ? $agg->groupBy('website', $from, $to) : [], $th['article']),
            'topRows' => $this->topRows(array_slice($articles, 0, 10), $th['article']),
            'dayRows' => $this->dayRows($days),
        ];
    }

    /** Thay đổi so với kỳ liền trước cùng độ dài; null khi không tính được. Chi phí: % ; số bài/phiên bản: số tuyệt đối. */
    private function deltas(?array $cur, ?array $prev): array
    {
        $pct = fn ($a, $b) => ($a === null || $b === null || (float) $b == 0.0) ? null : round(($a - $b) / $b * 100, 1);
        if (! $cur || ! $prev) {
            return ['cost' => null, 'avg' => null, 'regen' => null, 'unrecorded' => null];
        }

        return [
            'cost' => $pct($cur['cost'], $prev['cost']),
            'avg' => $pct($cur['avg_per_article'], $prev['avg_per_article']),
            'regen' => $cur['regen_articles'] - $prev['regen_articles'],
            'unrecorded' => $cur['unrecorded'] - $prev['unrecorded'],
        ];
    }

    private static function overBadge(?float $v, ?int $limit): array|string
    {
        if ($v === null) {
            return '—';
        }

        return UsageThresholds::exceeds($v, $limit) ? ['text' => '▲ Vượt ngưỡng', 'tone' => 'danger'] : ['text' => 'Trong ngưỡng', 'tone' => 'ok'];
    }

    /** @return list<array<string,mixed>> */
    private function groupRows(array $groups, ?int $limit, bool $withProvider = false): array
    {
        $rows = [];
        foreach ($groups as $i => $g) {
            $rows[] = [
                'id' => $i,
                'label' => $g['label'],
                'provider' => $g['provider'] ?? '',
                'articles' => $g['articles'],
                'versions' => $g['versions'],
                'tokens' => $g['tokens'],
                'cost' => $g['cost'] === null ? null : round($g['cost']),
                'share' => $g['share'] === null ? null : round($g['share'] * 100, 1),
                'avg' => $g['avg_per_article'] === null ? null : round($g['avg_per_article']),
                'over' => self::overBadge($g['avg_per_article'], $limit),
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function topRows(array $articles, ?int $limit): array
    {
        $rows = [];
        foreach ($articles as $a) {
            $rows[] = [
                'id' => $a['article_id'],
                'title' => ['text' => $a['title'], 'href' => ArticleResource::getUrl('view', ['record' => $a['article_id']])],
                'website' => $a['website'],
                'models' => implode(', ', $a['models']) ?: 'không rõ model',
                'cost' => round($a['cost']),
                'versions' => $a['versions'],
                'tokens' => $a['tokens'],
                'over' => self::overBadge($a['cost'], $limit),
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function dayRows(array $days): array
    {
        return array_map(fn ($d) => ['id' => $d['date']->format('Y-m-d'), 'date' => $d['date']->format('d/m/Y'), 'versions' => $d['versions'], 'tokens' => $d['tokens'], 'cost' => $d['versions'] > 0 ? round($d['cost']) : null], $days);
    }

    public function describeChart(array $days, float $peak, ?CarbonImmutable $peakDay, ?float $pace): string
    {
        $n = count(array_filter($days, fn ($d) => $d['versions'] > 0));
        $s = 'Cột chi phí AI theo ngày, đơn vị đồng. '.$n.' trên '.count($days).' ngày có phiên bản được sinh.';
        if ($peakDay) {
            $s .= ' Cao nhất '.Vnd::format($peak).' ngày '.$peakDay->format('d/m').'.';
        }
        if ($pace !== null) {
            $s .= ' Đường nét đứt là nhịp đều của ngưỡng tháng: '.Vnd::format($pace).' mỗi ngày.';
        }

        return $s;
    }
}
