<?php

namespace App\Services\Crawler;

use App\Models\Website;
use App\Services\Security\UrlSafetyService;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Throwable;

class SitemapCrawlerService
{
    protected const USER_AGENT = 'ADDP-AutoSEO-Crawler/1.0 (+https://company.vn/bot)';
    protected const MAX_SITEMAP_DEPTH = 2;
    protected const EXCLUDED_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp', 'ico',
        'pdf', 'zip', 'rar', 'tar', 'gz', 'doc', 'docx', 'xls', 'xlsx',
        'mp4', 'mp3', 'avi', 'mov', 'wmv',
        'css', 'js', 'json', 'xml',
    ];

    public function __construct(
        protected UrlSafetyService $urlSafetyService,
        protected ?HttpClient $httpClient = null
    ) {
        $this->httpClient = $httpClient ?: new HttpClient([
            'timeout' => 15,
            'connect_timeout' => 5,
            'allow_redirects' => ['max' => 3],
            'headers' => [
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/xml,text/xml,text/plain,*/*',
            ],
        ]);
    }

    /**
     * Khám phá và trích xuất danh sách URL từ sitemap của website
     *
     * @param Website $website
     * @param string|null $customSitemapUrl Tùy chọn chỉ định trực tiếp URL sitemap
     * @param int $maxUrls Giới hạn số lượng URL tối đa thu thập
     * @return Collection
     */
    public function discoverUrls(Website $website, ?string $customSitemapUrl = null, int $maxUrls = 500): Collection
    {
        $baseUrl = rtrim($website->base_url, '/');
        $baseHost = parse_url($baseUrl, PHP_URL_HOST) ?? '';

        $sitemapUrls = $customSitemapUrl
            ? [$customSitemapUrl]
            : $this->findSitemapLocations($baseUrl);

        $discoveredUrls = collect();
        $processedSitemaps = [];

        foreach ($sitemapUrls as $sitemapUrl) {
            $this->crawlSitemapRecursive(
                $sitemapUrl,
                $baseHost,
                $discoveredUrls,
                $processedSitemaps,
                0,
                $maxUrls
            );

            if ($discoveredUrls->count() >= $maxUrls) {
                break;
            }
        }

        return $discoveredUrls->take($maxUrls)->values();
    }

    /**
     * Dò tìm vị trí sitemap từ robots.txt hoặc các đường dẫn phổ biến
     */
    public function findSitemapLocations(string $baseUrl): array
    {
        $locations = [];

        // 1. Thử đọc robots.txt
        $robotsUrl = "{$baseUrl}/robots.txt";
        try {
            if ($this->urlSafetyService->validateUrl($robotsUrl)) {
                $response = $this->httpClient->get($robotsUrl);
                if ($response->getStatusCode() === 200) {
                    $robotsTxt = (string) $response->getBody();
                    preg_match_all('/^sitemap:\s*(https?:\/\/[^\s\r\n]+)/im', $robotsTxt, $matches);
                    if (! empty($matches[1])) {
                        $locations = array_merge($locations, $matches[1]);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::debug("Không tìm thấy sitemap trong robots.txt ({$robotsUrl}): " . $e->getMessage());
        }

        // 2. Dự phòng các đường dẫn sitemap mặc định nếu robots.txt không có
        if (empty($locations)) {
            $locations = [
                "{$baseUrl}/sitemap.xml",
                "{$baseUrl}/sitemap_index.xml",
                "{$baseUrl}/wp-sitemap.xml",
            ];
        }

        return array_unique($locations);
    }

    /**
     * Đệ quy thu thập sitemap index hoặc urlset
     */
    protected function crawlSitemapRecursive(
        string $sitemapUrl,
        string $baseHost,
        Collection &$discoveredUrls,
        array &$processedSitemaps,
        int $depth,
        int $maxUrls
    ): void {
        if ($depth > self::MAX_SITEMAP_DEPTH || in_array($sitemapUrl, $processedSitemaps, true) || $discoveredUrls->count() >= $maxUrls) {
            return;
        }

        $processedSitemaps[] = $sitemapUrl;

        try {
            // Kiểm tra bảo mật chống SSRF
            $this->urlSafetyService->validateUrl($sitemapUrl);

            $response = $this->httpClient->get($sitemapUrl);
            if ($response->getStatusCode() !== 200) {
                return;
            }

            $xmlString = (string) $response->getBody();
            if (empty(trim($xmlString))) {
                return;
            }

            libxml_use_internal_errors(true);
            $xml = @simplexml_load_string($xmlString);
            libxml_clear_errors();

            if (! $xml instanceof SimpleXMLElement) {
                return;
            }

            // Phân biệt sitemapindex (chứa các sitemap con) và urlset (chứa các url bài viết)
            $rootName = strtolower($xml->getName());

            if ($rootName === 'sitemapindex') {
                foreach ($xml->sitemap as $childSitemap) {
                    $childLoc = trim((string) $childSitemap->loc);
                    if (! empty($childLoc)) {
                        $this->crawlSitemapRecursive(
                            $childLoc,
                            $baseHost,
                            $discoveredUrls,
                            $processedSitemaps,
                            $depth + 1,
                            $maxUrls
                        );
                    }
                }
            } elseif ($rootName === 'urlset') {
                foreach ($xml->url as $urlEntry) {
                    $loc = trim((string) $urlEntry->loc);
                    if ($this->isValidPageUrl($loc, $baseHost) && ! $discoveredUrls->contains('url', $loc)) {
                        $discoveredUrls->push([
                            'url' => $loc,
                            'lastmod' => ! empty($urlEntry->lastmod) ? (string) $urlEntry->lastmod : null,
                            'changefreq' => ! empty($urlEntry->changefreq) ? (string) $urlEntry->changefreq : null,
                            'priority' => ! empty($urlEntry->priority) ? (float) $urlEntry->priority : null,
                        ]);

                        if ($discoveredUrls->count() >= $maxUrls) {
                            break;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning("Lỗi phân tích sitemap {$sitemapUrl}: " . $e->getMessage());
        }
    }

    /**
     * Kiểm tra URL có hợp lệ và thuộc trang HTML của website hay không
     */
    public function isValidPageUrl(string $url, string $expectedHost): bool
    {
        if (empty($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if (! empty($expectedHost) && $host !== $expectedHost && ! str_ends_with($host, '.' . $expectedHost)) {
            return false;
        }

        $path = $parts['path'] ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return ! in_array($ext, self::EXCLUDED_EXTENSIONS, true);
    }
}
