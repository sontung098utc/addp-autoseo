<?php

namespace Tests\Unit;

use App\Services\Agent\Eval\EvalHarness;
use App\Services\Agent\Eval\GoldenCase;
use App\Services\Agent\Eval\GoldenSet;
use App\Services\Content\Quality\QualityReport;
use InvalidArgumentException;
use Tests\TestCase;

/** AG-K09: harness chấm mẫu vàng bằng luật và đo vòng tự sửa. Không gọi AI/mạng. */
class EvalHarnessTest extends TestCase
{
    private const KW = 'học bổng toàn phần';
    private string $dir;

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            foreach (glob($this->dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    /** Bộ sinh giả lập tất định: dựng bài tốt rồi làm hỏng theo `mutate`. */
    private function producer(): \Closure
    {
        return function (GoldenCase $c): array {
            $a = QualityFixtures::goodAttrs(self::KW);

            return match ($c->input['mutate'] ?? 'none') {
                'short' => ['html_content' => '<h2>Ngắn</h2><p>Quá ngắn.</p>'] + $a,
                'script' => ['html_content' => $a['html_content'].'<script>alert(1)</script>'] + $a,
                'nokeyword' => ['focus_keyword' => 'từ khóa không xuất hiện ở đâu cả'] + $a,
                default => $a,
            };
        };
    }

    public function test_mau_vang_that_trong_repo_dat_100_phan_tram_voi_bo_sinh_tat_dinh(): void
    {
        $cases = GoldenSet::load(base_path('tests/Fixtures/golden'));
        $out = (new EvalHarness())->run($cases, $this->producer());

        $this->assertNotEmpty($cases);
        $this->assertSame(1.0, $out['pass_rate'], json_encode($out['cases'], JSON_UNESCAPED_UNICODE));
    }

    public function test_bo_sinh_kem_bi_phat_hien_pass_rate_giam(): void
    {
        $cases = GoldenSet::load(base_path('tests/Fixtures/golden'));
        // bộ sinh luôn trả bài rác: mọi mẫu mong đợi "sạch" hoặc lỗi cụ thể đều không khớp trừ w02
        $out = (new EvalHarness())->run($cases, fn () => ['html_content' => '<p>x</p>']);

        $this->assertLessThan(1.0, $out['pass_rate']);
        $this->assertSame(count($cases), $out['total']);
    }

    public function test_khong_co_mau_thi_pass_rate_bang_0_khong_phai_100(): void
    {
        $this->assertSame(0.0, (new EvalHarness())->run([], fn () => [])['pass_rate']);
    }

    public function test_duong_cong_tu_sua_giam_diem_va_hoi_tu(): void
    {
        $case = new GoldenCase('r1', 'writer/article-draft@1', ['mutate' => 'script'], ['SAFE01']);
        $repair = function (array $attrs, QualityReport $r): array {
            $attrs['html_content'] = preg_replace('#<script>.*?</script>#s', '', $attrs['html_content']);

            return $attrs;
        };
        $curve = (new EvalHarness())->repairCurve($case, $this->producer(), $repair);

        $this->assertGreaterThan(end($curve['scores']), $curve['scores'][0]);
        $this->assertTrue($curve['converged'] || end($curve['scores']) < $curve['scores'][0]);
        $this->assertLessThanOrEqual(3, $curve['rounds']);
    }

    public function test_vong_tu_sua_bi_chan_o_so_vong_toi_da(): void
    {
        $case = new GoldenCase('r2', 'writer/article-draft@1', ['mutate' => 'script'], ['SAFE01']);
        $curve = (new EvalHarness())->repairCurve($case, $this->producer(), fn (array $a) => $a, 2);

        $this->assertFalse($curve['converged']);
        $this->assertSame(2, $curve['rounds']);
    }

    public function test_file_mau_hong_hoac_trung_id_bi_tu_choi(): void
    {
        $this->dir = sys_get_temp_dir().'/golden_'.bin2hex(random_bytes(5));
        mkdir($this->dir);
        file_put_contents($this->dir.'/a.json', '{"playbook":"x@1","cases":[{"id":"a","input":{},"expect_blocking":[]},{"id":"a","input":{},"expect_blocking":[]}]}');
        try {
            GoldenSet::load($this->dir);
            $this->fail('Phải từ chối trùng id');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Trùng id', $e->getMessage());
        }

        file_put_contents($this->dir.'/a.json', 'không phải json');
        $this->expectException(InvalidArgumentException::class);
        GoldenSet::load($this->dir);
    }
}
