<?php

namespace App\Filament\Support\SeoAudit;

use App\Filament\Support\Demo\DemoMode;
use App\Models\User;
use App\Models\Website;
use App\Services\Security\UrlSafetyService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Luật THẬT của việc quét: (1) chỉ website thuộc allowlist = đang active; host kiểm bằng UrlSafetyService (chặn IP nội bộ/
 * metadata cloud); (2) tối đa 1 lần quét/website mỗi 6 giờ; (3) chỉ approver trở lên được quét lại / tạo brief.
 * Sau này mốc quét lưu ở bảng audit_runs; hiện lấy từ DemoStore.
 */
final class ScanGuard
{
    public const COOLDOWN_HOURS = 6;

    /** @return array{ok: bool, code: ?string, reason: ?string, next_at: ?CarbonImmutable} */
    public static function check(Website $site, ?CarbonInterface $lastScanAt, ?User $user = null): array
    {
        if ($user !== null && ! self::canAct($user)) {
            return self::no('permission', 'Chỉ approver trở lên được quét lại.');
        }
        if ($site->status !== 'active') {
            return self::no('not_allowlisted', 'Website không ở trạng thái active nên không thuộc danh sách được phép quét. Chỉ quản trị viên thêm được website vào danh sách.');
        }
        if ($e = self::hostProblem($site)) {
            return self::no('not_allowlisted', 'Domain không được phép quét: '.$e);
        }
        if ($lastScanAt !== null) {
            $next = CarbonImmutable::instance($lastScanAt)->addHours(self::COOLDOWN_HOURS);
            if (CarbonImmutable::now()->lt($next)) {
                return self::no('rate_limited', 'Mỗi website tối đa 1 lần quét mỗi '.self::COOLDOWN_HOURS.' giờ để không làm chậm site.', $next);
            }
        }

        return ['ok' => true, 'code' => null, 'reason' => null, 'next_at' => null];
    }

    public static function canAct(User $user): bool
    {
        return $user->is_active && $user->isApprover();
    }

    /** Lỗi của host (SSRF/allowlist) hoặc null nếu hợp lệ. */
    public static function hostProblem(Website $site): ?string
    {
        $host = parse_url((string) $site->base_url, PHP_URL_HOST);
        if (! $host) {
            return 'URL của website không hợp lệ.';
        }
        try {
            app(UrlSafetyService::class)->validateUrl((string) $site->base_url, [$host]);
        } catch (InvalidArgumentException $e) {
            // MINH HỌA: chưa có crawler thật nên không có request nào được gửi; domain mẫu không phân giải được DNS vẫn cho xem luồng.
            // Crawler thật (task 1.1) sẽ chặn trường hợp này.
            if (DemoMode::enabled() && str_starts_with($e->getMessage(), 'Không thể phân giải')) {
                return null;
            }

            return $e->getMessage();
        }

        return null;
    }

    /** @return array{ok: bool, code: ?string, reason: ?string, next_at: ?CarbonImmutable} */
    private static function no(string $code, string $reason, ?CarbonImmutable $next = null): array
    {
        return ['ok' => false, 'code' => $code, 'reason' => $reason, 'next_at' => $next];
    }
}
