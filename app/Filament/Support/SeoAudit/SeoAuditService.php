<?php

namespace App\Filament\Support\SeoAudit;

use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Demo\DemoStore;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;

/** Điểm vào duy nhất của màn Audit: đọc kết quả, quét lại (qua ScanGuard), lưu brief. */
final class SeoAuditService
{
    public const BRIEFS_KEY = 'seoaudit.briefs';

    public function __construct(private readonly SeoAuditSource $source) {}

    public static function make(): self
    {
        return new self(new DemoSeoAuditSource());
    }

    /** @return array{status: string, run: ?array<string, mixed>} */
    public function snapshot(Website $site): array
    {
        return $this->source->latest($site);
    }

    /** @return array{ok: bool, code: ?string, reason: ?string, next_at: ?CarbonImmutable} */
    public function canScan(Website $site, ?User $user = null): array
    {
        if (! DemoMode::enabled()) {
            return ['ok' => false, 'code' => 'crawler_off', 'reason' => 'Crawler (task 1.1) chưa được triển khai nên chưa quét được website nào.', 'next_at' => null];
        }

        return ScanGuard::check($site, $this->source->lastScanAt($site), $user);
    }

    /** Quét lại; ném ScanBlocked nếu luật không cho. @return array<string, mixed> kết quả mới */
    public function rescan(Website $site, User $user): array
    {
        $g = $this->canScan($site, $user);
        if (! $g['ok']) {
            throw new ScanBlocked((string) $g['reason']);
        }
        $this->source->scan($site, $user);

        return $this->source->latest($site);
    }

    /**
     * @param  array{title: string, body: string}  $brief
     * @return array<string, mixed>
     */
    public function saveBrief(Website $site, User $user, string $issueType, int $urls, array $brief): array
    {
        $rec = [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'website_id' => $site->id,
            'website' => $site->name,
            'issue_type' => $issueType,
            'urls' => $urls,
            'title' => $brief['title'],
            'body' => $brief['body'],
            'created_by' => $user->id,
            'created_by_name' => $user->name,
            'created_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
        $all = $this->briefs();
        array_unshift($all, $rec);
        DemoStore::put(self::BRIEFS_KEY, $all);

        return $rec;
    }

    /** @return list<array<string, mixed>> */
    public function briefs(?Website $site = null): array
    {
        $all = (array) DemoStore::get(self::BRIEFS_KEY, []);

        return array_values($site ? array_filter($all, fn ($b) => $b['website_id'] === $site->id) : $all);
    }
}
