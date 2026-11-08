<?php

namespace Tests\Unit;

use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityRubric;
use App\Services\Content\Quality\QualityThresholds;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * AG-K03: rubric có metadata phải khớp với bộ luật thật; chống "lệch" khi thêm luật/ngưỡng mới mà quên ghi nguồn.
 */
class QualityRubricTest extends TestCase
{
    public function test_moi_luat_dang_chay_deu_co_metadata_va_khong_thua(): void
    {
        $codes = array_map(fn ($r) => $r->code(), ContentQualityChecker::defaultRules());
        sort($codes);
        $meta = array_keys(QualityRubric::all());
        sort($meta);

        $this->assertSame($codes, $meta, 'Rubric phải có đúng một mục cho mỗi luật đang chạy');
    }

    public function test_truong_bat_buoc_day_du_va_hop_le(): void
    {
        foreach (QualityRubric::all() as $code => $e) {
            $this->assertSame($code, $e['code']);
            foreach (['name', 'what', 'basis', 'rationale', 'caveat'] as $f) {
                $this->assertNotSame('', trim((string) $e[$f]), "{$code}.{$f} không được rỗng");
            }
            $this->assertContains($e['verification'], QualityRubric::VERIFICATION, $code);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $e['reviewed_at'], $code);
            $this->assertNotFalse(strtotime($e['reviewed_at']), $code);
        }
    }

    public function test_muc_da_xac_minh_phai_co_nguon_chinh_thuc(): void
    {
        foreach (QualityRubric::all() as $code => $e) {
            if (in_array($e['verification'], ['verified', 'partial'], true)) {
                $this->assertNotEmpty($e['source_urls'], "{$code} đánh dấu đã xác minh nhưng không có nguồn");
                foreach ($e['source_urls'] as $u) {
                    $this->assertStringStartsWith('https://developers.google.com/', $u, $code);
                }
            }
        }
    }

    public function test_moi_hang_so_nguong_deu_duoc_ghi_trong_rubric_va_gia_tri_khop(): void
    {
        $consts = (new ReflectionClass(QualityThresholds::class))->getConstants();
        $inRubric = [];
        foreach (QualityRubric::all() as $e) {
            foreach ($e['thresholds'] as $value) {
                $inRubric[] = $value;
            }
        }

        foreach ($consts as $name => $value) {
            $this->assertContains($value, $inRubric, "Hằng số {$name} chưa được ghi (nguồn/lý do) trong QualityRubric");
        }
    }

    public function test_nguong_chua_xac_minh_khong_duoc_gia_lam_quy_tac_google(): void
    {
        // Theo AG-K01: mật độ từ khóa và FAQ chỉ xác minh một phần; số từ chưa xác minh.
        $this->assertSame('partial', QualityRubric::for('KW01')['verification']);
        $this->assertSame('partial', QualityRubric::for('FAQ01')['verification']);
        $this->assertSame('unverified', QualityRubric::for('LEN01')['verification']);
        $this->assertEqualsCanonicalizing(['LEN01', 'HEAD01', 'KW01', 'FAQ01'], QualityRubric::notVerified());
    }

    public function test_khong_co_luat_la_tra_null(): void
    {
        $this->assertNull(QualityRubric::for('KHONG_TON_TAI'));
    }
}
