<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Crawler\SeoAuditService;
use Illuminate\Console\Command;

class AuditWebsiteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'autoseo:audit-website 
                            {website_id : ID của website trong cơ sở dữ liệu}
                            {--url= : Tùy chọn chỉ kiểm tra một URL cụ thể}
                            {--sitemap= : Tùy chọn chỉ định đường dẫn sitemap tùy chỉnh}
                            {--limit=50 : Giới hạn số lượng URL quét}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Thu thập sitemap và đánh giá các chỉ số On-page SEO của website';

    /**
     * Execute the console command.
     */
    public function handle(SeoAuditService $auditService): int
    {
        $websiteId = $this->argument('website_id');
        $website = Website::find($websiteId);

        if (! $website) {
            $this->error("Không tìm thấy website với ID: {$websiteId}");
            return self::FAILURE;
        }

        $specificUrl = $this->option('url');
        $sitemapUrl = $this->option('sitemap');
        $limit = (int) $this->option('limit');

        $this->info("Bắt đầu thu thập & Audit SEO cho website: {$website->name} ({$website->base_url})");

        if ($specificUrl) {
            $this->info("Đang kiểm tra URL cụ thể: {$specificUrl}");
            $crawled = $auditService->auditSingleUrl($website, $specificUrl);

            $this->table(
                ['Chỉ số SEO', 'Giá trị bóc tách được'],
                [
                    ['URL', $crawled->url],
                    ['Mã phản hồi HTTP', $crawled->http_status],
                    ['Điểm SEO Health', "{$crawled->seo_score} / 100"],
                    ['Thẻ Title', $crawled->title ?: '<Trống>'],
                    ['Meta Description', $crawled->meta_description ?: '<Trống>'],
                    ['Thẻ H1 (Số lượng)', "{$crawled->h1} ({$crawled->h1_count})"],
                    ['Số lượng ảnh / Thiếu Alt', "{$crawled->images_count} / {$crawled->missing_alt_count}"],
                    ['Liên kết nội bộ / Ngoại bộ', "{$crawled->internal_links_count} / {$crawled->external_links_count}"],
                    ['Có dữ liệu có cấu trúc Schema', $crawled->has_schema ? 'Có' : 'Không'],
                    ['Các vấn đề phát hiện', implode(', ', $crawled->issues ?? []) ?: 'Tối ưu tốt'],
                ]
            );

            return self::SUCCESS;
        }

        $this->info("Đang quét sitemap và audit On-page (tối đa {$limit} URLs)...");
        $audit = $auditService->runFullAudit($website, $sitemapUrl, $limit);

        $this->newLine();
        $this->info("=== KẾT QUẢ AUDIT SEO TỔNG THỂ ===");
        $this->table(
            ['Tổng URL quét', 'Điểm SEO trung bình', 'Tốt (>=80)', 'Cảnh báo (50-79)', 'Lỗi (<50)', 'Trạng thái'],
            [
                [
                    $audit->total_urls_scanned,
                    "{$audit->average_seo_score} / 100",
                    $audit->healthy_urls_count,
                    $audit->warning_urls_count,
                    $audit->error_urls_count,
                    strtoupper($audit->status),
                ],
            ]
        );

        if (! empty($audit->summary_issues)) {
            $this->newLine();
            $this->info("--- Các vấn đề SEO cần khắc phục phổ biến ---");
            $rows = [];
            foreach ($audit->summary_issues as $issue => $count) {
                $rows[] = [$issue, $count];
            }
            $this->table(['Vấn đề SEO', 'Số trang bị ảnh hưởng'], $rows);
        }

        return self::SUCCESS;
    }
}
