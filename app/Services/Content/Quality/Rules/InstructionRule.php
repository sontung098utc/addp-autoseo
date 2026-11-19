<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\GuidanceConstraints;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/** INS01: bản viết phải tuân các ràng buộc ĐO ĐƯỢC trong chỉ dẫn của người phụ trách. Không có ràng buộc nào thì không có mục nào. */
class InstructionRule implements QualityRule
{
    public function code(): string
    {
        return 'INS01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $ex = GuidanceConstraints::extract((string) $ctx->article?->guidance, $ctx->extraGuidance);
        if ($ex['constraints'] === []) {
            return [];
        }
        $rows = GuidanceConstraints::evaluate($ex['constraints'], $ctx->html());
        $bad = array_values(array_filter($rows, fn ($r) => $r['ok'] === false));
        if ($bad === []) {
            return [new RuleResult('INS01', RuleResult::P1, true, 'Tuân chỉ dẫn của người phụ trách', 'Đạt các chỉ dẫn đo được: '.implode('; ', array_map(fn ($r) => $r['message'], array_filter($rows, fn ($r) => $r['ok'] === true))).'.')];
        }
        $gaps = array_map(fn ($r) => $r['message'], $bad);
        $distance = 0;
        foreach ($bad as $r) {
            $distance += $r['type'] === 'word_limit' ? max(1, (int) ceil($r['deviation'] / 100)) : $r['deviation'];
        }

        return [new RuleResult(
            'INS01', RuleResult::P1, false, 'Tuân chỉ dẫn của người phụ trách',
            'Chưa đạt chỉ dẫn: '.implode('; ', $gaps).'. Chỉ dẫn của người phụ trách là yêu cầu nội dung phải đạt, sửa đúng số đo này.',
            implode('; ', array_map(fn ($r) => "{$r['measured']} {$r['unit']}", $bad)),
            implode('; ', array_map(fn ($r) => $r['label'], $bad)),
            null, $gaps, ['distance' => $distance],
        )];
    }
}
