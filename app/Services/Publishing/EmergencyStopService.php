<?php

namespace App\Services\Publishing;

use App\Filament\Support\Audit\AuditAction;
use App\Filament\Support\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class EmergencyStopService
{
    public const CACHE_PREFIX = 'autoseo:emergency_stop:';
    public const GLOBAL_KEY = self::CACHE_PREFIX . 'global';

    public function __construct(protected ?AuditRecorder $auditRecorder = null)
    {
        $this->auditRecorder = $this->auditRecorder ?? app(AuditRecorder::class);
    }

    /**
     * Kiểm tra xem toàn hệ thống hoặc website cụ thể có đang dừng khẩn cấp không.
     */
    public function isStopped(?int $websiteId = null): bool
    {
        if (Cache::get(self::GLOBAL_KEY) !== null) {
            return true;
        }

        if ($websiteId !== null) {
            return Cache::get(self::CACHE_PREFIX . "site_{$websiteId}") !== null;
        }

        return false;
    }

    /**
     * Lấy thông tin chi tiết về lệnh dừng khẩn cấp đang kích hoạt.
     *
     * @return array{scope: string, website_id: ?int, stopped_at: string, stopped_by_id: int, stopped_by_name: string, reason: string}|null
     */
    public function getStopInfo(?int $websiteId = null): ?array
    {
        $global = Cache::get(self::GLOBAL_KEY);
        if ($global !== null && is_array($global)) {
            return $global;
        }

        if ($websiteId !== null) {
            $site = Cache::get(self::CACHE_PREFIX . "site_{$websiteId}");
            if ($site !== null && is_array($site)) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Kích hoạt dừng khẩn cấp (Toàn hệ thống hoặc theo Website).
     */
    public function stop(User $actor, string $reason, ?int $websiteId = null): array
    {
        if (! $actor->is_active) {
            throw new InvalidArgumentException('Tài khoản đã bị vô hiệu hóa, không thể thực hiện hành động này.');
        }

        if (! $actor->isApprover()) {
            throw new InvalidArgumentException('Chỉ Trưởng nhóm duyệt bài hoặc Quản trị viên mới có quyền dừng khẩn cấp.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Bắt buộc phải nhập lý do dừng khẩn cấp.');
        }

        $info = [
            'scope' => $websiteId === null ? 'global' : 'site',
            'website_id' => $websiteId,
            'stopped_at' => now()->toIso8601String(),
            'stopped_by_id' => $actor->id,
            'stopped_by_name' => $actor->name,
            'reason' => $reason,
        ];

        $key = $websiteId === null ? self::GLOBAL_KEY : self::CACHE_PREFIX . "site_{$websiteId}";
        Cache::forever($key, $info);

        // Ghi Audit log
        $this->auditRecorder->record(
            AuditAction::EmergencyStop,
            $actor,
            article: null,
            version: null,
            reason: $reason,
            extra: [
                'scope' => $info['scope'],
                'website_id' => $websiteId,
            ],
        );

        Log::warning(sprintf(
            '[EmergencyStop] Người dùng #%d (%s) đã kích hoạt DỪNG KHẨN CẤP [%s]. Lý do: %s',
            $actor->id,
            $actor->name,
            $info['scope'] === 'global' ? 'Toàn hệ thống' : "Website #{$websiteId}",
            $reason
        ));

        return $info;
    }

    /**
     * Khôi phục hoạt động xuất bản tự động.
     */
    public function resume(User $actor, ?string $reason = null, ?int $websiteId = null): void
    {
        if (! $actor->is_active) {
            throw new InvalidArgumentException('Tài khoản đã bị vô hiệu hóa, không thể thực hiện hành động này.');
        }

        if (! $actor->isApprover()) {
            throw new InvalidArgumentException('Chỉ Trưởng nhóm duyệt bài hoặc Quản trị viên mới có quyền khôi phục lịch đăng.');
        }

        $key = $websiteId === null ? self::GLOBAL_KEY : self::CACHE_PREFIX . "site_{$websiteId}";
        Cache::forget($key);

        Log::info(sprintf(
            '[EmergencyStop] Người dùng #%d (%s) đã KHÔI PHỤC hoạt động xuất bản [%s]. Ghi chú: %s',
            $actor->id,
            $actor->name,
            $websiteId === null ? 'Toàn hệ thống' : "Website #{$websiteId}",
            $reason ?: 'Không có'
        ));
    }

    /**
     * Xóa sạch cờ dừng khẩn cấp (dùng cho testing).
     */
    public function resetForTesting(): void
    {
        Cache::forget(self::GLOBAL_KEY);
    }
}
