<?php

namespace Tests\Unit\Eval;

use App\Services\Agent\Eval\GoldenQuery;
use App\Services\Agent\Eval\GoldenQuerySet;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Q0.1: bộ từ khóa chuẩn nạp được, đúng định dạng; tệp hỏng làm cả bộ thất bại. */
class GoldenQuerySetTest extends TestCase
{
    private function tmp(array $data): string
    {
        $p = sys_get_temp_dir().'/gq-'.uniqid().'.json';
        file_put_contents($p, json_encode($data, JSON_UNESCAPED_UNICODE));

        return $p;
    }

    private function ok(): array
    {
        return ['queries' => [['id' => 'q-1', 'keyword' => 'a b', 'intent' => 'informational', 'risk' => 'low', 'domain' => 'x',
            'must_answer' => [['id' => 'q1-1', 'q' => 'Câu hỏi?', 'kind' => 'so_lieu', 'needs_source' => true]]]]];
    }

    public function test_bo_chuan_that_trong_repo_nap_duoc_va_du_30_tu_khoa(): void
    {
        $qs = GoldenQuerySet::load(dirname(__DIR__, 3).'/resources/eval/golden-queries.json');
        $this->assertCount(30, $qs);
        $ids = array_map(fn (GoldenQuery $q) => $q->id, $qs);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $risks = array_count_values(array_map(fn (GoldenQuery $q) => $q->risk, $qs));
        $this->assertGreaterThan(0, $risks['high'] ?? 0);
        $this->assertGreaterThan(0, $risks['low'] ?? 0);
        foreach ($qs as $q) {
            $this->assertGreaterThanOrEqual(3, count($q->mustAnswer), $q->id);
        }
    }

    public function test_tim_theo_tu_khoa_khong_phan_biet_hoa_thuong_va_khoang_trang(): void
    {
        $qs = GoldenQuerySet::load(dirname(__DIR__, 3).'/resources/eval/golden-queries.json');
        $q = GoldenQuerySet::findByKeyword($qs, '  CHỈ SỐ  HbA1c bao nhiêu là tốt ');
        $this->assertNotNull($q);
        $this->assertSame('q-d02', $q->id);
        $this->assertNull(GoldenQuerySet::findByKeyword($qs, 'khong co tu khoa nay'));
    }

    public function test_cau_hoi_so_lieu_duoc_nhan_biet(): void
    {
        $q = GoldenQuerySet::load($this->tmp($this->ok()))[0];
        $this->assertTrue($q->needsNumber('q1-1'));
        $this->assertFalse($q->needsNumber('khong-co'));
    }

    public function test_tep_hong_thi_that_bai_co_ly_do(): void
    {
        $bad = $this->ok();
        $bad['queries'][0]['risk'] = 'cao';
        foreach ([['x' => 1], $bad] as $d) {
            try {
                GoldenQuerySet::load($this->tmp($d));
                $this->fail('Phải ném lỗi.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $dup = $this->ok();
        $dup['queries'][] = $dup['queries'][0];
        $this->expectException(InvalidArgumentException::class);
        GoldenQuerySet::load($this->tmp($dup));
    }

    public function test_thieu_cau_hoi_bat_buoc_bi_tu_choi(): void
    {
        $d = $this->ok();
        $d['queries'][0]['must_answer'] = [];
        $this->expectException(InvalidArgumentException::class);
        GoldenQuerySet::load($this->tmp($d));
    }
}
