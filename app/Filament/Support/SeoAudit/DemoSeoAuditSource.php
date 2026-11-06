<?php

namespace App\Filament\Support\SeoAudit;

use App\Filament\Support\Demo\DemoMode;
use App\Filament\Support\Demo\DemoStore;
use App\Filament\Support\Demo\SeoAuditDemoData;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;

/**
 * MINH HỌA: thay bước thu thập bằng bộ trang mẫu (SeoAuditDemoData); PHÂN TÍCH bằng OnPageAnalyzer thật.
 * Mốc quét, mức quét lưu ở DemoStore (khóa seoaudit.site.{id}.*); sau này lưu bảng audit_runs.
 */
final class DemoSeoAuditSource implements SeoAuditSource
{
    public function latest(Website $site): array
    {
        if (! DemoMode::enabled()) {
            return ['status' => 'crawler_off', 'run' => null];
        }
        $this->seed($site);
        $level = (int) DemoStore::get($this->k($site, 'level'), 1);
        $analyzer = new OnPageAnalyzer();

        $pages = SeoAuditDemoData::pages($level);
        $findings = $analyzer->analyze($pages);

        $prevCounts = null;
        $prevAt = null;
        if ($level >= 1) {
            $prevCounts = AuditReport::counts(AuditReport::groups($analyzer->analyze(SeoAuditDemoData::pages($level - 1))));
            $prevAt = $this->at($site, 'prev_at');
        }
        $groups = AuditReport::groups($findings, $prevCounts);
        $crawled = count($pages);

        return ['status' => 'completed', 'run' => [
            'at' => $this->at($site, 'at'),
            'prev_at' => $prevAt,
            'urls_crawled' => $crawled,
            'urls_total' => $crawled,
            'ok_200' => count(array_filter($pages, fn ($p) => $p['status'] === 200)),
            'not_found' => count(array_filter($pages, fn ($p) => $p['status'] === 404)),
            'duration_s' => $crawled * 22,
            'source' => 'bộ trang mẫu (thay cho /sitemap_index.xml)',
            'triggered_by' => (string) DemoStore::get($this->k($site, 'by'), 'Hệ thống (dữ liệu mẫu)'),
            'sample' => true,
            'findings' => $findings,
            'groups' => $groups,
            'summary' => AuditReport::summary($findings, $groups),
            'previous_total' => $prevCounts === null ? null : array_sum($prevCounts),
        ]];
    }

    public function lastScanAt(Website $site): ?CarbonImmutable
    {
        if (! DemoMode::enabled()) {
            return null;
        }
        $this->seed($site);

        return $this->at($site, 'at');
    }

    public function scan(Website $site, User $user): void
    {
        $this->seed($site);
        DemoStore::put($this->k($site, 'prev_at'), (string) DemoStore::get($this->k($site, 'at')));
        DemoStore::put($this->k($site, 'level'), (int) DemoStore::get($this->k($site, 'level'), 1) + 1);
        DemoStore::put($this->k($site, 'at'), CarbonImmutable::now('UTC')->toIso8601String());
        DemoStore::put($this->k($site, 'by'), $user->name);
    }

    private function seed(Website $site): void
    {
        if (DemoStore::get($this->k($site, 'level')) !== null) {
            return;
        }
        $now = CarbonImmutable::now('UTC');
        DemoStore::put($this->k($site, 'level'), 1);
        DemoStore::put($this->k($site, 'at'), $now->subDay()->toIso8601String());
        DemoStore::put($this->k($site, 'prev_at'), $now->subDays(8)->toIso8601String());
        DemoStore::put($this->k($site, 'by'), 'Hệ thống (dữ liệu mẫu)');
    }

    private function at(Website $site, string $key): ?CarbonImmutable
    {
        $v = DemoStore::get($this->k($site, $key));

        return $v ? CarbonImmutable::parse((string) $v)->utc() : null;
    }

    private function k(Website $site, string $key): string
    {
        return "seoaudit.site.{$site->id}.{$key}";
    }
}
