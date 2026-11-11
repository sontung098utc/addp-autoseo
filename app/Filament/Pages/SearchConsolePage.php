<?php

namespace App\Filament\Pages;

use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Demo\DemoStore;
use App\Filament\Support\Gsc\GscService;
use App\Filament\Support\Gsc\OpportunityFinder;
use App\Filament\Support\Gsc\PageArticleLinker;
use App\Filament\Support\Gsc\UpdateProposals;
use App\Models\User;
use App\Models\Website;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;

class SearchConsolePage extends Page
{
    public const STATES = ['chua-ket-noi', 'het-han', 'dang-dong-bo', 'khong-quyen', 'chua-co-du-lieu', 'ket-noi'];

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Search Console';

    protected static ?string $navigationGroup = 'Khám phá';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Search Console';

    protected static ?string $slug = 'search-console';

    protected static string $view = 'filament.pages.search-console';

    #[Url(as: 'demo')]
    public ?string $demo = null;

    public ?int $websiteId = null;

    public int $days = 28;

    public ?string $selDim = null;

    public ?string $selKey = null;

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    /** Một (i) duy nhất của trang, đặt cạnh tiêu đề (cùng hàng). */
    public function getHeading(): string|Htmlable
    {
        return new HtmlString('Search Console '.Blade::render(<<<'BLADE'
<x-chain-context step="Khám phá" purpose="Đo hiệu quả bài đã đăng và phát hiện bài tụt hạng để làm mới." :inputs="[['label'=>'Bài đã đăng','url'=>\App\Filament\Resources\PublicationResource::listUrl(),'note'=>'Lịch xuất bản']]" :outputs="[['label'=>'Đề xuất làm mới','url'=>\App\Filament\Pages\AuditPage::getUrl(),'note'=>'bài tụt hạng'],['label'=>'Từ khóa mới','url'=>\App\Filament\Pages\KeywordsPage::getUrl()]]" :compact="true" />
BLADE));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->websiteId ??= Website::where('status', 'active')->orderBy('name')->value('id');
    }

    /** Trạng thái màn: production luôn "chưa kết nối" thật; ngoài production theo ?demo= hoặc cờ gsc.connected. */
    public function state(): string
    {
        $default = DemoMode::enabled() && DemoStore::get('gsc.connected', true) ? 'ket-noi' : 'chua-ket-noi';

        return DemoMode::state($this->demo, self::STATES, $default);
    }

    public function setDays(int $days): void
    {
        if (in_array($days, [7, 14, 28], true)) {
            $this->days = $days;
        }
    }

    public function selectItem(string $dim, string $key): void
    {
        if (array_key_exists($dim, GscService::DIMENSIONS)) {
            $this->selDim = $dim;
            $this->selKey = $key;
        }
    }

    /** Chọn dòng từ bảng: id có dạng "chiều|khóa". */
    public function selectRowId(string $id): void
    {
        [$dim, $key] = array_pad(explode('|', $id, 2), 2, '');
        if ($key !== '') {
            $this->selectItem($dim, $key);
        }
    }

    /** Demo: chỉ lật cờ. Thật sẽ là OAuth chỉ-đọc, token làm mới mã hóa, thu hồi được. */
    public function connect(): void
    {
        abort_unless(static::canAccess(), 403);
        DemoStore::put('gsc.connected', true);
        $this->demo = null;
        Notification::make()->title('Đã kết nối (minh họa)')->body('Chỉ lật cờ minh họa; chưa có OAuth Google thật.')->success()->send();
    }

    public function disconnect(): void
    {
        abort_unless(static::canAccess(), 403);
        DemoStore::put('gsc.connected', false);
        $this->demo = null;
    }

    public function createProposal(): void
    {
        $report = $this->report();
        $item = collect($report['items'][$this->selDim] ?? [])->firstWhere('key', $this->selKey);
        if (! $item) {
            Notification::make()->title('Chưa chọn mục nào')->danger()->send();

            return;
        }
        $opps = array_values(array_filter($report['opportunities'], fn ($o) => $o['key'] === $this->selKey));
        $p = $report['periods']['current'];
        try {
            UpdateProposals::create(Auth::user(), $item, $this->selDim, $opps, $p['from']->format('d/m/Y').' – '.$p['to']->format('d/m/Y'));
        } catch (AuthorizationException $e) {
            Notification::make()->title('Không có quyền')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title('Đã tạo đề xuất cập nhật')->body('Đề xuất đang chờ duyệt; bài chưa bị sửa.')->success()->send();
    }

    public function report(): array
    {
        return GscService::make()->report($this->days);
    }

    /** @param array<string, mixed> $d @return float|null chênh lệch để hiển thị (null nếu chưa có / mới) */
    private function dv(array $d, bool $pct): ?float
    {
        if ($d['state'] !== 'ok') {
            return null;
        }

        return $pct ? ($d['pct'] === null ? null : round($d['pct'], 1)) : round((float) $d['abs'], 3);
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function dimRows(string $dim, array $items): array
    {
        return array_map(fn ($r) => [
            'id' => $dim.'|'.$r['key'],
            'key' => $r['key'],
            'clicks' => $r['cur']['clicks'],
            'impressions' => $r['cur']['impressions'],
            'ctr' => $r['cur']['ctr'] === null ? null : round($r['cur']['ctr'] * 100, 2),
            'position' => $r['cur']['position'] === null ? null : round($r['cur']['position'], 1),
            'd_clicks' => $this->dv($r['d']['clicks'], true),
            'd_impressions' => $this->dv($r['d']['impressions'], true),
            'd_ctr' => $this->dv($r['d']['ctr'], false) === null ? null : round($this->dv($r['d']['ctr'], false) * 100, 2),
            'd_position' => $this->dv($r['d']['position'], false) === null ? null : round($this->dv($r['d']['position'], false), 1),
        ], $items);
    }

    /** @param array<string, mixed> $report @return list<array<string, mixed>> */
    private function oppRows(array $report): array
    {
        $pages = array_column($report['items']['page'], 'key');
        $tones = [OpportunityFinder::RULE_DROP => 'danger', OpportunityFinder::RULE_LOW_CTR => 'warn', OpportunityFinder::RULE_PAGE1 => 'info'];

        return array_map(fn ($o, $i) => [
            'id' => (in_array($o['key'], $pages, true) ? 'page' : 'query').'|'.$o['key'],
            'type' => ['text' => $o['label'], 'tone' => $tones[$o['rule']] ?? 'muted'],
            'key' => $o['key'],
            'reason' => $o['reason'],
            '_tone' => $tones[$o['rule']] ?? null,
        ], $report['opportunities'], array_keys($report['opportunities']));
    }

    /** @return list<array<string, mixed>> */
    private function proposalRows(): array
    {
        return array_map(fn ($p, $i) => [
            'id' => $p['id'] ?? $i,
            'status' => ['text' => $p['status'], 'tone' => 'warn'],
            'key' => $p['key'],
            'page' => $p['page'] ?? '—',
            'by' => $p['created_by_name'],
            'at' => Carbon::parse($p['created_at'])->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i'),
            'period' => $p['metrics']['period'],
            'reasons' => implode(' · ', $p['reasons']),
        ], UpdateProposals::all(), array_keys(UpdateProposals::all()));
    }

    protected function getViewData(): array
    {
        $state = $this->state();
        $report = in_array($state, ['ket-noi', 'het-han'], true) ? $this->report() : null;
        $dimRows = [];
        if ($report) {
            foreach (GscService::DIMENSIONS as $dim => $label) {
                $dimRows[$dim] = $this->dimRows($dim, $report['items'][$dim]);
            }
        }
        $selected = $report && $this->selKey ? collect($report['items'][$this->selDim] ?? [])->firstWhere('key', $this->selKey) : null;

        return [
            'state' => $state,
            'report' => $report,
            'dimRows' => $dimRows,
            'oppRows' => $report ? $this->oppRows($report) : [],
            'proposalRows' => $this->proposalRows(),
            'selected' => $selected,
            'selDim' => $this->selDim,
            'selectedId' => $selected ? $this->selDim.'|'.$this->selKey : null,
            'selectedOpps' => $selected ? array_values(array_filter($report['opportunities'], fn ($o) => $o['key'] === $this->selKey)) : [],
            'websites' => Website::where('status', 'active')->orderBy('name')->get(['id', 'name', 'base_url']),
            'links' => $report ? PageArticleLinker::map() : [],
        ];
    }
}
