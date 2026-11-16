<?php

namespace Tests\Unit\Eval;

use App\Services\Agent\Eval\JudgeCalibration;
use PHPUnit\Framework\TestCase;

/** Q0.5: đo giám khảo AI so với nhãn của người. Hàm thuần. */
class JudgeCalibrationTest extends TestCase
{
    private const C = ['dung', 'khong_ro', 'sai'];

    public function test_dong_y_hoan_toan_cho_kappa_bang_1(): void
    {
        $r = JudgeCalibration::compare([['dung', 'dung'], ['sai', 'sai'], ['khong_ro', 'khong_ro'], ['dung', 'dung']], self::C);
        $this->assertSame(4, $r['n']);
        $this->assertSame(1.0, $r['accuracy']);
        $this->assertSame(1.0, $r['kappa']);
        $this->assertSame(1.0, $r['per_class']['dung']['precision']);
    }

    public function test_ai_noi_dung_het_trong_khi_nguoi_chia_deu_thi_kappa_khong_cao(): void
    {
        $pairs = [['dung', 'dung'], ['dung', 'dung'], ['dung', 'sai'], ['dung', 'sai']];
        $r = JudgeCalibration::compare($pairs, self::C);
        $this->assertSame(0.5, $r['accuracy']);
        $this->assertSame(0.0, $r['kappa'], 'Giám khảo luôn nói "đúng" không có khả năng phân biệt.');
        $this->assertSame(0.5, $r['per_class']['dung']['precision']);
        $this->assertSame(1.0, $r['per_class']['dung']['recall']);
        $this->assertNull($r['per_class']['sai']['precision'], 'AI không bao giờ nói "sai" nên precision không xác định.');
        $this->assertSame(0.0, $r['per_class']['sai']['recall']);
    }

    public function test_khong_co_cap_nao_thi_khong_co_so(): void
    {
        $r = JudgeCalibration::compare([], self::C);
        $this->assertSame(0, $r['n']);
        $this->assertNull($r['accuracy']);
        $this->assertNull($r['kappa']);
    }

    public function test_nhan_la_bi_bo_qua(): void
    {
        $r = JudgeCalibration::compare([['dung', 'chua-chon'], ['dung', 'dung']], self::C);
        $this->assertSame(1, $r['n']);
    }

    public function test_tu_tep_nhan_tao_hai_bo_so_sanh_va_bo_muc_chua_cham(): void
    {
        $labels = ['labels' => [[
            'pairs' => [['key' => 'a', 'ai' => 'supported', 'human' => 'dung'], ['key' => 'b', 'ai' => 'unsupported', 'human' => 'dung'], ['key' => 'c', 'ai' => 'supported', 'human' => '']],
            'must_answer' => [['id' => 'q1', 'ai' => 'answered', 'human' => 'co'], ['id' => 'q2', 'ai' => 'evasive', 'human' => 'noi_vong'], ['id' => 'q3', 'ai' => 'missing', 'human' => '']],
        ]]];
        $r = JudgeCalibration::fromLabels($labels);
        $this->assertSame(2, $r['claims']['n']);
        $this->assertSame(0.5, $r['claims']['accuracy']);
        $this->assertSame(2, $r['intent']['n']);
        $this->assertSame(1.0, $r['intent']['accuracy']);
    }
}
