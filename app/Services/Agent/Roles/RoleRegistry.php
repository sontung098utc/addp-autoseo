<?php

namespace App\Services\Agent\Roles;

use App\Services\Ai\Playbook\InvalidPlaybook;
use App\Services\Ai\Playbook\Playbook;
use App\Services\Ai\Playbook\PlaybookLoader;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityRubric;
use InvalidArgumentException;

/**
 * AG-S09: sổ vai trò của đội agent, đọc từ resources/agents/roles/*.php (mỗi vai trò một file, là DỮ LIỆU).
 *
 * Mức sẵn sàng được SUY ra, không tô tay: vai trò có `orchestrator_steps` (bước => người thực hiện ghi trong nhật ký, và thật sự
 * có trong AgentRunOrchestrator, có test đối chiếu mã nguồn) là "in_development" (mới kiểm bằng AI giả lập); vai trò còn lại phải khai
 * báo not_connected | idea | demo. KHÔNG có đường nào cho ra "active": cần bằng chứng staging và người thật nghiệm thu, chưa có.
 *
 * Kỹ năng = khóa playbook "<role>/<key>". Playbook chưa tồn tại KHÔNG làm lỗi: hiển thị "Chưa có".
 * rubric_refs phải là mã luật có thật trong QualityRubric (sai thì ném lỗi lúc nạp).
 */
final class RoleRegistry
{
    public const TIERS = ['cheap' => 'Rẻ', 'standard' => 'Chuẩn', 'reasoning' => 'Suy luận'];

    private const UNWIRED_READINESS = ['not_connected', 'idea', 'demo'];

    private const REQUIRED = ['key', 'name', 'goal', 'tools_allowed', 'tools_denied', 'model_tier', 'human_gate', 'rubric_refs', 'guardrails',
        'pass_condition', 'on_fail', 'topic_class', 'on_timeout', 'skills', 'orchestrator_steps', 'origin'];

    /** AG-S14: bước của phiên chạy thuộc vai trò nào (khóa vai trò, hoặc 'system' = hệ thống). Có test đối chiếu với AgentRunOrchestrator. */
    public const STEP_ROLE = [
        'guard' => 'system', 'handoff' => 'system', 'images' => 'designer', 'assemble' => 'system', 'retry' => 'system', 'failover' => 'system', 'decision' => 'system',
        'risk' => 'editor', 'brief' => 'strategist', 'write' => 'writer', 'repair' => 'writer', 'grade' => 'editor',
    ];

    /** AG-S14: playbook (kỹ năng) mỗi bước dùng, khai báo tường minh. editor/eeat-check chỉ là gợi ý định tính, KHÔNG nạp vào bước nào. */
    public const STEP_PLAYBOOK = [
        'brief' => 'brief/content-brief', 'write' => 'writer/article-draft', 'repair' => 'editor/qa-review', 'grade' => 'editor/qa-review',
    ];

    /** Vai trò không được tắt dù ai yêu cầu: cổng an toàn (chấm luật) và bước viết. */
    public const LOCKED = ['editor', 'writer'];

    /** @var list<array<string, mixed>>|null */
    private ?array $roles = null;

    public function __construct(private readonly ?string $dir = null, private readonly ?PlaybookLoader $playbooks = null) {}

    public function dir(): string
    {
        return $this->dir ?? resource_path('agents/roles');
    }

    /** @return list<array<string, mixed>> thứ tự theo tên file; mỗi vai trò đã kèm `readiness` suy ra. */
    public function all(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }
        $out = [];
        $files = glob($this->dir().'/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $out[] = $this->normalize((array) require $file, basename($file));
        }

        return $this->roles = $out;
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $r) {
            if ($r['key'] === $key) {
                return $r;
            }
        }

        return null;
    }

    /** Tên tiếng Việt của vai trò; 'system' là Hệ thống. */
    public function roleName(string $key): string
    {
        if ($key === 'system') {
            return 'Hệ thống';
        }
        $custom = null;
        try {
            $custom = AgentProfiles::customName($key); // Agent Studio: tên do admin đặt (cache theo request)
        } catch (\Throwable) {
        }

        return $custom ?? ($this->find($key)['name'] ?? $key);
    }

    /** Vai trò có được tắt không: phải khai báo can_disable và không nằm trong LOCKED. */
    public function isSwitchable(string $key): bool
    {
        return ! in_array($key, self::LOCKED, true) && (bool) ($this->find($key)['can_disable'] ?? false);
    }

    /**
     * Kỹ năng của vai trò, kèm trạng thái playbook THẬT trên đĩa.
     *
     * AG-S17: kèm `enabled` và `pin` từ bản gán theo vai trò (mặc định bật, không ghim); `version` vẫn là bản mới nhất trên đĩa.
     *
     * @return list<array{ref: string, role: string, key: string, exists: bool, version: ?int, status: ?string, title: ?string, lineage: ?array<string, string>, error: ?string, enabled: bool, pin: ?int}>
     */
    public function skills(array $role): array
    {
        $loader = $this->playbooks ?? new PlaybookLoader;
        $assign = new RoleAssignments($this);
        $out = [];
        foreach ($role['skills'] as $ref) {
            [$pRole, $pKey] = explode('/', $ref, 2);
            $row = ['ref' => $ref, 'role' => $pRole, 'key' => $pKey, 'exists' => false, 'version' => null, 'status' => null, 'title' => null, 'lineage' => null, 'error' => null] + $assign->skillState($ref);
            try {
                $pb = $loader->findLatest($pRole, $pKey);
            } catch (InvalidPlaybook $e) {
                $out[] = ['error' => 'Playbook lỗi định dạng: '.$e->getMessage()] + $row;

                continue;
            }
            if ($pb instanceof Playbook) {
                $row = ['exists' => true, 'version' => $pb->version, 'status' => $pb->status, 'title' => $pb->title, 'lineage' => $pb->lineage] + $row;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Số kỹ năng đã có playbook thật / tổng khai báo. @return array{have: int, total: int} */
    public function skillCounts(array $role): array
    {
        $s = $this->skills($role);

        return ['have' => count(array_filter($s, fn ($r) => $r['exists'])), 'total' => count($s)];
    }

    /**
     * Bảng luật của vai trò, lấy từ QualityRubric; "mức" suy từ các mức P0/P1/P2 mà lớp luật có thể trả (đọc mã nguồn luật).
     *
     * @return list<array<string, mixed>>
     */
    public function rubric(array $role): array
    {
        $severity = self::severityByCode();
        $rows = [];
        foreach ($role['rubric_refs'] as $code) {
            $e = QualityRubric::for($code);
            if ($e !== null) {
                $rows[] = $e + ['severities' => $severity[$code] ?? []];
            }
        }

        return $rows;
    }

    /** @return array<string, list<string>> mã luật => các mức có thể trả (P0..P2), đọc từ mã nguồn lớp luật mặc định. */
    public static function severityByCode(): array
    {
        $map = [];
        foreach ((new \ReflectionClass(ContentQualityChecker::class))->getMethod('defaultRules')->invoke(null) as $rule) {
            $src = (string) @file_get_contents((new \ReflectionClass($rule))->getFileName());
            preg_match_all('/RuleResult::(P[0-2])/', $src, $m);
            $levels = array_values(array_unique($m[1]));
            sort($levels);
            $map[$rule->code()] = $levels;
        }

        return $map;
    }

    /** @return array<string, mixed> */
    private function normalize(array $r, string $file): array
    {
        foreach (self::REQUIRED as $k) {
            if (! array_key_exists($k, $r)) {
                throw new InvalidArgumentException("{$file}: thiếu khóa '{$k}'");
            }
        }
        if (basename($file, '.php') !== $r['key']) {
            throw new InvalidArgumentException("{$file}: tên file phải trùng key '{$r['key']}'");
        }
        if (! isset(self::TIERS[$r['model_tier']])) {
            throw new InvalidArgumentException("{$file}: model_tier phải là ".implode('|', array_keys(self::TIERS)));
        }
        foreach ($r['rubric_refs'] as $code) {
            if (QualityRubric::for($code) === null) {
                throw new InvalidArgumentException("{$file}: rubric_refs '{$code}' không phải mã luật có thật trong QualityRubric");
            }
        }
        foreach ($r['skills'] as $ref) {
            if (! preg_match('#^[a-z0-9_-]+/[a-z0-9-]+$#', $ref)) {
                throw new InvalidArgumentException("{$file}: kỹ năng '{$ref}' phải có dạng role/key");
            }
        }
        if ($r['orchestrator_steps'] !== []) {
            $r['readiness'] = 'in_development';
        } elseif (! in_array($r['readiness'] ?? null, self::UNWIRED_READINESS, true)) {
            throw new InvalidArgumentException("{$file}: vai trò chưa nối phải khai báo readiness ".implode('|', self::UNWIRED_READINESS).' (không có "active")');
        }
        $r['readiness_note'] ??= '';
        $r['tier_label'] = self::TIERS[$r['model_tier']];

        return $r;
    }
}
