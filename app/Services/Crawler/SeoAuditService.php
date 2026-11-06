<?php

namespace App\Services\Crawler;

use App\Models\CrawledUrl;
use App\Models\SeoAudit;
use App\Models\Website;
use App\Services\Security\UrlSafetyService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

class SeoAuditService
{
    protected const USER_AGENT = 'ADDP-AutoSEO-Crawler/1.0 (+https://company.vn/bot)';

    public function __construct(
        protected SitemapCrawlerService $sitemapCrawler,
        protected HtmlSeoExtractor $htmlExtractor,
        protected UrlSafetyService $urlSafetyService,
        protected ?HttpClient $httpClient = null
    ) {
        $this->httpClient = $httpClient ?: new HttpClient([
            'timeout' => 15,
            'connect_timeout' => 5,
            'allow_redirects' => ['max' => 3],
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml,*/*',
            ],
        ]);
    }

    /**
     * Thu thập và đánh giá On-page SEO cho 1 trang cụ thể
     */
    public function auditSingleUrl(Website $website, string $url): CrawledUrl
    {
        // 1. Kiểm tra an toàn chống SSRF
        $this->urlSafetyService->validateUrl($url);

        $httpStatus = 200;
        $html = '';

        try {
            $response = $this->httpClient->get($url);
            $httpStatus = $response->getStatusCode();
            $html = (string) $response->getBody();
        } catch (RequestException $e) {
            $httpStatus = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 500;
            $html = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
        } catch (Throwable $e) {
            $httpStatus = 500;
            $html = '';
            Log::warning("Lỗi kết nối khi audit URL {$url}: " . $e->getMessage());
        }

        // 2. Bóc tách và chấm điểm SEO
        $analysis = $this->htmlExtractor->extract($html, $url, $httpStatus);

        // 3. Lưu hoặc cập nhật vào bảng crawled_urls
        return CrawledUrl::updateOrCreate(
            [
                'website_id' => $website->id,
                'url' => $url,
            ],
            [
                'http_status' => $analysis['http_status'],
                'title' => $analysis['title'],
                'meta_description' => $analysis['meta_description'],
                'canonical_url' => $analysis['canonical_url'],
                'h1' => $analysis['h1'],
                'h1_count' => $analysis['h1_count'],
                'headings_summary' => $analysis['headings_summary'],
                'images_count' => $analysis['images_count'],
                'missing_alt_count' => $analysis['missing_alt_count'],
                'internal_links_count' => $analysis['internal_links_count'],
                'external_links_count' => $analysis['external_links_count'],
                'has_schema' => $analysis['has_schema'],
                'schema_types' => $analysis['schema_types'],
                'seo_score' => $analysis['seo_score'],
                'issues' => $analysis['issues'],
                'last_crawled_at' => now(),
            ]
        );
    }

    /**
     * Chạy quy trình thu thập và Audit toàn bộ website theo sitemap
     */
    public function runFullAudit(Website $website, ?string $sitemapUrl = null, int $limit = 50): SeoAudit
    {
        $audit = SeoAudit::create([
            'website_id' => $website->id,
            'audit_type' => $sitemapUrl ? 'sample' : 'full_sitemap',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            // 1. Dò tìm danh sách URL từ sitemap
            $urlsToAudit = $this->sitemapCrawler->discoverUrls($website, $sitemapUrl, $limit);

            // Nếu không tìm thấy URL nào từ sitemap, kiểm tra ít nhất trang chủ base_url
            if ($urlsToAudit->isEmpty()) {
                $urlsToAudit = collect([['url' => $website->base_url]]);
            }

            $totalScanned = 0;
            $healthyCount = 0;
            $warningCount = 0;
            $errorCount = 0;
            $scoreSum = 0;
            $summaryIssues = [];

            foreach ($urlsToAudit as $entry) {
                $url = is_array($entry) ? $entry['url'] : (string) $entry;
                $crawledRecord = $this->auditSingleUrl($website, $url);

                $totalScanned++;
                $score = $crawledRecord->seo_score;
                $scoreSum += $score;

                if ($score >= 80) {
                    $healthyCount++;
                } elseif ($score >= 50) {
                    $warningCount++;
                } else {
                    $errorCount++;
                }

                if (! empty($crawledRecord->issues)) {
                    foreach ($crawledRecord->issues as $issue) {
                        $baseIssue = explode(':', $issue)[0];
                        $summaryIssues[$baseIssue] = ($summaryIssues[$baseIssue] ?? 0) + 1;
                    }
                }
            }

            $averageScore = $totalScanned > 0 ? round($scoreSum / $totalScanned, 2) : 0;

            $audit->update([
                'total_urls_scanned' => $totalScanned,
                'healthy_urls_count' => $healthyCount,
                'warning_urls_count' => $warningCount,
                'error_urls_count' => $errorCount,
                'average_seo_score' => $averageScore,
                'summary_issues' => $summaryIssues,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return $audit;
        } catch (Throwable $e) {
            $audit->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }
}
