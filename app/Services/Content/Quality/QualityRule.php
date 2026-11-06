<?php

namespace App\Services\Content\Quality;

interface QualityRule
{
    /** Mã luật, ví dụ LEN01. */
    public function code(): string;

    /** @return list<RuleResult> */
    public function evaluate(QualityContext $ctx): array;
}
