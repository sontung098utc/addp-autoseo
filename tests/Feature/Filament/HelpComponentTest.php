<?php

namespace Tests\Feature\Filament;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class HelpComponentTest extends TestCase
{
    public function test_help_la_nut_bam_co_noi_dung_an_va_nhan_truy_cap(): void
    {
        $html = Blade::render('<x-help title="Tiêu đề">Nội dung hướng dẫn</x-help>');

        $this->assertStringContainsString('aria-label="Xem hướng dẫn"', $html);
        $this->assertStringContainsString('@click.stop="open = ! open"', $html);
        $this->assertStringContainsString('x-show="open"', $html);
        $this->assertStringContainsString('Nội dung hướng dẫn', $html);
        $this->assertStringContainsString('@keydown.escape.window', $html);
    }

    public function test_chain_context_mot_dong_va_muc_dich_nam_trong_popup(): void
    {
        $html = Blade::render('<x-chain-context step="Khám phá" purpose="Mục đích X" :inputs="[[\'label\'=>\'A\',\'url\'=>\'/a\']]" :outputs="[[\'label\'=>\'B\']]" />');

        $this->assertStringContainsString('data-testid="chain-context"', $html);
        $this->assertStringContainsString('<a href="/a">A</a>', $html);
        $this->assertStringContainsString('Mục đích X', $html);
        $this->assertStringContainsString('x-show="open"', $html, 'mục đích chỉ hiện khi bấm (i)');
    }
}
