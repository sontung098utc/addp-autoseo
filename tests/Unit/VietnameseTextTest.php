<?php

namespace Tests\Unit;

use App\Services\Content\VietnameseText;
use PHPUnit\Framework\TestCase;

/** Đếm từ tiếng Việt có dấu đúng (str_word_count cắt chữ có dấu và phóng ~1,5 lần). */
class VietnameseTextTest extends TestCase
{
    public function test_dem_am_tiet_co_dau_dung(): void
    {
        $s = 'Tiêu chuẩn GMP-WHO là gì? Đây là bộ hướng dẫn của Tổ chức Y tế Thế giới.';
        $this->assertSame(17, VietnameseText::wordCount($s));
        $this->assertGreaterThan(VietnameseText::wordCount($s), str_word_count($s), 'str_word_count phóng số từ với chữ có dấu');
    }

    public function test_bo_dau_cau_dung_rieng_va_khoang_trang_la(): void
    {
        $this->assertSame(3, VietnameseText::wordCount("Máy  dập\u{00A0}viên – | …"));
        $this->assertSame(0, VietnameseText::wordCount('   '));
    }

    public function test_dem_tu_html_bo_the_va_script(): void
    {
        $html = '<h2>Nguyên lý hoạt động</h2><p>Chày trên&nbsp;hạ xuống.</p><script>var a = "không tính";</script><ul><li>Phễu</li><li>Cối</li></ul>';
        $this->assertSame(10, VietnameseText::wordCountHtml($html));
    }

    public function test_hai_the_sat_nhau_van_tach_tu(): void
    {
        $this->assertSame(2, VietnameseText::wordCountHtml('<p>Một</p><p>hai</p>'));
    }
}
