<?php

namespace App\Services\Audit;

use App\Filament\Support\SeoAudit\DbSeoAuditSource;
use App\Filament\Support\SeoAudit\ScanBlocked;
use App\Filament\Support\SeoAudit\ScanGuard;
use App\Models\SeoAudit;
use App\Models\User;
use App\Models\Website;
use App\Services\Crawler\SeoAuditService as Crawler;
use Throwable;

/**
 * Quét THẬT từ giao diện: cùng đường với lệnh autoseo:audit-website (Crawler\SeoAuditService::runFullAudit),
 * cùng luật quyền/allowlist/SSRF (ScanGuard + UrlSafetyService). Chạy đồng bộ nên số trang bị chặn trần.
 */
final class RealScanService
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    public const TIME_BUDGET_S = 180;

    public static function clamp(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
    }

    public function __construct(private readonly ?Crawler $crawler = null) {}

    /** @throws ScanBlocked */
    public function run(Website $site, User $user, int $limit): SeoAudit
    {
        $g = ScanGuard::check($site, (new DbSeoAuditSource())->lastScanAt($site), $user);
        if (! $g['ok']) {
            throw new ScanBlocked((string) $g['reason']);
        }
        @set_time_limit(self::TIME_BUDGET_S);
        try {
            return ($this->crawler ?? app(Crawler::class))->runFullAudit($site, null, self::clamp($limit));
        } catch (Throwable $e) {
            throw new ScanBlocked('Quét không hoàn tất: '.$e->getMessage());
        }
    }
}
