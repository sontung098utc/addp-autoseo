<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Models\AgentRunStep;
use App\Models\User;
use App\Services\Agent\Roles\RoleRegistry;
use App\Services\Ai\Playbook\InvalidPlaybook;
use App\Services\Ai\Playbook\Playbook;
use App\Services\Ai\Playbook\PlaybookLoader;
use App\Services\Content\Quality\QualityRubric;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Tab "Kỹ năng" của Đội AI (AG-S13): kỹ năng nghiệp vụ = playbook học từ claude-seo, đọc THẬT từ resources/playbooks.
 * CHỈ XEM: sửa kỹ năng = sửa file + PR. Playbook hỏng chỉ hiện một dòng lỗi, không làm vỡ trang.
 */
class AgentSkills extends Page
{
    protected static ?string $cluster = AgentTeam::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Kỹ năng';

    protected static ?string $title = 'Kỹ năng của đội AI';

    protected static ?string $slug = 'ky-nang';

    protected static ?int $navigationSort = 6;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string $view = 'filament.clusters.agent-team.skills';

    public string $roleFilter = '';

    public string $statusFilter = '';

    public string $search = '';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    public function detailAction(): Action
    {
        return Action::make('detail')
            ->modalHeading(fn (array $arguments) => $this->find((string) ($arguments['ref'] ?? ''))['title'] ?? 'Kỹ năng')
            ->slideOver()->modalWidth('4xl')
            ->modalContent(function (array $arguments) {
                $skill = $this->find((string) ($arguments['ref'] ?? ''));
                abort_if($skill === null, 404);
                $pb = $skill['playbook'];

                return view('filament.clusters.agent-team.skill-detail', [
                    's' => $skill,
                    'html' => $pb ? Str::markdown($pb->body, ['html_input' => 'escape', 'allow_unsafe_links' => false]) : '',
                    'rubric' => $pb ? array_map(fn (string $c) => ['code' => $c, 'entry' => QualityRubric::for($c)], $pb->rubricRefs) : [],
                    'usage' => $this->usage()[$skill['ref']] ?? null,
                ]);
            })
            ->modalSubmitAction(false)->modalCancelActionLabel('Đóng');
    }

    public function noticeAction(): Action
    {
        return Action::make('notice')
            ->modalHeading('Ghi công (NOTICE)')
            ->modalContent(function () {
                $raw = @file_get_contents(app(PlaybookLoader::class)->baseDir().'/NOTICE.md');

                return view('filament.clusters.agent-team.skill-notice', [
                    'html' => $raw === false ? '' : Str::markdown($raw, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
                ]);
            })
            ->modalSubmitAction(false)->modalCancelActionLabel('Đóng');
    }

    /**
     * Mọi kỹ năng trên đĩa, mỗi file đọc riêng: file hỏng thành một dòng lỗi.
     *
     * @return list<array<string, mixed>>
     */
    private function skills(): array
    {
        $loader = app(PlaybookLoader::class);
        $registry = new RoleRegistry(null, $loader);
        $usedBy = [];
        foreach ($registry->all() as $r) {
            foreach ($r['skills'] as $ref) {
                $usedBy[$ref][] = ['key' => $r['key'], 'name' => $r['name']];
            }
        }

        $rows = [];
        foreach (glob($loader->baseDir().'/*/*.md') ?: [] as $file) {
            $dirRole = basename(dirname($file));
            $name = basename($file, '.md');
            try {
                $pb = $loader->load($file);
                $ref = $pb->ref();
                $rows[$ref] = ['ref' => $ref, 'title' => $pb->title, 'playbook' => $pb, 'error' => null, 'status' => $pb->status];
            } catch (InvalidPlaybook $e) {
                $ref = $dirRole.'/'.$name;
                $rows[$ref] = ['ref' => $ref, 'title' => $name, 'playbook' => null, 'error' => $e->getMessage(), 'status' => 'error'];
            }
            // Vai trò dùng kỹ năng: khai báo trong sổ vai trò theo khóa "<thư mục>/<key>" (không kèm version).
            $base = $rows[$ref]['playbook'] ? $dirRole.'/'.$rows[$ref]['playbook']->key : $dirRole.'/'.preg_replace('/\.v\d+$/', '', $name);
            $rows[$ref]['roles'] = $usedBy[$base] ?? [];
        }
        uasort($rows, fn ($a, $b) => [$a['roles'][0]['name'] ?? '~', $a['ref']] <=> [$b['roles'][0]['name'] ?? '~', $b['ref']]);

        return array_values($rows);
    }

    /** @return array<string, mixed>|null */
    private function find(string $ref): ?array
    {
        foreach ($this->skills() as $s) {
            if ($s['ref'] === $ref) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Số lần phiên chạy đã dùng từng playbook, đọc từ agent_run_steps.data.playbook.ref.
     *
     * @return array<string, array{count: int, last: ?\Illuminate\Support\Carbon}>
     */
    private function usage(): array
    {
        $out = [];
        foreach (AgentRunStep::query()->whereNotNull('data->playbook->ref')->cursor() as $step) {
            $ref = $step->data['playbook']['ref'] ?? null;
            if (! is_string($ref) || $ref === '') {
                continue;
            }
            $at = $step->started_at ?? $step->created_at;
            $cur = $out[$ref] ?? ['count' => 0, 'last' => null];
            $cur['count']++;
            if ($at !== null && ($cur['last'] === null || $at->greaterThan($cur['last']))) {
                $cur['last'] = $at;
            }
            $out[$ref] = $cur;
        }

        return $out;
    }

    protected function getViewData(): array
    {
        $all = $this->skills();
        $search = Str::lower(trim($this->search));
        $rows = array_values(array_filter($all, function (array $s) use ($search) {
            if ($this->statusFilter !== '' && $s['status'] !== $this->statusFilter) {
                return false;
            }
            if ($this->roleFilter !== '' && ! in_array($this->roleFilter, array_column($s['roles'], 'key'), true)) {
                return false;
            }

            return $search === '' || str_contains(Str::lower($s['title'].' '.$s['ref']), $search);
        }));

        $groups = [];
        foreach ($rows as $s) {
            $groups[$s['roles'][0]['name'] ?? 'Chưa gắn vai trò nào'][] = $s;
        }

        return [
            'groups' => $groups,
            'total' => count($all),
            'drafts' => count(array_filter($all, fn ($s) => $s['status'] === 'draft')),
            'errors' => count(array_filter($all, fn ($s) => $s['status'] === 'error')),
            'roles' => array_map(fn ($r) => ['key' => $r['key'], 'name' => $r['name']], (new RoleRegistry(null, app(PlaybookLoader::class)))->all()),
            'usage' => $this->usage(),
        ];
    }
}
