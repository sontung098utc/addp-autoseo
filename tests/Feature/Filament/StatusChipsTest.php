<?php

namespace Tests\Feature\Filament;

use App\Filament\Support\StatusPresenter;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * AG-U01: nhãn mức sẵn sàng và nguồn nội dung AI — để không nhầm "chưa nối" thành "đã chạy" hay "AI giả lập" thành "AI thật".
 */
class StatusChipsTest extends TestCase
{
    public function test_nam_muc_san_sang_co_nhan_va_mau_co_dinh(): void
    {
        $expect = [
            'active' => ['Đang hoạt động', 'success'],
            'demo' => ['Dữ liệu minh họa', 'gray'],
            'not_connected' => ['Chưa kết nối', 'warning'],
            'in_development' => ['Đang phát triển', 'info'],
            'idea' => ['Ý tưởng', 'gray'],
        ];
        foreach ($expect as $v => [$label, $color]) {
            $this->assertSame($label, StatusPresenter::label($v, 'readiness'), $v);
            $this->assertSame($color, StatusPresenter::color($v, 'readiness'), $v);
            $this->assertNotNull(StatusPresenter::hint($v, 'readiness'), "{$v} phải có gợi ý");
        }
    }

    public function test_nguon_noi_dung_ai_giai_lap_khac_biet_ro_voi_ai_that(): void
    {
        $this->assertSame('AI thật', StatusPresenter::label('live', 'source'));
        $this->assertSame('AI giả lập', StatusPresenter::label('mock', 'source'));
        $this->assertNotSame(StatusPresenter::color('live', 'source'), StatusPresenter::color('mock', 'source'));
        $this->assertSame('warning', StatusPresenter::color('mock', 'source'));
        $this->assertStringContainsString('KHÔNG phải', StatusPresenter::hint('mock', 'source'));
    }

    public function test_gia_tri_la_khong_nem_loi_va_khong_gia_la_hoat_dong(): void
    {
        $this->assertSame('xyz', StatusPresenter::label('xyz', 'readiness'));
        $this->assertSame('gray', StatusPresenter::color('xyz', 'readiness'));
        $this->assertNull(StatusPresenter::hint('xyz', 'readiness'));
        $this->assertSame('—', StatusPresenter::label(null, 'source'));
    }

    public function test_cac_domain_cu_khong_doi(): void
    {
        $this->assertSame('Đã duyệt', StatusPresenter::label('approved', 'article'));
        $this->assertSame('Chờ đối soát', StatusPresenter::label('needs_reconcile', 'publication'));
        $this->assertSame('Hoạt động', StatusPresenter::label('active', 'website'));
        $this->assertNull(StatusPresenter::hint('approved', 'article'));
    }

    public function test_component_chip_hien_thi_nhan_va_tooltip(): void
    {
        $html = Blade::render('<x-studio.chip domain="source" value="mock" />');

        $this->assertStringContainsString('AI giả lập', $html);
        $this->assertStringContainsString('ss-badge--warn', $html);
        $this->assertStringContainsString('KHÔNG phải', $html);

        $html = Blade::render('<x-studio.chip domain="readiness" value="not_connected" />');
        $this->assertStringContainsString('Chưa kết nối', $html);
        $this->assertStringContainsString('ss-badge--warn', $html);
    }

    public function test_component_chip_voi_trang_thai_cu_van_dung(): void
    {
        $html = Blade::render('<x-studio.chip domain="article" value="approved" />');

        $this->assertStringContainsString('Đã duyệt', $html);
        $this->assertStringContainsString('ss-badge--ok', $html);
    }
}
