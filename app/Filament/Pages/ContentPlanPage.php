<?php

namespace App\Filament\Pages;

use App\Filament\Support\ContentPlan\MockTitleSuggester;
use App\Filament\Support\ContentPlan\PlanActions;
use App\Filament\Support\ContentPlan\PlanData;
use App\Filament\Support\ContentPlan\PlanRules;
use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Keywords\KeywordService;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Filament\Support\Schedule\VnTime;
use App\Models\Article;
use App\Models\Website;
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

    public string $tab = 'posts';

    public int $weekOffset = 0;

    /** @var list<array{keyword:string,title:string,intent:string}> */
    public array $suggestions = [];

    /** @var list<string> */
    public array $suggestPick = [];

    /** @var list<array<string,mixed>> kết quả lần tạo gần nhất, từng từ khóa */
    public array $lastResults = [];

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof \App\Models\User && $u->is_active && $u->isWriter();
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

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['posts', 'kw', 'warn'], true) ? $tab : 'posts';
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

        return KeywordService::make()->repo()->clusters();
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
            ->modalDescription('Chọn các từ khóa chưa có bài. Bài được tạo ở trạng thái "Đã lên kế hoạch", chưa viết nội dung, chưa tốn token. Mỗi từ khóa chính chỉ một bài trên mỗi website.')
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
        foreach (KeywordService::make()->repo()->all() as $r) {
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
        foreach (KeywordService::make()->repo()->all() as $r) {
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

        return [
            'demo' => $demo, 'demoOn' => DemoMode::enabled(), 'sites' => $sites, 'site' => $site, 'rows' => $rows, 'detail' => $detail,
            'allConflicts' => $allConflicts, 'byId' => $byId, 'days' => $days, 'weekCount' => $weekCount, 'undated' => $undated,
            'rangeLabel' => $start->format('d/m').' – '.$start->addDays(6)->format('d/m/Y'),
            'limit' => PlanRules::DAILY_LIMIT, 'total' => count($articles),
            'norm' => fn (string $k) => PlannedArticleCreator::normalize($k),
        ];
    }
}
