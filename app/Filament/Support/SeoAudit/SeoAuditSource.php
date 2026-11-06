<?php

namespace App\Filament\Support\SeoAudit;

use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;

/**
 * Nguồn dữ liệu audit. Bản hiện tại: DemoSeoAuditSource. Khi crawler 1.1 + bảng audit_runs/audit_pages/audit_issues có,
 * thêm bản thật và đổi SeoAuditService::make(); giao diện không đổi.
 */
interface SeoAuditSource
{
    /**
     * @return array{status: string, run: ?array<string, mixed>}  status: 'completed' | 'none' | 'crawler_off'
     */
    public function latest(Website $site): array;

    public function lastScanAt(Website $site): ?CarbonImmutable;

    /** Ghi một lần quét mới (đã qua ScanGuard). */
    public function scan(Website $site, User $user): void;
}
