<?php

namespace Tests\Unit;

use App\Services\Brain\BrainProfileDrafter;
use PHPUnit\Framework\TestCase;

class BrainProfileDrafterTest extends TestCase
{
    public function test_chuan_hoa_ho_so_ai_dung_linh_vuc_va_bo_phan_khong_hop_le(): void
    {
        $p = BrainProfileDrafter::normalize([
            'voice' => ['tone' => 'Chuyên nghiệp', 'do' => ['Nêu thông số', 'Giải thích tiêu chuẩn'], 'dont' => ['Hứa hiệu quả']],
            'article_types' => [['key' => 'So sánh thiết bị', 'name' => 'So sánh thiết bị', 'outline' => ['Tổng quan', 'Nguyên lý', 'Ưu nhược', 'Chọn theo quy mô']], ['name' => 'Thiếu mục', 'outline' => ['a']]],
            'terms' => [['term' => 'GMP', 'definition' => 'Thực hành sản xuất tốt'], ['term' => '']],
            'abbreviations' => ['GMP = Thực hành sản xuất tốt'], 'rules' => ['Số liệu phải có nguồn'],
        ], 'Công nghiệp dược', false);
        $this->assertTrue($p['_ai']);
        $this->assertCount(1, $p['structure']['article_types'], 'Khung thiếu mục bị bỏ.');
        $this->assertSame('so_sanh_thiet_bi', $p['structure']['article_types'][0]['key']);
        $this->assertSame('GMP', $p['terminology']['preferred'][0]['term']);
        $this->assertSame(['Số liệu phải có nguồn'], $p['reasoning']['rules'], 'Lĩnh vực không nhạy cảm: không tự thêm miễn trừ y tế.');
    }

    public function test_ai_loi_thi_dung_ho_so_trung_tinh_khong_mang_khung_y_khoa(): void
    {
        $p = BrainProfileDrafter::normalize([], 'Công nghiệp dược', false);
        $this->assertFalse($p['_ai']);
        $json = json_encode($p, JSON_UNESCAPED_UNICODE);
        foreach (['bác sĩ', 'Giải thích bệnh', 'kê đơn'] as $bad) {
            $this->assertStringNotContainsString($bad, $json, 'Hồ sơ trung tính không được mang khung của lĩnh vực y khoa.');
        }
        $s = BrainProfileDrafter::normalize([], 'Đái tháo đường', true);
        $this->assertStringContainsString('miễn trừ', json_encode($s['reasoning'], JSON_UNESCAPED_UNICODE), 'Lĩnh vực nhạy cảm vẫn có miễn trừ trách nhiệm.');
        $this->assertStringContainsString('KHÔNG phải tư vấn sức khỏe', BrainProfileDrafter::systemPrompt('n', 'Công nghiệp dược', false));
    }
}
