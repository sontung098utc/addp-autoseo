<?php

namespace App\Services\Content\Quality\Rules;

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
        $ok = $n >= T::MIN_WORDS;
        $sev = $n < T::BLOCKING_WORDS ? RuleResult::P0 : RuleResult::P1;

        return [new RuleResult(
            'LEN01', $ok ? RuleResult::P1 : $sev, $ok, 'Độ dài bài',
            $ok ? "Bài có {$n} từ, đạt mức tối thiểu " . T::MIN_WORDS . '.'
                : "Bài có {$n} từ, tối thiểu " . T::MIN_WORDS . ($sev === RuleResult::P0 ? ' (dưới ' . T::BLOCKING_WORDS . ' từ là quá ngắn, chặn duyệt)' : '') . '. Hãy bổ sung nội dung có giá trị, không độn chữ.',
            "{$n} từ", '≥ ' . T::MIN_WORDS . ' từ',
        )];
    }
}
