<?php

namespace Tests\Feature\Agent;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Ghi chú phụ dùng biểu tượng + tooltip, nội dung không nằm trơ trên trang và được escape. */
class HintComponentTest extends TestCase
{
    public function test_hint_co_aria_label_tooltip_va_escape(): void
    {
        $html = Blade::render('<x-studio.hint text="Giải thích <script>alert(1)</script>" />');

        $this->assertStringContainsString('x-tooltip', $html);
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('data-hint="info"', $html);
    }

    public function test_hint_canh_bao_co_bien_the(): void
    {
        $html = Blade::render('<x-studio.hint tone="warning" text="Cần chú ý" />');

        $this->assertStringContainsString('data-hint="warning"', $html);
        $this->assertStringContainsString('#d97706', $html);
    }
}
