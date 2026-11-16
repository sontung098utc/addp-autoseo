<?php

namespace Tests\Feature\Knowledge\Build;

use App\Models\Brain;
use App\Services\Knowledge\Build\RiskTiering;
use Tests\TestCase;

class RiskTieringTest extends TestCase
{
    private function t(array $o, ?Brain $brain = null, bool $own = true): string
    {
        return (new RiskTiering)->tier($o + ['type' => 'fact', 'title' => 'Công ty thành lập năm 2010', 'body' => 'Công ty Ánh Dương thành lập năm 2010 tại Hà Nội.', 'source_span' => 'thành lập năm 2010'], $brain, $own)['tier'];
    }

    private function brain(array $settings): Brain
    {
        return new Brain(['name' => 'Thử', 'settings' => $settings]);
    }

    public function test_tang_a_thong_tin_thuong_cua_doanh_nghiep(): void
    {
        $this->assertSame('A', $this->t([]));
        foreach (['process', 'faq', 'messaging', 'product'] as $type) {
            $this->assertSame('A', $this->t(['type' => $type, 'title' => 'Bàn học gỗ', 'body' => 'Bàn học gỗ sồi, có ngăn kéo, phù hợp phòng nhỏ.']), $type);
        }
        $this->assertSame('A', $this->t([], $this->brain(['risk_class' => 'low', 'ai_reliance' => 'free'])));
    }

    public function test_tang_c_theo_loai(): void
    {
        foreach (['price', 'policy', 'myth', 'forbidden'] as $type) {
            $this->assertSame('C', $this->t(['type' => $type]), $type);
        }
    }

    public function test_tang_c_khi_co_so_kem_don_vi(): void
    {
        foreach (['Học phí 8.000.000 đồng mỗi năm', 'Giảm 20% cho học viên mới', 'Uống 2 viên mỗi ngày', 'Chỉ từ $50 mỗi tháng', 'Liều 500mg', 'Giá 1,5 triệu', 'Phí 300k', 'Hoàn 10 phần trăm'] as $body) {
            $this->assertSame('C', $this->t(['body' => $body]), $body);
        }
        $this->assertSame('A', $this->t(['body' => 'Chúng tôi có 120 học viên và 15 giáo viên.']), 'số không kèm đơn vị tiền/liều thì không phải C');
    }

    public function test_tang_c_theo_tri_tue(): void
    {
        $this->assertSame('C', $this->t([], $this->brain(['risk_class' => 'high'])));
        $this->assertSame('C', $this->t([], $this->brain(['ai_reliance' => 'must_cite'])));
    }

    public function test_tang_c_theo_chu_de_rui_ro_cao(): void
    {
        $this->assertSame('C', $this->t(['title' => 'Điều trị tiểu đường', 'body' => 'Phòng khám hướng dẫn chế độ ăn cho người tiểu đường.']));
        $this->assertSame('C', $this->t(['title' => 'Tư vấn thuế', 'body' => 'Chúng tôi hỗ trợ kê khai thuế cho doanh nghiệp nhỏ.']));
        $this->assertSame('C', $this->t(['title' => 'Khởi kiện', 'body' => 'Văn phòng luật nhận giải quyết tranh chấp đất đai.']));
    }

    public function test_san_pham_suc_khoe_luon_c(): void
    {
        foreach (['Viên uống Omega-3', 'Thực phẩm chức năng Canxi D3', 'Máy đo huyết áp'] as $name) {
            $body = str_contains($name, 'Thực phẩm') ? 'Thực phẩm chức năng bổ sung canxi cho người lớn.' : (str_contains($name, 'Máy') ? 'Thiết bị y tế đo huyết áp tại nhà, màn hình lớn.' : 'Viên uống bổ sung vitamin hằng ngày, dạng viên nang mềm.');
            $this->assertSame('C', $this->t(['type' => 'product', 'title' => $name, 'entity' => $name, 'body' => $body]), $name);
        }
        $r = (new RiskTiering)->tier(['type' => 'product', 'title' => 'Canxi D3', 'body' => 'Thực phẩm bảo vệ sức khỏe giúp xương chắc khỏe.', 'source_span' => 'x']);
        $this->assertSame('C', $r['tier']);
        $this->assertStringContainsString('sức khỏe', implode(' ', $r['reasons']));
    }

    public function test_tang_b_con_lai(): void
    {
        $this->assertSame('B', $this->t([], null, false), 'không phải từ chính website');
        $this->assertSame('B', $this->t(['source_span' => null]), 'không có đoạn nguyên văn');
        $this->assertSame('B', $this->t(['body' => 'Dịch vụ của chúng tôi tốt nhất khu vực, so sánh với đối thủ.']), 'so sánh/khẳng định cần người xem');
        $this->assertSame('B', $this->t(['type' => 'unknown_type']));
    }

    public function test_ai_khong_the_ha_tang(): void
    {
        $r = (new RiskTiering)->tier(['type' => 'price', 'title' => 'Giá', 'body' => 'Giá gói cơ bản.', 'source_span' => 'x', 'confidence' => 100, 'tier' => 'A', 'risk_tier' => 'A']);
        $this->assertSame('C', $r['tier']);
    }
}
