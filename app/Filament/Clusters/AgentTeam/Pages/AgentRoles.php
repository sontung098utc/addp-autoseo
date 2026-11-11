<?php

namespace App\Filament\Clusters\AgentTeam\Pages;

use App\Filament\Clusters\AgentTeam;
use App\Filament\Clusters\AgentTeam\Concerns\ManagesCustomSkills;
use App\Filament\Pages\AiUsagePage;
use App\Filament\Resources\AgentRunResource;
use App\Models\AgentRunStep;
use App\Models\AiProvider;
use App\Models\Brain;
use App\Models\User;
use App\Services\Agent\Ai\AiModelCatalog;
use App\Services\Agent\Pipeline\PipelineVisual;
use App\Services\Agent\Roles\AgentProfiles;
use App\Services\Agent\Roles\AgentStudioData;
use App\Services\Agent\Roles\RoleAssignments;
use App\Services\Agent\Roles\RoleRegistry;
use App\Services\Agent\Roles\RoleSwitches;
use App\Services\Ai\Playbook\PlaybookLoader;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;
use Livewire\WithFileUploads;

/**
 * Tab "Vai trò" của Đội AI (AG-S09): xem sổ vai trò, mức sẵn sàng THẬT, rubric, rào chắn, tự động hóa, xuất xứ và kỹ năng.
 * AG-S17: mỗi thẻ có khu "Gán" (chỉ admin thao tác; người duyệt chỉ xem): model chính + dự phòng, bật/tắt và ghim phiên bản kỹ năng, bật/tắt vai trò.
 * Xem là chính (nút "Sửa rubric" của bản mẫu chưa làm). AG-S14: công tắc bật/tắt vai trò KHÔNG bắt buộc (hiện chỉ Chiến lược), chỉ admin đổi, ghi audit. Hồ sơ nhân sự mở thành cửa sổ giữa màn hình, chi tiết kỹ thuật là một mục trong hồ sơ.
 */
class AgentRoles extends Page
{
    use ManagesCustomSkills;
    use WithFileUploads;

    /** Agent Studio: vai trò đang mở (null = đóng) và tab hiện tại. */
    public ?string $studioRole = null;

    public string $studioTab = 'overview';

    /** Khối nhỏ đang mở trong tab Huấn luyện. */
    public string $trainSec = 'instructions';

    public const TRAIN_SECTIONS = ['instructions' => 'Chỉ dẫn', 'lessons' => 'Bài học', 'docs' => 'Tài liệu', 'skills' => 'Kỹ năng', 'brain' => 'Trí tuệ'];

    /** Tên mục cũ → [mục mới, khối trong Huấn luyện] để liên kết cũ vẫn mở đúng chỗ. */
    private const LEGACY_TABS = ['persona' => ['training', 'instructions'], 'model' => ['tech', null]];

    public string $fName = '';

    public string $fTitle = '';

    /** @var list<string> */
    public array $fTraits = [];

    public string $newTrait = '';

    public string $fPersona = '';

    public string $fInstructions = '';

    public bool $showHistory = false;

    public string $lessonText = '';

    public string $docTitle = '';

    public string $docBody = '';

    public $docFile = null;

    public string $skillTitle = '';

    public string $skillBody = '';

    /** @var array<int, bool> */
    public array $grants = [];

    /** @var array<string, string> bản nháp bài học theo dòng ở Phòng huấn luyện */
    public array $teach = [];

    /**
     * Cột trái hồ sơ: 5 mục. "Huấn luyện" = nhân sự học và làm gì (chỉ dẫn, bài học, tài liệu, kỹ năng, Trí tuệ, lần chạy cần dạy lại);
     * "Mô hình & kỹ thuật" = nhân sự chạy thế nào (model chính/dự phòng, công cụ, rubric, rào chắn, tự động hóa).
     */
    public const STUDIO_TABS = [
        'overview' => ['Tổng quan', null],
        'training' => ['Huấn luyện', null],
        'tech' => ['Mô hình & kỹ thuật', null],
        'position' => ['Vị trí trong luồng', null],
        'activity' => ['Hoạt động', null],
    ];

    protected static ?string $cluster = AgentTeam::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Vai trò';

    protected static ?string $title = 'Đội & Vai trò';

    protected static ?string $slug = 'vai-tro';

    protected static ?int $navigationSort = 5;

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    protected static string $view = 'filament.clusters.agent-team.roles';

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    // ---- Agent Studio ----

    private function actor(): User
    {
        $u = Auth::user();
        abort_unless($u instanceof User && $u->is_active && $u->isApprover(), 403);

        return $u;
    }

    public function openStudio(string $key, string $tab = 'overview', ?string $sec = null): void
    {
        $this->actor();
        abort_if((new RoleRegistry)->find($key) === null, 404);
        $this->studioRole = $key;
        $this->trainSec = 'instructions';
        [$tab, $sec] = $this->resolveTab($tab, $sec);
        $this->studioTab = $tab;
        if ($sec !== null) {
            $this->trainSec = $sec;
        }
        $this->showHistory = false;
        $this->loadStudioForm();
    }

    public function closeStudio(): void
    {
        $this->studioRole = null;
    }

    /** @return array{0: string, 1: ?string} */
    private function resolveTab(string $tab, ?string $sec): array
    {
        if (isset(self::LEGACY_TABS[$tab])) {
            [$tab, $legacySec] = self::LEGACY_TABS[$tab];
            $sec ??= $legacySec;
        }
        if (! array_key_exists($tab, self::STUDIO_TABS)) {
            return ['overview', null];
        }

        return [$tab, $sec !== null && array_key_exists($sec, self::TRAIN_SECTIONS) ? $sec : null];
    }

    public function setStudioTab(string $tab, ?string $sec = null): void
    {
        if (array_key_exists($tab, self::STUDIO_TABS) || isset(self::LEGACY_TABS[$tab])) {
            [$this->studioTab, $sec] = $this->resolveTab($tab, $sec);
            $this->showHistory = false;
            if ($sec !== null) {
                $this->trainSec = $sec;
            }
        }
    }

    public function setTrainSec(string $sec): void
    {
        if (array_key_exists($sec, self::TRAIN_SECTIONS)) {
            $this->trainSec = $sec;
        }
    }

    private function loadStudioForm(): void
    {
        $p = AgentProfiles::for((string) $this->studioRole);
        $this->fName = (string) $p['display_name'];
        $this->fTitle = (string) $p['title'];
        $this->fTraits = array_values((array) $p['traits']);
        $this->fPersona = (string) $p['persona'];
        $this->fInstructions = (string) $p['instructions'];
        $this->grants = [];
        foreach (AgentProfiles::brainGrants((string) $this->studioRole) as $id) {
            $this->grants[$id] = true;
        }
    }

    private function guarded(\Closure $fn, ?string $ok = null): void
    {
        try {
            $fn($this->actor());
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        if ($ok) {
            Notification::make()->title($ok)->success()->send();
        }
    }

    public function addTrait(?string $t = null): void
    {
        $t = trim($t ?? $this->newTrait);
        $this->newTrait = '';
        if ($t !== '' && ! in_array(mb_strtolower($t), array_map('mb_strtolower', $this->fTraits), true)) {
            $this->fTraits[] = $t;
        }
    }

    public function removeTrait(int $i): void
    {
        unset($this->fTraits[$i]);
        $this->fTraits = array_values($this->fTraits);
    }

    public function saveProfile(): void
    {
        $this->guarded(function (User $u) {
            AgentProfiles::save((string) $this->studioRole, [
                'display_name' => $this->fName, 'title' => $this->fTitle,
                'traits' => $this->fTraits, 'persona' => $this->fPersona, 'instructions' => $this->fInstructions,
            ], $u);
            $this->loadStudioForm();
        }, 'Đã lưu hồ sơ. Có hiệu lực từ lần chạy kế tiếp.');
    }

    public function undoProfile(): void
    {
        $this->guarded(function (User $u) {
            $prev = AgentProfiles::previousVersion((string) $this->studioRole);
            if ($prev === null) {
                throw new \InvalidArgumentException('Chưa có phiên bản trước để hoàn tác.');
            }
            AgentProfiles::restore((string) $this->studioRole, $prev, $u);
            $this->loadStudioForm();
        }, 'Đã hoàn tác về phiên bản trước (tạo phiên bản mới).');
    }

    public function toggleHistory(): void
    {
        $this->showHistory = ! $this->showHistory;
    }

    public function restoreVersion(int $v): void
    {
        $this->guarded(function (User $u) use ($v) {
            AgentProfiles::restore((string) $this->studioRole, $v, $u);
            $this->loadStudioForm();
            $this->showHistory = false;
        }, 'Đã khôi phục (tạo phiên bản mới).');
    }

    public function addLesson(?string $text = null, ?int $runId = null): void
    {
        $this->guarded(function (User $u) use ($text, $runId) {
            AgentProfiles::addLesson((string) $this->studioRole, $text ?? $this->lessonText, $runId, $u);
            $this->lessonText = '';
        }, 'Đã thêm bài học. Áp dụng từ lần chạy kế tiếp.');
    }

    public function teachRow(string $key, ?int $runId): void
    {
        $this->addLesson($this->teach[$key] ?? '', $runId);
        unset($this->teach[$key]);
    }

    public function toggleLesson(int $id, bool $on): void
    {
        $this->guarded(fn (User $u) => AgentProfiles::toggleLesson($id, $on, $u));
    }

    public function deleteLesson(int $id): void
    {
        $this->guarded(fn (User $u) => AgentProfiles::deleteLesson($id, $u), 'Đã xóa bài học.');
    }

    public function addDocument(): void
    {
        $this->guarded(function (User $u) {
            $title = $this->docTitle;
            $body = $this->docBody;
            if ($this->docFile) {
                $ext = strtolower($this->docFile->getClientOriginalExtension());
                if (! in_array($ext, ['md', 'txt'], true) || $this->docFile->getSize() > 100 * 1024) {
                    throw new \InvalidArgumentException('Chỉ nhận tệp .md hoặc .txt dưới 100 KB.');
                }
                $body = (string) file_get_contents($this->docFile->getRealPath());
                if (! mb_check_encoding($body, 'UTF-8')) {
                    throw new \InvalidArgumentException('Tệp cần mã hóa UTF-8.');
                }
                $title = $title !== '' ? $title : pathinfo($this->docFile->getClientOriginalName(), PATHINFO_FILENAME);
            }
            AgentProfiles::addDocument((string) $this->studioRole, 'knowledge', $title, $body, $u);
            $this->docTitle = $this->docBody = '';
            $this->docFile = null;
        }, 'Đã thêm tài liệu.');
    }

    public function addSkillDoc(): void
    {
        $this->guarded(function (User $u) {
            AgentProfiles::addDocument((string) $this->studioRole, 'skill', $this->skillTitle, $this->skillBody, $u);
            $this->skillTitle = $this->skillBody = '';
        }, 'Đã thêm kỹ năng tùy chỉnh.');
    }

    /** Nạp playbook gốc vào cửa sổ "Thêm kỹ năng" để sửa thành bản riêng. */
    public function cloneSkill(string $ref): void
    {
        $pb = in_array($ref, (new RoleRegistry)->find((string) $this->studioRole)['skills'] ?? [], true) ? AgentStudioData::playbookBody($ref) : null;
        if ($pb === null) {
            Notification::make()->title('Chưa có playbook gốc để nhân bản.')->warning()->send();

            return;
        }
        $this->mountAction('createSkill', ['role' => $this->studioRole, 'title' => mb_substr('Bản riêng: '.$pb['title'], 0, 160), 'body' => mb_substr($pb['body'], 0, AgentProfiles::INSTRUCTIONS_MAX)]);
    }

    public function deleteDocument(int $id): void
    {
        $this->guarded(fn (User $u) => AgentProfiles::deleteDocument($id, $u), 'Đã xóa.');
    }

    public function saveGrants(): void
    {
        $this->guarded(function (User $u) {
            AgentProfiles::save((string) $this->studioRole, ['brain_grants' => array_keys(array_filter($this->grants))], $u);
            $this->loadStudioForm();
        }, 'Đã lưu quyền Trí tuệ.');
    }

    /** @return array<string, mixed> */
    private function studioData(): array
    {
        $key = (string) $this->studioRole;
        $reg = new RoleRegistry;
        $role = $reg->find($key);
        $assign = new RoleAssignments($reg);
        $d = ['key' => $key, 'role' => $role, 'profile' => AgentProfiles::for($key), 'tab' => $this->studioTab,
            'canEdit' => $this->isAdmin(), 'enabled' => (new RoleSwitches($reg))->isEnabled($key), 'switchable' => $reg->isSwitchable($key),
            'suggested' => AgentProfiles::SUGGESTED_TRAITS, 'pipelineUrl' => PipelineMap::getUrl(), 'skillsUrl' => self::skillsTabUrl(),
            'runUrl' => fn (int $id) => AgentRunResource::getUrl('view', ['record' => $id])];
        $d['canUndo'] = AgentProfiles::previousVersion($key) !== null;
        match ($this->studioTab) {
            'overview' => $d += $this->overviewData($key, $role, $d['profile'], $assign),
            'training' => $d += $this->trainingData($key, $role, $reg) + ['history' => $this->showHistory ? AgentProfiles::history($key) : []],
            'position' => $d += ['positions' => $this->orderedPositions(AgentStudioData::positions($key))],
            'activity' => $d += ['activity' => AgentStudioData::activity($key)],
            'tech' => $d += $this->modelData($key, $assign) + ['rubric' => $reg->rubric($role)],
            default => null,
        };

        return $d;
    }

    /** Luồng có tham gia lên đầu, luồng không liên quan xuống cuối (giữ thứ tự gốc trong mỗi nhóm). @param list<array<string, mixed>> $positions */
    private function orderedPositions(array $positions): array
    {
        $in = array_values(array_filter($positions, fn ($j) => $j['mine'] > 0));
        $out = array_values(array_filter($positions, fn ($j) => $j['mine'] === 0));

        return [...$in, ...$out];
    }

    /** @return array<string, mixed> */
    private function modelData(string $key, RoleAssignments $assign): array
    {
        $a = $assign->find($key);

        return [
            'assigned' => $assign->summary($key), 'modelWired' => isset(RoleAssignments::ROLE_TASKS[$key]), 'tasks' => RoleAssignments::ROLE_TASKS[$key] ?? [],
            'groups' => AiModelCatalog::available(), 'configUrl' => AiUsagePage::configUrl(), 'tierLabels' => AiProvider::TIER_LABELS,
            'primary' => $a && $a->provider_id && $a->model ? ['provider' => (int) $a->provider_id, 'model' => $a->model] : null,
            'fallback' => $a && $a->fallback_provider_id && $a->fallback_model ? ['provider' => (int) $a->fallback_provider_id, 'model' => $a->fallback_model] : null,
            'modelOn' => $a?->enabled ?? true,
        ];
    }

    /** @return array<string, mixed> */
    private function trainingData(string $key, array $role, RoleRegistry $reg): array
    {
        return [
            'sec' => $this->trainSec, 'sections' => self::TRAIN_SECTIONS,
            'lessons' => AgentProfiles::lessons($key), 'docs' => AgentProfiles::documents($key, 'knowledge'), 'chars' => AgentProfiles::knowledgeChars($key), 'max' => AgentProfiles::PROMPT_MAX,
            'issues' => AgentStudioData::trainingIssues($key),
            'skills' => $reg->skills($role), 'custom' => AgentProfiles::documents($key, 'skill'),
            'brains' => $this->brains(), 'applied' => $key === 'writer',
        ];
    }

    /** Chọn model cho nhân sự từ danh sách khả dụng. $slot: primary | fallback. Chỉ nhận model đang BẬT ở một tài khoản đang dùng được. */
    public function pickModel(string $slot, int $providerId, string $model): void
    {
        $this->guarded(function (User $u) use ($slot, $providerId, $model) {
            abort_unless(in_array($slot, ['primary', 'fallback'], true), 422);
            $ok = collect(AiModelCatalog::available())->firstWhere('id', $providerId);
            if (! $ok || ! in_array($model, array_column($ok['models'], 'id'), true)) {
                throw new \InvalidArgumentException('Model này chưa được bật ở Cấu hình AI.');
            }
            $assign = new RoleAssignments;
            $a = $assign->find((string) $this->studioRole);
            $p = [$a?->provider_id, $a?->model, $a?->fallback_provider_id, $a?->fallback_model];
            if ($slot === 'primary') {
                [$p[0], $p[1]] = [$providerId, $model];
            } else {
                [$p[2], $p[3]] = [$providerId, $model];
            }
            $assign->saveModel((string) $this->studioRole, $p[0], $p[1], $p[2], $p[3], $a?->enabled ?? true, $u);
        }, $slot === 'primary' ? 'Đã chọn model chính.' : 'Đã chọn model dự phòng.');
    }

    public function clearModel(string $slot): void
    {
        $this->guarded(function (User $u) use ($slot) {
            $assign = new RoleAssignments;
            $a = $assign->find((string) $this->studioRole);
            if ($slot === 'fallback') {
                $assign->saveModel((string) $this->studioRole, $a?->provider_id, $a?->model, null, null, $a?->enabled ?? true, $u);
            } else {
                $assign->saveModel((string) $this->studioRole, null, null, null, null, $a?->enabled ?? true, $u);
            }
        }, 'Đã bỏ chọn.');
    }

    /**
     * Bảng điều hành của một nhân sự: đang làm gì, model, 3 số thật 7 ngày và việc cần làm tiếp (nếu có).
     *
     * @return array<string, mixed>
     */
    private function overviewData(string $key, array $role, array $profile, RoleAssignments $assign): array
    {
        $reg = new RoleRegistry;
        $working = [];
        foreach (PipelineVisual::office() as $d) {
            if ($d['profile']['key'] === $key) {
                $working = $d['working'];
            }
        }
        $wired = isset(RoleAssignments::ROLE_TASKS[$key]);
        $week = ['runs' => 0, 'steps' => 0, 'ok_rate' => null, 'cost' => 0.0];
        try {
            $q = AgentRunStep::query()->where('role', $key)->where('created_at', '>=', now()->subDays(7));
            $agg = (clone $q)->selectRaw("count(*) as n, count(distinct agent_run_id) as runs, sum(case when status in ('ok','skipped') then 1 else 0 end) as ok, coalesce(sum(cost_vnd),0) as cost")->first();
            $n = (int) ($agg->n ?? 0);
            $week = ['runs' => (int) ($agg->runs ?? 0), 'steps' => $n, 'ok_rate' => $n > 0 ? (int) round(100 * (int) $agg->ok / $n) : null, 'cost' => (float) ($agg->cost ?? 0)];
        } catch (\Throwable) {
        }

        return [
            'working' => $working, 'week' => $week,
            'model' => $assign->summary($key), 'next' => $this->nextSteps($key, $role, $profile, $wired, $assign),
        ];
    }

    /**
     * Việc cần làm tiếp (chỉ những gì thật sự thiếu): vai trò đã gọi AI mà chưa chọn model, chưa có chỉ dẫn, khai báo kỹ năng nhưng không còn kỹ năng nào bật.
     *
     * @return list<array{label: string, tab: string, sec: ?string}>
     */
    private function nextSteps(string $key, array $role, array $profile, bool $wired, RoleAssignments $assign): array
    {
        $next = [];
        if ($wired && ! $assign->summary($key)) {
            $next[] = ['label' => 'Chọn model', 'tab' => 'tech', 'sec' => null];
        }
        if (trim((string) $profile['instructions']) === '') {
            $next[] = ['label' => 'Viết chỉ dẫn', 'tab' => 'training', 'sec' => 'instructions'];
        }
        $reg = new RoleRegistry;
        $on = count(array_filter($reg->skills($role), fn ($s) => $s['exists'] && $s['enabled']))
            + count(array_filter(AgentProfiles::documents($key, 'skill'), fn ($d) => $d->enabled !== false));
        if (($role['skills'] ?? []) !== [] && $on === 0) {
            $next[] = ['label' => 'Bật một kỹ năng', 'tab' => 'training', 'sec' => 'skills'];
        }

        return $next;
    }

    /** @return list<array{id: int, name: string}> */
    private function brains(): array
    {
        try {
            return Brain::query()->where('status', 'active')->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function toggleRole(string $key): void
    {
        $user = Auth::user();
        $switches = new RoleSwitches;
        try {
            abort_unless($user instanceof User, 403);
            $switches->set($key, ! $switches->isEnabled($key), $user);
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($switches->isEnabled($key) ? 'Đã bật vai trò' : 'Đã tắt vai trò')->success()->send();
    }

    private function isAdmin(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isAdmin();
    }

    /** AG-S17: cửa sổ trượt "Gán" cho một vai trò. Ẩn với người không phải admin (không mở được); dịch vụ vẫn kiểm quyền lần nữa khi lưu. */
    public function assignAction(): Action
    {
        return Action::make('assign')
            ->visible(fn () => $this->isAdmin())
            ->modalHeading(fn (array $arguments) => 'Kỹ năng và bật/tắt: '.((new RoleRegistry)->find((string) ($arguments['role'] ?? ''))['name'] ?? 'Vai trò'))
            ->slideOver()->modalWidth('2xl')
            ->modalSubmitActionLabel('Lưu')
            ->fillForm(function (array $arguments): array {
                $role = (string) ($arguments['role'] ?? '');
                $a = (new RoleAssignments)->find($role);
                $data = ['role_enabled' => (new RoleSwitches)->isEnabled($role)];
                $reg = new RoleRegistry;
                $assign = new RoleAssignments($reg);
                foreach (array_values($reg->find($role)['skills'] ?? []) as $i => $ref) {
                    $st = $assign->skillState($ref);
                    $data["sk_{$i}_on"] = $st['enabled'];
                    $data["sk_{$i}_pin"] = $st['pin'] === null ? '' : (string) $st['pin'];
                }

                return $data;
            })
            ->form(fn (array $arguments) => $this->assignForm((string) ($arguments['role'] ?? '')))
            ->action(function (array $data, array $arguments, Action $action): void {
                $user = Auth::user();
                $role = (string) ($arguments['role'] ?? '');
                $reg = new RoleRegistry;
                $def = $reg->find($role);
                abort_unless($user instanceof User && $def !== null, 404);
                $assign = new RoleAssignments($reg);
                try {
                    $skills = [];
                    foreach (array_values($def['skills']) as $i => $ref) {
                        $skills[$ref] = ['enabled' => (bool) ($data["sk_{$i}_on"] ?? true), 'pin' => $data["sk_{$i}_pin"] ?? null];
                    }
                    if ($skills !== []) {
                        $assign->saveSkills($role, $skills, $user);
                    }
                    $sw = new RoleSwitches($reg);
                    if ($reg->isSwitchable($role) && array_key_exists('role_enabled', $data) && $sw->isEnabled($role) !== (bool) $data['role_enabled']) {
                        $sw->set($role, (bool) $data['role_enabled'], $user);
                    }
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                }
                Notification::make()->title('Đã lưu phần gán cho '.$def['name'])->success()->send();
            });
    }

    private function intOrNull(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }

    /** @return list<Component> */
    private function assignForm(string $role): array
    {
        $reg = new RoleRegistry;
        $def = $reg->find($role);
        abort_if($def === null, 404);
        $schema = [];

        if ($def['skills'] !== []) {
            $loader = new PlaybookLoader;
            $versions = [];
            foreach ($def['skills'] as $ref) {
                [$r, $k] = explode('/', $ref, 2);
                try {
                    foreach ($loader->all() as $pb) {
                        if ($pb->role === $r && $pb->key === $k && $pb->status !== 'retired') {
                            $versions[$ref][(string) $pb->version] = 'v'.$pb->version.' · '.($pb->status === 'active' ? 'đang dùng' : 'bản nháp');
                        }
                    }
                } catch (\Throwable) {
                }
            }
            $fields = [];
            foreach (array_values($def['skills']) as $i => $ref) {
                $fields[] = Toggle::make("sk_{$i}_on")->label($ref);
                $fields[] = Select::make("sk_{$i}_pin")->label('Phiên bản')->options(['' => 'Mới nhất (không ghim)'] + ($versions[$ref] ?? []))->selectablePlaceholder(false);
            }
            $schema[] = Section::make('Kỹ năng')->description('Tắt kỹ năng thì bước đó dùng chỉ dẫn mặc định. Ghim chỉ chọn được playbook hợp lệ, chưa ngừng dùng.')->schema($fields)->columns(2);
        }

        if ($reg->isSwitchable($role)) {
            $schema[] = Section::make('Vai trò')->schema([Toggle::make('role_enabled')->label('Bật vai trò')->helperText('Tắt thì bước của vai trò này bị bỏ qua, cổng chấm luật vẫn chạy đủ.')]);
        }

        return $schema;
    }

    /** URL tab "Kỹ năng" (slug ky-nang) nếu trang đó đã tồn tại trong panel; chưa có thì null (không dẫn tới trang 404). */
    public static function skillsTabUrl(): ?string
    {
        try {
            foreach (Filament::getCurrentPanel()?->getPages() ?? [] as $page) {
                if (method_exists($page, 'getSlug') && $page::getSlug() === 'ky-nang' && $page::canAccess()) {
                    return $page::getUrl();
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    protected function getViewData(): array
    {
        $reg = new RoleRegistry;
        $off = (new RoleSwitches($reg))->disabled();
        $user = Auth::user();

        $assign = new RoleAssignments($reg);
        $hasProviders = AiProvider::query()->exists();
        $office = [];
        foreach (PipelineVisual::office() as $d) {
            $office[$d['profile']['key']] = $d;
        }

        return [
            'cards' => array_map(function (array $r) use ($reg, $off, $assign, $office) {
                $a = $assign->find($r['key']);

                $prof = AgentProfiles::for($r['key']);

                return array_merge($r, ['profile' => $prof, 'name' => $prof['display_name']]) + [
                    'skill_counts' => $reg->skillCounts($r), 'switchable' => $reg->isSwitchable($r['key']), 'is_off' => in_array($r['key'], $off, true),
                    'model_wired' => isset(RoleAssignments::ROLE_TASKS[$r['key']]), 'assigned' => $assign->summary($r['key']),
                    'working' => $office[$r['key']]['working'] ?? [], 'joins' => $office[$r['key']]['joins'] ?? [],
                    'skill_changes' => count(array_filter($reg->skills($r), fn ($s) => ! $s['enabled'] || $s['pin'] !== null)),
                    'updated' => $a ? trim(($a->updater?->name ?? '').' · '.$a->updated_at?->format('d/m/Y H:i'), ' ·') : null,
                ];
            }, $reg->all()),
            'skillsUrl' => self::skillsTabUrl(), 'canSwitch' => $user instanceof User && $user->isAdmin(),
            'hasProviders' => $hasProviders, 'connectionUrl' => AiUsagePage::configUrl(),
            'studio' => $this->studioRole !== null ? $this->studioData() : null,
        ];
    }
}
