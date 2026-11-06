<?php

namespace App\Services\Content\Quality;

final class QualityReport
{
    /** @param list<RuleResult> $results */
    public function __construct(public readonly array $results)
    {
    }

    /** @return list<RuleResult> chưa đạt, P0 trước */
    public function failed(): array
    {
        $failed = array_values(array_filter($this->results, fn (RuleResult $r) => ! $r->passed));
        usort($failed, fn (RuleResult $a, RuleResult $b) => strcmp($a->severity, $b->severity));

        return $failed;
    }

    /** @return list<RuleResult> */
    public function passed(): array
    {
        return array_values(array_filter($this->results, fn (RuleResult $r) => $r->passed));
    }

    public function hasBlocking(): bool
    {
        foreach ($this->results as $r) {
            if ($r->isBlocking()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<RuleResult> */
    public function blocking(): array
    {
        return array_values(array_filter($this->results, fn (RuleResult $r) => $r->isBlocking()));
    }

    /** @return list<string> mã luật P0 chưa đạt (duy nhất) */
    public function blockingCodes(): array
    {
        return array_values(array_unique(array_map(fn (RuleResult $r) => $r->code, $this->blocking())));
    }

    /** Số mục CHƯA ĐẠT theo mức. @return array{P0:int,P1:int,P2:int} */
    public function counts(): array
    {
        $c = [RuleResult::P0 => 0, RuleResult::P1 => 0, RuleResult::P2 => 0];
        foreach ($this->failed() as $r) {
            $c[$r->severity]++;
        }

        return $c;
    }
}
