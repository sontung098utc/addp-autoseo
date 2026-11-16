<?php

namespace App\Services\Agent\Roles;

use App\Filament\Support\Audit\LogAuditSink;
use App\Models\AgentSetting;
use App\Models\User;
use InvalidArgumentException;
use Throwable;

/**
 * AG-S14: công tắc bật/tắt vai trò KHÔNG bắt buộc. Chỉ admin đổi, mỗi lần đổi ghi nhật ký audit (ai, vai trò, bật/tắt).
 * Vai trò bắt buộc (editor = cổng chấm luật, writer) không tắt được: bị chặn ở đây VÀ khi đọc (disabled() chỉ trả vai trò được phép tắt).
 */
final class RoleSwitches
{
    public function __construct(private readonly ?RoleRegistry $registry = null)
    {
    }

    private function registry(): RoleRegistry
    {
        return $this->registry ?? new RoleRegistry();
    }

    /** @return list<string> khóa vai trò đang tắt VÀ được phép tắt. Lỗi đọc cấu hình => coi như không tắt gì. */
    public function disabled(): array
    {
        try {
            $stored = (array) (AgentSetting::current()->disabled_roles ?? []);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter(array_unique($stored), fn ($k) => is_string($k) && $this->registry()->isSwitchable($k)));
    }

    public function isEnabled(string $key): bool
    {
        return ! in_array($key, $this->disabled(), true);
    }

    public function set(string $key, bool $enabled, User $actor): void
    {
        if (! $actor->is_active || ! $actor->isAdmin()) {
            throw new InvalidArgumentException('Chỉ quản trị viên mới được bật/tắt vai trò.');
        }
        if ($this->registry()->find($key) === null) {
            throw new InvalidArgumentException("Không có vai trò '{$key}'.");
        }
        if (! $this->registry()->isSwitchable($key)) {
            throw new InvalidArgumentException('Vai trò này là bắt buộc (cổng an toàn hoặc bước viết), không tắt được.');
        }
        $setting = AgentSetting::current();
        $off = array_values(array_diff((array) ($setting->disabled_roles ?? []), [$key]));
        if (! $enabled) {
            $off[] = $key;
        }
        $setting->disabled_roles = array_values(array_unique($off));
        $setting->updated_by = $actor->id;
        $setting->save();

        (new LogAuditSink())->write([
            'action' => 'role_switch', 'actor_id' => $actor->id, 'actor_role' => $actor->role, 'role' => $key,
            'enabled' => $enabled, 'at_utc' => now('UTC')->toIso8601String(),
        ]);
    }
}
