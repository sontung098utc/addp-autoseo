<?php

namespace Tests\Unit;

use App\Services\Crawler\HtmlSeoExtractor;
use PHPUnit\Framework\TestCase;

class HtmlSeoExtractorTest extends TestCase
{
    protected HtmlSeoExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new HtmlSeoExtractor();
    }

    public function test_it_extracts_and_scores_fully_optimized_html(): void
    {
        $html = <<<HTML
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quy Trình Sản Xuất Dược Phẩm Đạt Chuẩn GMP Quốc Tế</title>
    <meta name="description" content="Hướng dẫn chi tiết toàn bộ các bước trong quy trình sản xuất dược phẩm đạt chuẩn GMP-WHO giúp nâng cao hiệu quả và đảm bảo chất lượng.">
    <link rel="canonical" href="https://example.com/quy-trinh-gmp">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Article",
        "headline": "Quy Trình Sản Xuất Dược Phẩm"
    }
    </script>
</head>
<body>
    <h1>Quy Trình Sản Xuất Dược Phẩm Đạt Chuẩn GMP</h1>
    <h2>1. Tiếp nhận và kiểm nghiệm nguyên liệu</h2>
    <p>Kiểm tra kỹ lưỡng các hoạt chất trước khi đưa vào sản xuất.</p>
    <img src="/images/nha-may.webp" alt="Dây chuyền sản xuất nhà máy đạt chuẩn GMP" width="800" height="450">
    <h2>2. Bào chế và ép vỉ</h2>
    <p>Tham khảo thêm tại <a href="/san-pham">danh mục sản phẩm</a> hoặc <a href="https://who.int" rel="nofollow">tiêu chuẩn WHO</a>.</p>
</body>
</html>
HTML;

        $result = $this->extractor->extract($html, 'https://example.com/quy-trinh-gmp', 200);

        $this->assertEquals(200, $result['http_status']);
        $this->assertEquals('Quy Trình Sản Xuất Dược Phẩm Đạt Chuẩn GMP Quốc Tế', $result['title']);
        $this->assertNotEmpty($result['meta_description']);
        $this->assertEquals('https://example.com/quy-trinh-gmp', $result['canonical_url']);
        $this->assertEquals('Quy Trình Sản Xuất Dược Phẩm Đạt Chuẩn GMP', $result['h1']);
        $this->assertEquals(1, $result['h1_count']);
        $this->assertEquals(2, $result['headings_summary']['h2']);
        $this->assertEquals(1, $result['images_count']);
        $this->assertEquals(0, $result['missing_alt_count']);
        $this->assertEquals(1, $result['internal_links_count']);
        $this->assertEquals(1, $result['external_links_count']);
        $this->assertTrue($result['has_schema']);
        $this->assertContains('Article', $result['schema_types']);
        $this->assertGreaterThanOrEqual(90, $result['seo_score']);
        $this->assertEmpty($result['issues']);
    }

    public function test_it_flags_critical_seo_issues(): void
    {
        $poorHtml = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <!-- Thiếu title, description, canonical, schema -->
</head>
<body>
    <!-- Có 2 thẻ H1 -->
    <h1>Tiêu đề 1</h1>
    <h1>Tiêu đề 2</h1>
    <!-- Ảnh thiếu alt -->
    <img src="banner.jpg">
    <img src="photo.jpg">
</body>
</html>
HTML;

        $result = $this->extractor->extract($poorHtml, 'https://example.com/poor-page', 200);

        $this->assertContains('missing_title', $result['issues']);
        $this->assertContains('missing_meta_description', $result['issues']);
        $this->assertContains('missing_canonical_url', $result['issues']);
        $this->assertContains('multiple_h1', $result['issues']);
        $this->assertContains('missing_alt:2', $result['issues']);
        $this->assertContains('missing_json_ld_schema', $result['issues']);
        $this->assertLessThan(50, $result['seo_score']);
    }

    public function test_it_scores_zero_for_non_200_http_status(): void
    {
        $result = $this->extractor->extract('<html><body>404 Not Found</body></html>', 'https://example.com/broken', 404);

        $this->assertEquals(404, $result['http_status']);
        $this->assertEquals(0, $result['seo_score']);
        $this->assertContains('http_error_404', $result['issues']);
    }
}
