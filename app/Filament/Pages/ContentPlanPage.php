<?php

namespace App\Filament\Pages;

use App\Filament\Support\ContentPlan\MockTitleSuggester;
use App\Filament\Support\ContentPlan\PlanActions;
use App\Filament\Support\ContentPlan\PlanData;
use App\Filament\Support\ContentPlan\PlanRules;
use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Keywords\KeywordIntent;
use App\Filament\Support\Keywords\KeywordService;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Filament\Support\Schedule\VnTime;
use App\Filament\Support\StatusPresenter;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Planning\ContentPlanner;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class ContentPlanPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Kế hoạch nội dung';

    protected static ?string $navigationGroup = 'Khám phá';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Kế hoạch nội dung';

    protected static ?string $slug = 'ke-hoach-noi-dung';

    protected static string $view = 'filament.pages.content-plan';

    public ?int $websiteId = null;

    public ?string $clusterName = null;

    public int $weekOffset = 0;

    /** @var list<array{keyword:string,title:string,intent:string}> */
    public array $suggestions = [];

    /** @var list<string> */
    public array $suggestPick = [];

    /** @var list<array<string,mixed>> kết quả lần tạo gần nhất, từng từ khóa */
    public array $lastResults = [];

    /** @var list<int> keyword_id đã chọn trong hộp "Đề xuất kế hoạch (theo luật)" */
    public array $planPick = [];

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isWriter();
    }

    private function guard(): void
    {
        if (! static::canAccess()) {
            abort(403);
        }
    }

    public function selectCluster(string $name): void
    {
        $this->clusterName = $name;
        $this->suggestions = [];
        $this->suggestPick = [];
        $this->lastResults = [];
    }

    public function previousWeek(): void
    {
        $this->weekOffset--;
    }

    public function nextWeek(): void
    {
        $this->weekOffset++;
    }

    public function currentWeek(): void
    {
        $this->weekOffset = 0;
    }

    /** Đổi ngày từ bảng "Chưa có ngày": mở hộp thoại đổi ngày của đúng bài. */
    public function rescheduleById(int $id): void
    {
        $this->guard();
        $this->mountAction('reschedule', ['article' => $id]);
    }

    private const TONES = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'danger', 'info' => 'info', 'gray' => 'muted'];

    /** @return array{text:string,tone:string} */
    public static function statusBadge(string $status): array
    {
        return ['text' => StatusPresenter::label($status), 'tone' => self::TONES[StatusPresenter::color($status)] ?? 'muted'];
    }

    private function site(): ?Website
    {
        $sites = Website::where('status', 'active')->orderBy('name')->get();

        return $sites->firstWhere('id', $this->websiteId) ?? $sites->first();
    }

    /** @return array<string,mixed>|null */
    private function currentCluster(): ?array
    {
        foreach ($this->clusters() as $c) {
            if ($c['name'] === $this->clusterName) {
                return $c;
            }
        }

        return $this->clusters()[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    private function clusters(): array
    {
        if (DemoMode::state(request()->query('demo'), ['chua-co-cum'], '') === 'chua-co-cum') {
            return [];
        }

        return KeywordService::make($this->site())->repo()->clusters();
    }

    /** @return array<string,string> từ khóa chưa có bài của cụm hiện tại, dạng giá trị => nhãn */
    private function freeOptions(): array
    {
        $site = $this->site();
        $c = $this->currentCluster();
        if (! $site || ! $c) {
            return [];
        }
        $cov = PlanRules::coverage($c['keywords'], array_column(PlanData::articles($site), 'keyword'));

        return array_combine($cov['free'], $cov['free']) ?: [];
    }

    public function createFromClusterAction(): Action
    {
        return Action::make('createFromCluster')
            ->label('Tạo bài từ cụm')
            ->color('primary')
            ->modalHeading('Tạo bài từ cụm')
            ->disabled(fn (): bool => $this->freeOptions() === [])
            ->tooltip(fn (): ?string => $this->freeOptions() === [] ? 'Cụm đã có bài cho mọi từ khóa (hoặc chưa chọn cụm).' : null)
            ->form([
                CheckboxList::make('keywords')->label('Từ khóa chưa có bài')->options(fn (): array => $this->freeOptions())->required()->columns(1),
                DatePicker::make('day')->label('Ngày dự kiến (giờ Việt Nam, tùy chọn)')->native(false)->minDate(fn () => VnTime::now()->startOfDay())
                    ->helperText('Tối đa '.PlanRules::DAILY_LIMIT.' bài/ngày (giới hạn tạm cho tới khi có bảng chiến dịch). Ngày dự kiến lưu tạm, sẽ có cột khi Dev1 thêm.'),
            ])
            ->action(function (array $data): void {
                $this->guard();
                $site = $this->site();
                $c = $this->currentCluster();
                if (! $site || ! $c) {
                    return;
                }
                $this->runCreate($site, $c, (array) ($data['keywords'] ?? []), $data['day'] ?? null);
            });
    }

    public function rescheduleAction(): Action
    {
        return Action::make('reschedule')
            ->label('Đổi ngày')
            ->modalHeading('Đổi ngày dự kiến')
            ->modalDescription('Chỉ bài "Đã lên kế hoạch", sang ngày tương lai chưa đủ '.PlanRules::DAILY_LIMIT.' bài. Giờ đăng lấy ô trống đầu tiên trong ngày (giờ VN).')
            ->form([DatePicker::make('day')->label('Ngày mới')->native(false)->required()->minDate(fn () => VnTime::now()->startOfDay())])
            ->action(function (array $arguments, array $data): void {
                $this->guard();
                $article = Article::find($arguments['article'] ?? 0);
                if (! $article || ! $this->site() || $article->website_id !== $this->site()->id) {
                    Notification::make()->title('Không đổi được ngày')->body('Không tìm thấy bài, tải lại trang.')->danger()->send();

                    return;
                }
                try {
                    $dt = (new PlanActions)->reschedule(Auth::user(), $article, (string) $data['day']);
                    Notification::make()->title('Đã đổi ngày dự kiến')->body($dt.' (giờ VN)')->success()->send();
                } catch (InvalidArgumentException $e) {
                    Notification::make()->title('Không đổi được ngày')->body($e->getMessage())->danger()->send();
                }
            });
    }

    /** @param  array<string,mixed>  $c  @param  list<string>  $keywords */
    private function runCreate(Website $site, array $c, array $keywords, ?string $day): void
    {
        $intents = [];
        foreach (KeywordService::make($this->site())->repo()->all() as $r) {
            $intents[$r['keyword']] = $r['intent'];
        }
        try {
            $this->lastResults = (new PlanActions)->createFromCluster(Auth::user(), $site, $c, $keywords, $day, $intents);
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Chưa tạo bài')->body($e->getMessage())->danger()->send();

            return;
        }
        $ok = count(array_filter($this->lastResults, fn ($r) => $r['status'] === 'created'));
        $n = Notification::make()->title("Đã tạo $ok / ".count($this->lastResults).' bài')->body('Xem kết quả từng từ khóa ngay dưới tiêu đề trang.');
        $ok === count($this->lastResults) ? $n->success() : $n->warning();
        $n->send();
        $this->suggestions = [];
        $this->suggestPick = [];
    }

    public function suggest(): void
    {
        $this->guard();
        $c = $this->currentCluster();
        if (! $c) {
            return;
        }
        $free = $this->freeOptions();
        $intents = [];
        foreach (KeywordService::make($this->site())->repo()->all() as $r) {
            $intents[$r['keyword']] = $r['intent'];
        }
        $this->suggestions = (new MockTitleSuggester)->suggest(array_map(fn ($k) => ['keyword' => $k, 'intent' => $intents[$k] ?? 'informational'], array_keys($free)), 5);
        $this->suggestPick = array_column($this->suggestions, 'keyword');
    }

    /** Chỉ khi người bấm mới tạo bài thật từ các gợi ý đã chọn. */
    public function createSuggested(): void
    {
        $this->guard();
        $site = $this->site();
        $c = $this->currentCluster();
        if ($site && $c) {
            $this->runCreate($site, $c, $this->suggestPick, null);
        }
    }

    /** Thêm các từ khóa đã chọn vào kế hoạch (bài planned, chờ người duyệt; không giao đội AI). */
    public function addProposed(): void
    {
        $this->guard();
        $site = $this->site();
        if (! $site || $this->planPick === []) {
            return;
        }
        try {
            $r = (new ContentPlanner)->create(Auth::user(), $site, array_map('intval', $this->planPick));
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Chưa thêm vào kế hoạch')->body($e->getMessage())->danger()->send();

            return;
        }
        $n = Notification::make()->title("Đã thêm {$r['created']} bài vào kế hoạch")
            ->body($r['skipped'] ? 'Bỏ qua: '.implode('; ', $r['skipped']) : 'Bài ở trạng thái Đã lên kế hoạch, chờ bạn duyệt.');
        $r['skipped'] ? $n->warning() : $n->success();
        $n->send();
        $this->planPick = [];
    }

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        $demo = DemoMode::state(request()->query('demo'), ['chua-co-cum', 'cum-du', 'tuan-trong'], '');
        $sites = Website::where('status', 'active')->orderBy('name')->get();
        $site = $this->site();
        $articles = $site ? PlanData::articles($site) : [];
        $clusters = $this->clusters();
        $artKeywords = array_column($articles, 'keyword');
        $cur = $this->currentCluster();

        $rows = [];
        foreach ($clusters as $c) {
            $cov = PlanRules::coverage($c['keywords'], $artKeywords);
            if ($demo === 'cum-du') {
                $cov = ['covered' => $c['keywords'], 'free' => [], 'percent' => 100];
            }
            $mine = PlanData::inCluster($articles, $c);
            $conf = PlanRules::cannibalized(array_map(fn ($a) => ['id' => $a['id'], 'keyword' => $a['keyword']], $mine));
            $rows[] = ['c' => $c, 'cov' => $cov, 'posts' => count($mine), 'conflicts' => count($conf)];
        }

        $detail = null;
        if ($cur) {
            $cov = PlanRules::coverage($cur['keywords'], $artKeywords);
            if ($demo === 'cum-du') {
                $cov = ['covered' => $cur['keywords'], 'free' => [], 'percent' => 100];
            }
            $mine = PlanData::inCluster($articles, $cur);
            $detail = ['c' => $cur, 'cov' => $cov, 'posts' => $mine,
                'conflicts' => PlanRules::cannibalized(array_map(fn ($a) => ['id' => $a['id'], 'keyword' => $a['keyword']], $mine))];
        }
        $byId = array_column($articles, null, 'id');
        $allConflicts = PlanRules::cannibalized(array_map(fn ($a) => ['id' => $a['id'], 'keyword' => $a['keyword']], $articles));

        // lịch tuần
        $start = VnTime::weekStart()->addWeeks($this->weekOffset);
        $dayTimes = PlanData::dayTimes($articles);
        $dayNames = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
        $today = VnTime::now()->format('Y-m-d');
        $days = [];
        $weekCount = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = $start->addDays($i);
            $key = $d->format('Y-m-d');
            $items = array_values(array_filter($articles, fn ($a) => $a['at'] && $a['at']->format('Y-m-d') === $key && $demo !== 'tuan-trong'));
            usort($items, fn ($a, $b) => $a['at'] <=> $b['at']);
            $count = count($dayTimes[$key] ?? []);
            $weekCount += count($items);
            $days[] = ['key' => $key, 'name' => $dayNames[$i], 'label' => $d->format('d/m'), 'isToday' => $key === $today, 'isPast' => $key < $today,
                'items' => $items, 'count' => $count, 'full' => $count >= PlanRules::DAILY_LIMIT];
        }
        $undated = array_values(array_filter($articles, fn ($a) => $a['at'] === null && in_array($a['status'], ['planned', 'drafting', 'needs_review', 'rejected', 'approved'], true)));

        $intentLabel = KeywordIntent::LABELS;
        $clusterRows = array_map(function ($r) use ($intentLabel) {
            $pct = $r['cov']['percent'];

            return ['id' => $r['c']['name'], 'name' => $r['c']['name'], 'keywords' => count($r['c']['keywords']), 'posts' => $r['posts'],
                'percent' => $pct, 'level' => $pct >= 100 ? ['text' => 'Đủ', 'tone' => 'ok'] : ($pct < 34 ? ['text' => 'Thấp', 'tone' => 'danger'] : ['text' => 'Trung bình', 'tone' => 'warn']),
                'conflicts' => $r['conflicts'], 'intent' => $intentLabel[$r['c']['intent']] ?? $r['c']['intent'],
                '_tone' => $r['conflicts'] ? 'danger' : null];
        }, $rows);
        $undatedRows = array_map(fn ($a) => ['id' => $a['id'], 'title' => $a['title'], 'keyword' => $a['keyword'], 'status' => self::statusBadge($a['status'])], $undated);

        return [
            'clusterColumns' => $this->clusterColumns(), 'clusterRows' => $clusterRows,
            'undatedColumns' => $this->undatedColumns(), 'undatedRows' => $undatedRows,
            'proposals' => $site ? (new ContentPlanner)->propose($site, 10) : [],
            'demo' => $demo, 'demoOn' => DemoMode::enabled(), 'sites' => $sites, 'site' => $site, 'rows' => $rows, 'detail' => $detail,
            'allConflicts' => $allConflicts, 'byId' => $byId, 'days' => $days, 'weekCount' => $weekCount, 'undated' => $undated,
            'rangeLabel' => $start->format('d/m').' – '.$start->addDays(6)->format('d/m/Y'),
            'limit' => PlanRules::DAILY_LIMIT, 'total' => count($articles),
            'norm' => fn (string $k) => PlannedArticleCreator::normalize($k),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function clusterColumns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Cụm', 'type' => 'text', 'sortable' => true],
            ['key' => 'keywords', 'label' => 'Từ khóa', 'type' => 'number', 'sortable' => true],
            ['key' => 'posts', 'label' => 'Bài đã có', 'type' => 'number', 'sortable' => true],
            ['key' => 'percent', 'label' => 'Độ phủ', 'type' => 'number', 'suffix' => '%', 'sortable' => true],
            ['key' => 'level', 'label' => 'Mức phủ', 'type' => 'badge', 'filter' => true, 'sortable' => true, 'tones' => ['Đủ' => 'ok', 'Trung bình' => 'warn', 'Thấp' => 'danger']],
            ['key' => 'conflicts', 'label' => 'Trùng từ khóa', 'type' => 'number', 'sortable' => true],
            ['key' => 'intent', 'label' => 'Ý định', 'type' => 'badge', 'filter' => true, 'visible' => false, 'tones' => ['Thông tin' => 'info', 'Thương mại' => 'accent', 'Giao dịch' => 'warn', 'Điều hướng' => 'muted']],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function undatedColumns(): array
    {
        return [
            ['key' => 'title', 'label' => 'Bài', 'type' => 'text', 'sortable' => true],
            ['key' => 'keyword', 'label' => 'Từ khóa chính', 'type' => 'text', 'sortable' => true],
            ['key' => 'status', 'label' => 'Trạng thái', 'type' => 'badge', 'filter' => true, 'sortable' => true],
            ['key' => 'act', 'label' => '', 'type' => 'actions', 'actions' => [['label' => 'Đổi ngày', 'method' => 'rescheduleById']]],
        ];
    }
}
