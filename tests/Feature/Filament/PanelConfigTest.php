<?php

namespace Tests\Feature\Filament;

use Filament\Facades\Filament;
use Tests\TestCase;

class PanelConfigTest extends TestCase
{
    public function test_trang_dang_nhap_hien_thi_thuong_hieu_va_css(): void
    {
        $res = $this->get('/admin/login');

        $res->assertOk();
        $res->assertSee('SEO Studio');
        $res->assertSee('css/seo-studio.css', false);
        $this->assertFileExists(public_path('css/seo-studio.css'));
    }

    public function test_cau_hinh_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame('SEO Studio', $panel->getBrandName());
        $this->assertSame(
            ['Chiến dịch', 'Lịch đăng', 'Khám phá', 'Phân tích', 'Cài đặt'],
            array_map(fn ($g) => is_string($g) ? $g : $g->getLabel(), $panel->getNavigationGroups())
        );
        $this->assertArrayHasKey('primary', $panel->getColors());
        $this->assertSame([], $panel->getWidgets());
    }
}
