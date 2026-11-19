<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\GuidanceConstraints;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\QualityThresholds as T;
use App\Services\Content\Quality\RuleResult;

class LengthRule implements QualityRule
{
    public function code(): string
    {
        return 'LEN01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $n = count($ctx->words());
        $min = T::MIN_WORDS;
        $blocking = T::BLOCKING_WORDS;
        // Người phụ trách giới hạn số từ thấp hơn sàn mặc định (vd "dưới 600 từ"): sàn hạ xuống một nửa giới hạn đó để hai luật không mâu thuẫn; việc tuân giới hạn do INS01 đo.
        foreach (GuidanceConstraints::extract((string) $ctx->article?->guidance, $ctx->extraGuidance)['constraints'] as $c) {
            if ($c['type'] === 'word_limit' && $c['max'] !== null && $c['max'] < $min) {
                $min = max(50, intdiv($c['max'], 2));
                $blocking = min($blocking, intdiv($min, 2));
            }
        }
        $ok = $n >= $min;
        $sev = $n < $blocking ? RuleResult::P0 : RuleResult::P1;

        return [new RuleResult(
            'LEN01', $ok ? RuleResult::P1 : $sev, $ok, 'Độ dài bài',
            $ok ? "Bài có {$n} từ, đạt mức tối thiểu " . $min . '.'
                : "Bài có {$n} từ, tối thiểu " . $min . ($sev === RuleResult::P0 ? ' (dưới ' . $blocking . ' từ là quá ngắn, chặn duyệt)' : '') . '. Hãy bổ sung nội dung có giá trị, không độn chữ.',
            "{$n} từ", '≥ ' . $min . ' từ',
        )];
    }
}
