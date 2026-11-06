<?php

namespace App\Filament\Pages;

use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Demo\DemoStore;
use App\Filament\Support\Gsc\GscService;
use App\Filament\Support\Gsc\PageArticleLinker;
use App\Filament\Support\Gsc\UpdateProposals;
use App\Models\User;
use App\Models\Website;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

class SearchConsolePage extends Page
{
    public const STATES = ['chua-ket-noi', 'het-han', 'dang-dong-bo', 'khong-quyen', 'chua-co-du-lieu', 'ket-noi'];

    protected static ?string $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Search Console';

    protected static ?string $navigationGroup = 'Phân tích';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Search Console';

    protected static ?string $slug = 'search-console';

    protected static string $view = 'filament.pages.search-console';

    #[Url(as: 'demo')]
    public ?string $demo = null;

    public ?int $websiteId = null;

    public int $days = 28;

    public string $tab = 'query';

    public string $sort = 'clicks';

    public string $dir = 'desc';

    public string $search = '';

    public ?string $selDim = null;

    public ?string $selKey = null;

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
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

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, GscService::DIMENSIONS)) {
            $this->tab = $tab;
            $this->search = '';
        }
    }

    public function setDays(int $days): void
    {
        if (in_array($days, [7, 14, 28], true)) {
            $this->days = $days;
        }
    }

    public function sortBy(string $col): void
    {
        if (! in_array($col, ['key', 'clicks', 'impressions', 'ctr', 'position'], true)) {
            return;
        }
        $this->dir = ($this->sort === $col && $this->dir === 'desc') ? 'asc' : 'desc';
        $this->sort = $col;
    }

    public function selectItem(string $dim, string $key): void
    {
        if (array_key_exists($dim, GscService::DIMENSIONS)) {
            $this->selDim = $dim;
            $this->selKey = $key;
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

    protected function getViewData(): array
    {
        $state = $this->state();
        $report = in_array($state, ['ket-noi', 'het-han'], true) ? $this->report() : null;
        $rows = [];
        if ($report) {
            $rows = $report['items'][$this->tab];
            if ($this->search !== '') {
                $rows = array_values(array_filter($rows, fn ($r) => mb_stripos($r['key'], $this->search) !== false));
            }
            $col = $this->sort;
            usort($rows, function ($a, $b) use ($col) {
                $x = $col === 'key' ? $a['key'] : ($a['cur'][$col] ?? 0);
                $y = $col === 'key' ? $b['key'] : ($b['cur'][$col] ?? 0);

                return $this->dir === 'asc' ? $x <=> $y : $y <=> $x;
            });
        }
        $selected = $report && $this->selKey ? collect($report['items'][$this->selDim] ?? [])->firstWhere('key', $this->selKey) : null;

        return [
            'state' => $state,
            'report' => $report,
            'rows' => array_slice($rows, 0, 50),
            'selected' => $selected,
            'selectedOpps' => $selected ? array_values(array_filter($report['opportunities'], fn ($o) => $o['key'] === $this->selKey)) : [],
            'websites' => Website::where('status', 'active')->orderBy('name')->get(['id', 'name', 'base_url']),
            'links' => $report ? PageArticleLinker::map() : [],
            'proposals' => UpdateProposals::all(),
        ];
    }
}
