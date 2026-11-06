<?php

namespace Tests\Unit;

use App\Services\Content\AiPaddingFilter;
use PHPUnit\Framework\TestCase;

class AiPaddingFilterTest extends TestCase
{
    protected AiPaddingFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filter = new AiPaddingFilter();
    }

    public function test_it_detects_banned_ai_padding_phrases(): void
    {
        $dirtyText = "<p>Trong thời đại số, không thể phủ nhận rằng chuyển đổi số đóng vai trò vô cùng quan trọng.</p>";
        $result = $this->filter->detectPadding($dirtyText);

        $this->assertFalse($result['is_clean']);
        $this->assertEquals(3, $result['found_count']);
        $this->assertArrayHasKey('trong thời đại số', $result['matches']);
        $this->assertArrayHasKey('không thể phủ nhận rằng', $result['matches']);
        $this->assertArrayHasKey('đóng vai trò vô cùng quan trọng', $result['matches']);
    }

    public function test_it_returns_clean_for_high_quality_content(): void
    {
        $cleanText = "<p>Máy dập viên tự động vận hành ở tốc độ 2.500 vòng/phút, giảm 15% hao hụt nguyên liệu theo tiêu chuẩn GMP.</p>";
        $result = $this->filter->detectPadding($cleanText);

        $this->assertTrue($result['is_clean']);
        $this->assertEquals(0, $result['found_count']);
        $this->assertEmpty($result['matches']);
    }

    public function test_it_calculates_information_density_score(): void
    {
        $content = "<h2>Nguyên lý vận hành</h2>"
            . "<p>Hệ thống nén áp lực 80 kN với công suất 5.5 kW giúp đảm bảo độ cứng viên đạt 12 kg. Máy đạt năng suất 120.000 viên/giờ với độ sai số dưới 1.5%.</p>"
            . "<ul><li>Bước 1: Nạp hạt</li><li>Bước 2: Nén sơ cấp</li><li>Bước 3: Nén chính</li></ul>";

        $analysis = $this->filter->calculateInformationDensity($content);

        $this->assertGreaterThanOrEqual(40, $analysis['score']);
        $this->assertGreaterThanOrEqual(2, $analysis['metric_count']);
        $this->assertEquals(3, $analysis['structure_elements']);
        $this->assertEquals(0, $analysis['padding_count']);
    }

    public function test_it_cleans_padding_phrases(): void
    {
        $input = "Như chúng ta đã biết, máy móc cần bảo dưỡng định kỳ.";
        $cleaned = $this->filter->cleanPadding($input);

        $this->assertStringNotContainsString('như chúng ta đã biết', mb_strtolower($cleaned, 'UTF-8'));
    }
}
