<?php

namespace Tests\Unit;

use App\Filament\Support\Article\PreviewNotes;
use Tests\TestCase;

/** Xem trước (sandbox rỗng): chú thích/ghi chú hiện hộp bằng CSS, không còn liên kết neo làm trắng khung. */
class PreviewNotesTest extends TestCase
{
    public function test_chu_thich_va_ghi_chu_thanh_phan_tu_bam_duoc_co_hop_chu_thich_khong_dieu_huong(): void
    {
        $html = '<p>A<sup class="autoseo-cite" data-kb="12" title="Nguồn: WHO — Chẩn đoán"><a href="#nguon-1">[1]</a></sup> B<sup class="autoseo-note" data-note="a" title="HbA1c là tỉ lệ huyết sắc tố gắn glucose."><a href="#ghichu-a">[a]</a></sup></p>';
        $out = PreviewNotes::prepare($html);

        $this->assertStringNotContainsString('<a ', $out, 'không còn liên kết neo nào để bấm vào làm trắng khung');
        $this->assertStringNotContainsString('title=', $out, 'không để tooltip gốc của trình duyệt trùng với hộp CSS');
        $this->assertStringContainsString('<sup class="autoseo-cite" data-kb="12" tabindex="0" data-tip="Nguồn: WHO — Chẩn đoán">[1]</sup>', $out);
        $this->assertStringContainsString('data-tip="HbA1c là tỉ lệ huyết sắc tố gắn glucose."', $out);
        $this->assertStringContainsString('>[a]</sup>', $out);
    }

    public function test_lien_ket_khac_khong_dieu_huong_chi_hien_dia_chi_trong_hop(): void
    {
        $out = PreviewNotes::prepare('<li>Tên — <a href="https://www.who.int/x?a=1&amp;b=2" rel="nofollow">who.int</a> và <a href="#top">neo</a> và <a name="x">không href</a></li>');
        $this->assertStringNotContainsString('<a ', $out);
        $this->assertStringContainsString('<span class="pv-link" tabindex="0" data-tip="https://www.who.int/x?a=1&amp;b=2">who.int</span>', $out);
        $this->assertStringContainsString('và neo và không href', $out);
        $this->assertSame('', PreviewNotes::prepare(''));
    }
}
