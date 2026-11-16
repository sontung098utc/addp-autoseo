<?php

namespace App\Services\Agent\Ai;

use RuntimeException;

/** Một lần gọi AI vượt trần chi phí của route. Phiên dừng và ghi quyết định. */
final class CostCapExceeded extends RuntimeException
{
    public function __construct(public readonly string $task, public readonly float $cost, public readonly float $cap)
    {
        parent::__construct(sprintf('Lần gọi "%s" tốn %s đ, vượt trần %s đ/lần.', $task, number_format($cost, 0, ',', '.'), number_format($cap, 0, ',', '.')));
    }
}
