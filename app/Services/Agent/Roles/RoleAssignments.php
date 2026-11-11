<?php

namespace App\Services\Agent\Roles;

use App\Filament\Support\Audit\LogAuditSink;
use App\Models\AgentRoleAssignment;
use App\Models\AiProvider;
use App\Models\User;
use App\Services\Ai\Playbook\InvalidPlaybook;
use App\Services\Ai\Playbook\PlaybookLoader;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * AG-S17: gán theo vai trò. Chỉ admin ghi; mỗi lần đổi ghi nhật ký audit (ai, vai trò, đổi gì, lúc nào) và lưu updated_by/updated_at.
 * Model: gán được cho mọi vai trò; chỉ vai trò đã nối vào một loại việc (ROLE_TASKS) mới được luồng gọi AI dùng ngay. Kỹ năng: chỉ kỹ năng khai báo của vai trò, ghim chỉ phiên bản có thật,
 * hợp lệ và chưa retired. Đọc không bao giờ ném lỗi ra ngoài (bảng chưa migrate => coi như chưa gán gì).
 */
final class RoleAssignments
{
    /** Vai trò → loại việc của AiModelRouter. Vai trò không có ở đây thì chưa nối vào luồng gọi AI. */
    public const ROLE_TASKS = ['strategist' => ['outline'], 'writer' => ['write', 'repair'], 'editor' => ['summary']];

    public function __construct(private readonly ?RoleRegistry $registry = null, private readonly ?PlaybookLoader $playbooks = null) {}

    private function registry(): RoleRegistry
    {
        return $this->registry ?? new RoleRegistry;
    }

    public static function roleForTask(string $task): ?string
    {
        foreach (self::ROLE_TASKS as $role => $tasks) {
            if (in_array($task, $tasks, true)) {
                return $role;
            }
        }

        return null;
    }

    public function find(string $role): ?AgentRoleAssignment
    {
        try {
            return Schema::hasTable('agent_role_assignments') ? AgentRoleAssignment::query()->with(['provider', 'fallbackProvider', 'updater'])->where('role_key', $role)->first() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Bản gán MODEL đang có hiệu lực cho một loại việc (bật, đủ provider + model); null nếu không. */
    public function forTask(string $task): ?AgentRoleAssignment
    {
        $role = self::roleForTask($task);
        $a = $role ? $this->find($role) : null;

        return $a && $a->enabled && $a->provider_id && $a->model ? $a : null;
    }

    public static function hasAny(): bool
    {
        try {
            return Schema::hasTable('agent_role_assignments') && AgentRoleAssignment::query()->whereNotNull('provider_id')->where('enabled', true)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{enabled: bool, pin: ?int} trạng thái gán của một kỹ năng "<role>/<key>"; mặc định bật, không ghim. */
    public function skillState(string $ref): array
    {
        foreach ($this->all() as $a) {
            $s = (array) ($a->skills[$ref] ?? []);
            if ($s !== []) {
                return ['enabled' => (bool) ($s['enabled'] ?? true), 'pin' => isset($s['pin']) ? (int) $s['pin'] : null];
            }
        }

        return ['enabled' => true, 'pin' => null];
    }

    /** @return list<AgentRoleAssignment> */
    private function all(): array
    {
        try {
            return Schema::hasTable('agent_role_assignments') ? AgentRoleAssignment::query()->get()->all() : [];
        } catch (Throwable) {
            return [];
        }
    }

    public function saveModel(string $role, ?int $providerId, ?string $model, ?int $fallbackProviderId, ?string $fallbackModel, bool $enabled, User $actor): AgentRoleAssignment
    {
        $this->guard($actor, $role);
        $model = trim((string) $model) ?: null;
        $fallbackModel = trim((string) $fallbackModel) ?: null;
        if (($providerId === null) !== ($model === null)) {
            throw new InvalidArgumentException('Chọn đủ cả nhà cung cấp và model, hoặc để trống cả hai.');
        }
        if (($fallbackProviderId === null) !== ($fallbackModel === null)) {
            throw new InvalidArgumentException('Model dự phòng cần đủ cả nhà cung cấp và model, hoặc để trống cả hai.');
        }
        foreach ([$providerId, $fallbackProviderId] as $pid) {
            if ($pid !== null && ! AiProvider::query()->whereKey($pid)->exists()) {
                throw new InvalidArgumentException('Nhà cung cấp đã chọn không tồn tại. Thêm ở Cấu hình AI → Tích hợp AI.');
            }
        }
        foreach ([$model, $fallbackModel] as $m) {
            if ($m !== null && (strlen($m) > 80 || ! preg_match('/^[A-Za-z0-9._:\/-]+$/', $m))) {
                throw new InvalidArgumentException('Tên model không hợp lệ (chỉ chữ, số và . _ : / -, tối đa 80 ký tự).');
            }
        }
        if ($providerId !== null && $providerId === $fallbackProviderId && $model === $fallbackModel) {
            throw new InvalidArgumentException('Model dự phòng trùng đúng model chính, hãy chọn model khác.');
        }
        $a = AgentRoleAssignment::query()->firstOrNew(['role_key' => $role]);
        $a->fill(['provider_id' => $providerId, 'model' => $model, 'fallback_provider_id' => $fallbackProviderId, 'fallback_model' => $fallbackModel, 'enabled' => $enabled, 'updated_by' => $actor->id])->save();
        $this->log($actor, $role, 'model', ['provider_id' => $providerId, 'model' => $model, 'fallback_provider_id' => $fallbackProviderId, 'fallback_model' => $fallbackModel, 'enabled' => $enabled]);

        return $a;
    }

    /**
     * @param  array<string, array{enabled?: bool, pin?: int|string|null}>  $skills  ref => trạng thái; ref không khai báo trong vai trò bị từ chối
     */
    public function saveSkills(string $role, array $skills, User $actor): AgentRoleAssignment
    {
        $this->guard($actor, $role);
        $declared = $this->registry()->find($role)['skills'];
        $loader = $this->playbooks ?? new PlaybookLoader;
        $clean = [];
        foreach ($skills as $ref => $s) {
            if (! in_array($ref, $declared, true)) {
                throw new InvalidArgumentException("Kỹ năng '{$ref}' không thuộc vai trò này.");
            }
            $pin = ($s['pin'] ?? null) === null || ($s['pin'] ?? '') === '' ? null : (int) $s['pin'];
            if ($pin !== null) {
                [$pRole, $pKey] = explode('/', $ref, 2);
                try {
                    $pb = $loader->find($pRole, $pKey, $pin);
                } catch (InvalidPlaybook $e) {
                    throw new InvalidArgumentException("Playbook {$ref} không hợp lệ nên không ghim được.");
                }
                if ($pb === null) {
                    throw new InvalidArgumentException("Playbook {$ref} chưa có phiên bản v{$pin}.");
                }
                if ($pb->status === 'retired') {
                    throw new InvalidArgumentException("Playbook {$ref}@{$pin} đã ngừng dùng (retired), không ghim được.");
                }
            }
            $clean[$ref] = ['enabled' => (bool) ($s['enabled'] ?? true), 'pin' => $pin];
        }
        $a = AgentRoleAssignment::query()->firstOrNew(['role_key' => $role]);
        $before = (array) ($a->skills ?? []);
        $a->skills = $clean;
        $a->updated_by = $actor->id;
        $a->save();
        $changes = [];
        foreach ($clean as $ref => $s) {
            if (($before[$ref] ?? ['enabled' => true, 'pin' => null]) != $s) {
                $changes[$ref] = $s;
            }
        }
        $this->log($actor, $role, 'skills', ['changes' => $changes]);

        return $a;
    }

    /** Dòng tóm tắt trạng thái hiện hành cho thẻ vai trò, ví dụ "Anthropic · claude-sonnet-5-5, dự phòng Gemini". Null nếu chưa gán. */
    public function summary(string $role): ?string
    {
        $a = $this->find($role);
        if (! $a || ! $a->provider_id || ! $a->model) {
            return null;
        }
        $s = ($a->provider?->name ?? 'Provider đã xóa').' · '.$a->model;
        if ($a->fallback_provider_id && $a->fallback_model) {
            $s .= ', dự phòng '.($a->fallbackProvider?->name ?? 'Provider đã xóa').' · '.$a->fallback_model;
        }

        return $a->enabled ? $s : $s.' (tạm tắt)';
    }

    private function guard(User $actor, string $role): void
    {
        if (! $actor->is_active || ! $actor->isAdmin()) {
            throw new InvalidArgumentException('Chỉ quản trị viên mới được gán vai trò.');
        }
        if ($this->registry()->find($role) === null) {
            throw new InvalidArgumentException("Không có vai trò '{$role}'.");
        }
    }

    private function log(User $actor, string $role, string $what, array $detail): void
    {
        (new LogAuditSink)->write(['action' => 'role_assignment', 'actor_id' => $actor->id, 'actor_role' => $actor->role, 'role' => $role, 'what' => $what, 'detail' => $detail, 'at_utc' => now('UTC')->toIso8601String()]);
    }
}
