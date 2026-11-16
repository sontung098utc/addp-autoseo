<?php

namespace Tests\Unit;

use App\Services\Content\Quality\QualityContext;
use PHPUnit\Framework\TestCase;

class KeywordCoreMatchTest extends TestCase
{
    public function test_tu_khoa_ngan_phai_khop_nguyen_cum(): void
    {
        $this->assertTrue(QualityContext::keywordIn('bài nói về huyết áp thấp ở người lớn', 'huyết áp thấp'));
        $this->assertFalse(QualityContext::keywordIn('huyết áp ở người thấp lớn', 'huyết áp thấp'));
    }

    public function test_tu_khoa_la_cau_hoi_dai_khop_theo_tu_cot_loi(): void
    {
        $kw = 'máy dập viên một chày và nhiều chày khác nhau thế nào';
        $this->assertFalse(str_contains('máy một chày dập từng viên, máy nhiều chày dập liên tục', $kw));
        $this->assertTrue(QualityContext::keywordIn('máy một chày dập từng viên, máy nhiều chày dập liên tục', $kw), 'Đủ từ cốt lõi (máy, dập, viên, chày, nhiều).');
        $this->assertFalse(QualityContext::keywordIn('hướng dẫn chọn phần mềm quản lý kho cho doanh nghiệp nhỏ', $kw), 'Thiếu từ cốt lõi thì không khớp.');
    }
}
