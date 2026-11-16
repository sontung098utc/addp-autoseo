<?php

namespace App\Services\Agent\Eval;

use App\Models\ArticleVersion;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityReport;

/**
 * AG-K09: đo một "bộ sinh" (playbook × model, hoặc bản giả lập) trên mẫu vàng, chấm bằng ContentQualityChecker.
 * Không gọi mạng; bộ sinh là callable nên thay bằng model thật ở chỗ khác. `pass_rate` là số liệu cổng bật tự động.
 */
final class EvalHarness
{
    public function __construct(private ContentQualityChecker $checker = new ContentQualityChecker())
    {
    }

    /**
     * @param  list<GoldenCase>  $cases
     * @param  callable(GoldenCase): array<string, mixed>  $producer  trả thuộc tính ArticleVersion
     * @return array{pass_rate: float, total: int, passed: int, cases: list<array{id: string, ok: bool, expected: list<string>, got: list<string>}>}
     */
    public function run(array $cases, callable $producer): array
    {
        $rows = [];
        $ok = 0;
        foreach ($cases as $c) {
            $got = $this->grade($producer($c))->blockingCodes();
            $want = $c->expectBlocking;
            sort($got);
            sort($want);
            $pass = $got === $want;
            $ok += $pass ? 1 : 0;
            $rows[] = ['id' => $c->id, 'ok' => $pass, 'expected' => $want, 'got' => $got];
        }
        $total = count($cases);

        return ['pass_rate' => $total === 0 ? 0.0 : round($ok / $total, 4), 'total' => $total, 'passed' => $ok, 'cases' => $rows];
    }

    /**
     * Đo vòng tự sửa: điểm (số lỗi có trọng số) sau mỗi vòng; dừng khi sạch P0/P1 hoặc hết $maxRounds.
     *
     * @param  callable(GoldenCase): array<string, mixed>  $producer
     * @param  callable(array<string, mixed>, QualityReport): array<string, mixed>  $repair
     * @return array{scores: list<int>, converged: bool, rounds: int}
     */
    public function repairCurve(GoldenCase $case, callable $producer, callable $repair, int $maxRounds = 3): array
    {
        $attrs = $producer($case);
        $scores = [self::penalty($report = $this->grade($attrs))];
        $rounds = 0;
        while ($rounds < $maxRounds && $scores[array_key_last($scores)] > 0) {
            $attrs = $repair($attrs, $report);
            $report = $this->grade($attrs);
            $scores[] = self::penalty($report);
            $rounds++;
        }

        return ['scores' => $scores, 'converged' => end($scores) === 0, 'rounds' => $rounds];
    }

    private function grade(array $attrs): QualityReport
    {
        return $this->checker->check(new ArticleVersion($attrs), null);
    }

    /** Điểm phạt: P0=10, P1=3, P2=1 cho mỗi mục chưa đạt. 0 = sạch hoàn toàn. */
    private static function penalty(QualityReport $r): int
    {
        $c = $r->counts();

        return $c['P0'] * 10 + $c['P1'] * 3 + $c['P2'];
    }
}
