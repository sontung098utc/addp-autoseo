<?php

namespace Tests\Unit;

use App\Models\Brain;
use App\Services\Brain\BrainContext;
use PHPUnit\Framework\TestCase;

/** Phạm vi Trí tuệ của bài được thu hẹp theo kết quả phân loại của điều phối kho. */
class BrainContextOnlyTest extends TestCase
{
    public function test_only_giu_dung_cac_tri_tue_duoc_chon(): void
    {
        $a = new Brain();
        $a->id = 1;
        $b = new Brain();
        $b->id = 2;
        $ctx = new BrainContext([$a, $b]);

        $this->assertSame([2], $ctx->only([2])->ids());
        $this->assertSame([], $ctx->only([])->ids());
        $this->assertSame([1, 2], $ctx->only([1, 2, 9])->ids());
    }
}
