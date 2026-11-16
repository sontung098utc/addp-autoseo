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

        $this->assertStringNotContainsString('href="#', $out, 'không còn liên kết neo nội bộ nào để bấm vào làm trắng khung');
        $this->assertStringNotContainsString('title=', $out, 'không để tooltip gốc của trình duyệt trùng với hộp CSS');
        $this->assertStringContainsString('<sup class="autoseo-cite" data-kb="12" tabindex="0" data-tip="Nguồn: WHO — Chẩn đoán">[1]</sup>', $out);
        $this->assertStringContainsString('data-tip="HbA1c là tỉ lệ huyết sắc tố gắn glucose."', $out);
        $this->assertStringContainsString('>[a]</sup>', $out);
    }

    public function test_lien_ket_khac_khong_dieu_huong_chi_hien_dia_chi_trong_hop(): void
    {
        $out = PreviewNotes::prepare('<li>Tên — <a href="https://www.who.int/x?a=1&amp;b=2" rel="nofollow">who.int</a> và <a href="#top">neo</a> và <a name="x">không href</a></li>');
        $this->assertStringContainsString('<a class="pv-link" href="https://www.who.int/x?a=1&amp;b=2" target="_blank" rel="noopener noreferrer nofollow" data-tip="https://www.who.int/x?a=1&amp;b=2">who.int</a>', $out, 'liên kết ngoài bấm được, mở ở tab mới');
        $this->assertStringNotContainsString('href="#top"', $out);
        $this->assertStringContainsString('và neo và không href', $out);
        $this->assertSame('', PreviewNotes::prepare(''));
    }

    public function test_chu_thich_so_bam_duoc_mo_dung_trang_nguon_va_chi_nhan_http(): void
    {
        $html = '<p>A<sup class="autoseo-cite" data-kb="12" title="Nguồn: X"><a href="#nguon-1">[1]</a></sup><sup class="autoseo-cite" title="Nguồn: Y"><a href="#nguon-2">[2]</a></sup></p>'
            .'<div class="autoseo-refs"><ol><li id="nguon-1"><a href="https://www.who.int/a" rel="nofollow">X</a></li><li id="nguon-2"><a href="javascript:alert(1)">Y</a></li></ol></div>';
        $out = PreviewNotes::prepare($html);
        $this->assertStringContainsString('<a class="pv-cite-link" href="https://www.who.int/a" target="_blank" rel="noopener noreferrer nofollow">[1]</a>', $out);
        $this->assertStringNotContainsString('href="javascript:', $out);
        $this->assertStringContainsString('>[2]</sup>', $out, 'Nguồn không phải http thì [2] không có liên kết.');
    }
}
