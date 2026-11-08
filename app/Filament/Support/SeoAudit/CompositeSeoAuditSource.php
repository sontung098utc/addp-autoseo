<?php

namespace App\Filament\Support\SeoAudit;

use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;

/**
 * Ưu tiên kết quả quét THẬT của crawler; chỉ khi website chưa có kết quả thật mới dùng nguồn mẫu (và nguồn mẫu tự
 * tắt ở production). Không bao giờ trộn số mẫu vào website đã có dữ liệu thật.
 */
final class CompositeSeoAuditSource implements SeoAuditSource
{
    public function __construct(private readonly DbSeoAuditSource $real, private readonly SeoAuditSource $fallback) {}

    public function latest(Website $site): array
    {
        return $this->real->hasResult($site) ? $this->real->latest($site) : $this->fallback->latest($site);
    }

    public function lastScanAt(Website $site): ?CarbonImmutable
    {
        return $this->real->hasResult($site) ? $this->real->lastScanAt($site) : $this->fallback->lastScanAt($site);
    }

    public function scan(Website $site, User $user): void
    {
        $this->real->hasResult($site) ? $this->real->scan($site, $user) : $this->fallback->scan($site, $user);
    }
}
