<?php

namespace Tests\Unit\Eval;

use App\Services\Agent\Eval\FactSentenceDetector;
use PHPUnit\Framework\TestCase;

/** Q0.2: phát hiện câu sự thật và câu nào chưa có dấu trích dẫn. Tất định, không AI. */
class FactSentenceDetectorTest extends TestCase
{
    public function test_cau_co_so_kem_don_vi_la_cau_su_that(): void
    {
        $this->assertTrue(FactSentenceDetector::isFact('Đường huyết lúc đói bình thường dưới 5,6 mmol/L ở người trưởng thành.'));
        $this->assertTrue(FactSentenceDetector::isFact('Người trưởng thành nên vận động khoảng 150 phút mỗi tuần theo khuyến cáo.'));
        $this->assertTrue(FactSentenceDetector::isFact('Theo Bộ Y tế, việc chẩn đoán dựa trên nhiều xét nghiệm khác nhau.'));
    }

    public function test_cau_chung_chung_hoac_luu_y_khong_phai_cau_su_that(): void
    {
        $this->assertFalse(FactSentenceDetector::isFact('Hãy giữ tinh thần thoải mái và ngủ đủ giấc mỗi tối nhé.'));
        $this->assertFalse(FactSentenceDetector::isFact('Thông tin trong bài chỉ mang tính tham khảo, không thay thế tư vấn của bác sĩ.'));
        $this->assertFalse(FactSentenceDetector::isFact('Ngắn 5 mmol.'));
    }

    public function test_dau_trich_dan_tho_sau_dau_cham_van_tinh_cho_cau_truoc(): void
    {
        $html = '<p>Đường huyết lúc đói bình thường dưới 5,6 mmol/L ở người lớn. [KB#12] Việc theo dõi đều đặn giúp kiểm soát bệnh tốt hơn nhiều người nghĩ.</p>';
        $r = (new FactSentenceDetector)->analyze($html);
        $this->assertSame(1, $r['facts']);
        $this->assertSame(1, $r['cited']);
        $this->assertSame([], $r['uncited']);
        $this->assertSame(1.0, $r['ratio']);
    }

    public function test_cau_so_lieu_khong_co_dau_duoc_liet_ke(): void
    {
        $html = '<p>Mức HbA1c từ 6,5% trở lên được dùng để chẩn đoán đái tháo đường.</p><p>Chủ đề này liên quan đến sức khỏe mỗi người trong cuộc sống.</p>';
        $r = (new FactSentenceDetector)->analyze($html);
        $this->assertSame(1, $r['facts']);
        $this->assertSame(0, $r['cited']);
        $this->assertCount(1, $r['uncited']);
        $this->assertSame(0.0, $r['ratio']);
    }

    public function test_ban_da_luu_dung_sup_data_kb_va_bo_qua_khoi_nguon_tham_khao(): void
    {
        $html = '<p>Xét nghiệm này phản ánh đường huyết trung bình trong 3 tháng gần nhất<sup data-kb="5">[1]</sup>.</p>'
            .'<div class="autoseo-refs"><p>Nguồn: Bộ Y tế, 2020. Quyết định 5481 năm 2020 của Bộ Y tế.</p></div>';
        $r = (new FactSentenceDetector)->analyze($html);
        $this->assertSame(1, $r['facts']);
        $this->assertSame(1, $r['cited']);
        $this->assertSame(1, $r['total'], 'Khối Nguồn tham khảo không được tính là câu của bài.');
    }

    public function test_khong_co_cau_su_that_thi_ti_le_la_null(): void
    {
        $r = (new FactSentenceDetector)->analyze('<p>Hãy giữ tinh thần thoải mái mỗi ngày nhé các bạn thân mến.</p>');
        $this->assertSame(0, $r['facts']);
        $this->assertNull($r['ratio']);
    }
}
