<?php

namespace App\Filament\Support\SeoAudit;

use App\Models\CrawledUrl;
use App\Models\SeoAudit;
use App\Models\User;
use App\Models\Website;
use Carbon\CarbonImmutable;

/**
 * AG-U05: nguồn dữ liệu THẬT cho màn Audit, đọc kết quả của crawler 1.1 (bảng seo_audits, crawled_urls).
 * Chỉ đọc. Không quét: quét từ giao diện cần job nền (AG-B00, Dev1) nên scan() luôn bị chặn ở đây.
 *
 * Giới hạn trung thực: crawler không lưu HTML nên không có phát hiện "trang mỏng", "trùng title/meta",
 * "noindex" hay liên kết gãy giữa các trang; các mã crawler chưa có trong danh mục được đếm riêng (run['unmapped']).
 * crawled_urls không có khóa theo lần quét: trang của một lần quét = các trang có last_crawled_at nằm trong [started_at, completed_at].
 */
final class DbSeoAuditSource implements SeoAuditSource
{
    public function latest(Website $site): array
    {
        $audit = $this->lastCompleted($site);
        if ($audit === null) {
            return ['status' => 'none', 'run' => null];
        }

        $rows = $this->pagesOf($audit);
        $findings = [];
        $unmapped = [];
        foreach ($rows as $row) {
            foreach ((array) $row->issues as $code) {
                $f = CrawlerIssueMap::finding((string) $code, $row);
                if ($f !== null) {
                    $findings[] = $f;
                } elseif (CrawlerIssueMap::type((string) $code) === null) {
                    $label = CrawlerIssueMap::noCatalogLabel((string) $code);
                    $unmapped[$label] = ($unmapped[$label] ?? 0) + 1;
                }
            }
        }

        $prev = $this->previousCounts($audit);
        $groups = AuditReport::groups($findings, $prev['counts']);
        $total = $rows->count();
        $start = $audit->started_at;
        $end = $audit->completed_at;
        arsort($unmapped);

        return ['status' => 'completed', 'run' => [
            'at' => $end ? CarbonImmutable::instance($end)->utc() : null,
            'prev_at' => $prev['at'],
            'urls_crawled' => $total,
            'urls_total' => (int) $audit->total_urls_scanned,
            'ok_200' => $rows->where('http_status', 200)->count(),
            'not_found' => $rows->where('http_status', 404)->count(),
            'duration_s' => $start && $end ? max(0, $start->diffInSeconds($end)) : 0,
            'source' => 'Quét sitemap bằng crawler (task 1.1)',
            'triggered_by' => 'Hệ thống (lệnh autoseo:audit-website)',
            'sample' => false,
            'findings' => $findings,
            'groups' => $groups,
            'summary' => AuditReport::summary($findings, $groups),
            'previous_total' => $prev['counts'] === null ? null : array_sum($prev['counts']),
            'score_method' => CrawlerIssueMap::SCORE_METHOD,
            'average_score' => (float) $audit->average_seo_score,
            'unmapped' => $unmapped,
        ]];
    }

    public function lastScanAt(Website $site): ?CarbonImmutable
    {
        $at = $this->lastCompleted($site)?->completed_at;

        return $at ? CarbonImmutable::instance($at)->utc() : null;
    }

    public function scan(Website $site, User $user): void
    {
        throw new ScanBlocked('Quét lại từ giao diện cần job nền (AG-B00, Dev1). Hiện chỉ xem được kết quả của lần quét gần nhất (lệnh autoseo:audit-website).');
    }

    public function hasResult(Website $site): bool
    {
        return $this->lastCompleted($site) !== null;
    }

    public function lastCompleted(Website $site): ?SeoAudit
    {
        return SeoAudit::query()->where('website_id', $site->id)->where('status', 'completed')->orderByDesc('completed_at')->orderByDesc('id')->first();
    }

    /** @return \Illuminate\Support\Collection<int, CrawledUrl> */
    public function pagesOf(SeoAudit $audit)
    {
        $q = CrawledUrl::query()->where('website_id', $audit->website_id);
        if ($audit->started_at && $audit->completed_at) {
            $q->whereBetween('last_crawled_at', [$audit->started_at->copy()->subSecond(), $audit->completed_at->copy()->addSecond()]);
        }

        return $q->orderBy('url')->get();
    }

    /** Số URL mỗi loại của lần quét trước (từ summary_issues); chỉ gồm loại suy ra được từ mã crawler. @return array{counts: ?array<string,int>, at: ?CarbonImmutable} */
    private function previousCounts(SeoAudit $audit): array
    {
        $prev = SeoAudit::query()->where('website_id', $audit->website_id)->where('status', 'completed')
            ->where('id', '<', $audit->id)->orderByDesc('id')->first();
        if ($prev === null) {
            return ['counts' => null, 'at' => null];
        }

        $counts = [];
        foreach ((array) $prev->summary_issues as $code => $n) {
            $type = CrawlerIssueMap::type((string) $code);
            if ($type !== null) {
                $counts[$type] = ($counts[$type] ?? 0) + (int) $n;
            }
        }

        return ['counts' => $counts, 'at' => $prev->completed_at ? CarbonImmutable::instance($prev->completed_at)->utc() : null];
    }
}
