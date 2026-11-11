<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Filament\Pages\AuditPage;
use App\Filament\Pages\ContentPlanPage;
use App\Filament\Pages\KeywordsPage;
use App\Filament\Pages\KnowledgePage;
use App\Filament\Pages\SearchConsolePage;
use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleResource;
use App\Filament\Support\Agent\RunActivity;
use App\Models\User;
use App\Services\Agent\Pipeline\JobTypeRegistry;
use App\Services\Agent\Pipeline\PipelineVisual;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;

/** Tab "Pipeline" của Đội AI: hai tab ngang, "Các luồng" (lưới thẻ lớn, bấm mở popup luồng đầy đủ) và "Đang chạy". */
class PipelineMap extends Page
{
    protected static ?string $cluster = AgentTeam::class;

    protected static ?string $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Pipeline';

    protected static ?string $title = 'Pipeline tự động';

    protected static ?string $slug = 'pipeline';

    protected static ?int $navigationSort = 9;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string $view = 'filament.clusters.agent-team.pipeline';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    /** Cửa sổ trượt của một loại việc (thẻ dưới mắt xích). */
    public function jobAction(): Action
    {
        return Action::make('job')
            ->modalHeading(fn (array $arguments): string => ($j = JobTypeRegistry::find($arguments['job'] ?? '')) ? 'Loại việc · '.$j['title'] : 'Loại việc')
            ->slideOver()
            ->modalContent(fn (array $arguments) => view('filament.clusters.agent-team.pipeline-job', [
                'j' => JobTypeRegistry::find($arguments['job'] ?? ''),
                'names' => $this->jobNames(),
                'url' => ($j = JobTypeRegistry::find($arguments['job'] ?? '')) ? self::workUrl($j['id']) : null,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng');
    }

    /** Cửa sổ trượt của một bước trong sơ đồ: mô tả, ai làm, điều kiện, số liệu 30 ngày. */
    public function stepAction(): Action
    {
        return Action::make('step')
            ->modalHeading(fn (array $arguments): string => 'Bước · '.($this->flowStep($arguments)['label'] ?? ''))
            ->slideOver()
            ->modalContent(function (array $arguments) {
                $s = $this->flowStep($arguments);

                return view('filament.clusters.agent-team.pipeline-step', ['s' => $s, 'stats' => $s ? PipelineVisual::stepStats((string) $arguments['job'], (string) $s['id']) : null]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng');
    }

    /** @return array<string, mixed>|null */
    private function flowStep(array $arguments): ?array
    {
        foreach (PipelineVisual::flows() as $f) {
            if ($f['id'] === ($arguments['job'] ?? '')) {
                foreach ($f['steps'] as $s) {
                    if ($s['id'] === ($arguments['step'] ?? '')) {
                        return $s;
                    }
                }
            }
        }

        return null;
    }

    /** @return array<string, string> mã loại việc => tên; 'human' => 'Người' */
    private function jobNames(): array
    {
        return ['human' => 'Người'] + array_column(JobTypeRegistry::all(), 'title', 'id');
    }

    /** Câu mô tả đời thường cho từng loại việc; dùng khi câu đầu của `summary` có mã kỹ thuật hoặc quá dài. */
    public const PLAIN = [
        'market_research' => 'Gợi ý từ khóa mới quanh hạt giống và hồ sơ doanh nghiệp, rồi gom thành cụm.',
        'site_audit' => 'Quét website, tìm lỗi SEO và gợi ý bài nào nên làm mới.',
        'content_plan' => 'Đề xuất bài nên viết trước, rồi bạn duyệt để đưa vào hàng chờ viết.',
        'article_write' => 'Viết bản nháp một bài từ từ khóa: dàn ý, tài liệu, viết, chấm và sửa.',
        'article_refresh' => 'Sửa lại bài cũ ở những chỗ còn yếu, có so sánh trước và sau để bạn duyệt.',
        'brain_build' => 'Dựng kho kiến thức từ website và tài liệu để các bài viết dùng lại.',
        'measure' => 'Theo dõi bài lên hay tụt hạng trên Google và gợi ý bài cần làm mới.',
    ];

    /** Trang làm việc thật của từng loại việc: mã => danh sách class (thử lần lượt, lấy class đầu tiên có thật). */
    public const PAGES = [
        'market_research' => [KeywordsPage::class],
        'site_audit' => [AuditPage::class],
        'content_plan' => [ContentPlanPage::class],
        'article_write' => [ArticleResource::class],
        'article_refresh' => [ArticleResource::class],
        'brain_build' => ['App\\Filament\\Pages\\BrainBuildPage', KnowledgePage::class],
        'measure' => [SearchConsolePage::class],
    ];

    /** Câu mô tả ngắn: câu đầu của summary (đến dấu chấm đầu tiên, <=140 ký tự) nếu không có mã kỹ thuật, nếu không thì câu viết sẵn. */
    public static function plainLine(array $j): string
    {
        $first = trim((string) preg_split('/\.(\s|$)/u', (string) $j['summary'], 2)[0]);
        $technical = $first === '' || mb_strlen($first) > 140 || preg_match('/[a-z]+_[a-z_]+|::|=|`|\bsource\b|\bjob\b/u', $first);

        return $technical ? (self::PLAIN[$j['id']] ?? $first).(self::PLAIN[$j['id']] ?? false ? '' : '.') : $first.'.';
    }

    /** Địa chỉ trang làm việc của loại việc; null nếu không có class, không có quyền hoặc lỗi route. */
    public static function workUrl(string $jobId): ?string
    {
        foreach (self::PAGES[$jobId] ?? [] as $class) {
            if (! class_exists($class)) {
                continue;
            }
            try {
                $ok = method_exists($class, 'canViewAny') ? $class::canViewAny() : (! method_exists($class, 'canAccess') || $class::canAccess());

                return $ok ? $class::getUrl() : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** Dòng trợ giúp (i) của một luồng: điểm người can thiệp, ngân sách, nơi hiện kết quả. @return list<string> */
    private function helpLines(array $j): array
    {
        $lines = [];
        if ($j['human']) {
            $lines[] = 'Việc bạn cần làm: '.implode('; ', $j['human']).'.';
        }
        if ($j['budget']) {
            $lines[] = 'Ngân sách: '.$j['budget'].'.';
        }
        if ($j['shown_at']) {
            $lines[] = 'Kết quả hiện ở: '.collect($j['shown_at'])->map(fn ($x) => trim(preg_replace('/\s*\(.*?\)/u', '', $x)))->implode('; ').'.';
        }

        return $lines;
    }

    protected function getViewData(): array
    {
        $flows = array_map(function (array $f) {
            $j = JobTypeRegistry::find($f['id']) ?? [];

            return $f + [
                'plain' => $j ? self::plainLine($j) : '', 'url' => self::workUrl($f['id']), 'help' => $j ? $this->helpLines($j) : [],
                'runs_url' => PipelineVisual::urlOf(AgentRunResource::class, ['tableFilters' => ['job_type' => ['value' => $f['id']]]]),
            ];
        }, PipelineVisual::flows());
        $recent = [];
        $stepStats = [];
        foreach ($flows as $f) {
            $recent[$f['id']] = PipelineVisual::recentRuns($f['id']);
            foreach ($f['steps'] as $s) {
                $stepStats[$f['id']][$s['id']] = PipelineVisual::stepStats($f['id'], (string) $s['id']);
            }
        }

        $runs = PipelineVisual::runs();
        $live = RunActivity::anyRunning();
        $liveSteps = [];
        foreach ($runs as $r) {
            if ($r['live'] && $r['current']) {
                $liveSteps[$r['job_type']][] = array_search($r['current'], JobTypeRegistry::stageLabels($r['job_type'], true), true);
            }
        }

        return [
            'flows' => $flows,
            'cards' => PipelineVisual::cardStats(),
            'recent' => $recent,
            'stepStats' => $stepStats,
            'isAdmin' => (bool) Auth::user()?->isAdmin(),
            'rolesUrl' => PipelineVisual::urlOf(AgentRoles::class),
            'skillsUrl' => PipelineVisual::urlOf(AgentSkills::class),
            'runs' => $runs,
            'live' => $live,
            'pollSeconds' => RunActivity::POLL_LIVE,
            'liveSteps' => $liveSteps,
            'names' => $this->jobNames(),
        ];
    }
}
