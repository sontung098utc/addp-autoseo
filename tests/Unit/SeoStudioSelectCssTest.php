<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Chốt chặn lỗi "mũi tên ô chọn lặp kín ô, đè lên chữ" do CSS trang đặt `background: transparent`. */
class SeoStudioSelectCssTest extends TestCase
{
    public function test_select_goc_dat_lai_vi_tri_va_khong_lap_mui_ten(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/public/css/seo-studio.css');

        $this->assertMatchesRegularExpression('/select:not\(\[multiple\]\)\s*\{[^}]*background-repeat:\s*no-repeat/', $css);
        $this->assertMatchesRegularExpression('/select:not\(\[multiple\]\)\s*\{[^}]*background-position:\s*right/', $css);
        $this->assertMatchesRegularExpression('/select:not\(\[multiple\]\)\s*\{[^}]*padding-right:/', $css);
    }
}
