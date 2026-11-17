<?php

namespace Tests\Unit;

use App\Services\Content\SectionSplice;
use PHPUnit\Framework\TestCase;

class SectionSpliceTest extends TestCase
{
    private function html(): string
    {
        return '<p>Mở đầu ngắn.</p>'
            .'<h2>Dấu hiệu thường gặp</h2><p>Khát nhiều và tiểu nhiều là hai dấu hiệu hay gặp nhất ở người bị đái tháo đường típ 2.[KB#12]</p>'
            .'<h2>Mốc xét nghiệm</h2><p>Đường huyết lúc đói từ 7,0 mmol/L trở lên là mốc chẩn đoán theo hướng dẫn hiện hành.[KB#7]</p><ul><li>HbA1c từ 6,5%</li></ul>'
            .'<h2>Khi nào cần gặp bác sĩ</h2><p>Hãy đi khám khi có dấu hiệu kéo dài nhiều tuần mà không rõ nguyên nhân cụ thể.</p><aside class="autoseo-notes"><ol><li>Ghi chú</li></ol></aside>';
    }

    public function test_tach_phan_mo_dau_va_cac_muc_h2(): void
    {
        $s = SectionSplice::split($this->html());
        $this->assertSame('<p>Mở đầu ngắn.</p>', $s['pre']);
        $this->assertCount(3, $s['sections']);
        $this->assertSame('dấu hiệu thường gặp', SectionSplice::heading($s['sections'][0]));
        $this->assertStringContainsString('<aside', $s['sections'][2], 'Khối sau H2 cuối thuộc mục cuối.');
        $this->assertSame($this->html(), $s['pre'].implode('', $s['sections']), 'Tách rồi nối lại phải ra đúng bản gốc.');
    }

    public function test_tim_dung_muc_chua_cho_loi_ke_ca_khi_co_ma_trich_va_dau_ba_cham(): void
    {
        $sections = SectionSplice::split($this->html())['sections'];
        $gaps = [
            'Khát nhiều và tiểu nhiều là hai dấu hiệu hay gặp nhất ở người bị đái tháo đường típ 2.…',          // CITE01: đoạn mẫu
            '«Đường huyết lúc đói từ 7,0 mmol/L trở lên là mốc chẩn đoán theo hướng dẫn hiện hành.» (KB#7) — mục được trích không nói điều này', // CITE02
        ];
        $this->assertSame([0, 1], SectionSplice::locate($gaps, $sections));
    }

    public function test_khong_chac_thi_tra_null(): void
    {
        $sections = SectionSplice::split($this->html())['sections'];
        $this->assertNull(SectionSplice::locate(['Một câu không hề có trong bài viết này đâu nhé bạn ơi'], $sections), 'Không khớp mục nào.');
        $this->assertNull(SectionSplice::locate(['ngắn quá'], $sections), 'Quá ngắn để khẳng định.');
        $this->assertNull(SectionSplice::locate([], $sections));
        $dup = ['<h2>A</h2><p>Đoạn giống hệt nhau xuất hiện ở hai mục khác nhau.</p>', '<h2>B</h2><p>Đoạn giống hệt nhau xuất hiện ở hai mục khác nhau.</p>'];
        $this->assertNull(SectionSplice::locate(['Đoạn giống hệt nhau xuất hiện ở hai mục khác nhau.'], $dup), 'Khớp nhiều mục thì không đoán.');
        $this->assertNull(SectionSplice::locate(['2 số liệu không có trong mục được trích: 45% 12 mmol. Dùng đúng số của mục kho hoặc bỏ số đó.'], $sections), 'Mô tả không nêu đoạn cụ thể.');
    }

    public function test_doan_mau_ngan_nhu_con_so_van_dinh_vi_duoc_neu_duy_nhat_mot_muc(): void
    {
        $sections = ['<h2>A</h2><p>Mức đường huyết bình thường thấp hơn 5,7% theo HbA1c.</p>', '<h2>B</h2><p>Tiền đái tháo đường nằm trong khoảng 5,9% – 6,4% HbA1c.</p>', '<h2>C</h2><p>Kết luận.</p>'];
        $gap = fn (string $x) => "«{$x}» nêu «{$x}» nhưng mục được trích (KB#1) không có số liệu đó.";
        $this->assertSame([1], SectionSplice::locate([$gap('5,9% – 6,4%')], $sections), 'Số liệu ngắn nhưng chỉ có ở một mục.');
        $this->assertNull(SectionSplice::locate([$gap('HbA1c')], $sections), 'Khớp hai mục thì không đoán.');
        $this->assertNull(SectionSplice::locate(['«5,7%» sai'], $sections), 'Dưới 8 ký tự thì không khẳng định.');
    }

    public function test_ghep_muc_moi_giu_nguyen_phan_con_lai(): void
    {
        $new = '<h2>Mốc xét nghiệm</h2><p>Đường huyết lúc đói từ 7,0 mmol/L trở lên là mốc chẩn đoán theo hướng dẫn.[KB#7]</p>';
        $out = SectionSplice::merge($this->html(), [1], $new, fn (string $h) => $h);
        $this->assertNotNull($out);
        $this->assertStringContainsString('theo hướng dẫn.[KB#7]', $out);
        $this->assertStringNotContainsString('hiện hành', $out);
        $this->assertStringContainsString('<p>Mở đầu ngắn.</p>', $out);
        $this->assertStringContainsString('Khát nhiều và tiểu nhiều', $out, 'Mục không sửa giữ nguyên.');
        $this->assertStringContainsString('<aside class="autoseo-notes">', $out, 'Khối ghi chú cuối bài còn nguyên.');
        $this->assertStringNotContainsString('HbA1c từ 6,5%', $out, 'Mục 1 được thay hoàn toàn bằng bản mới.');
    }

    public function test_lay_dung_muc_yeu_cau_tu_ban_ca_bai_va_bo_phan_con_lai_cua_mo_hinh(): void
    {
        $full = '<h2>Dấu hiệu thường gặp</h2><p>MÔ HÌNH ĐỔI MỤC 0</p><h2>Mốc xét nghiệm</h2><p>Mốc mới, đã sửa đúng chỗ lỗi.[KB#7]</p><h2>Khi nào cần gặp bác sĩ</h2><p>MÔ HÌNH ĐỔI MỤC 2</p>';
        $out = SectionSplice::pick($this->html(), [1], $full, fn (string $h) => $h);
        $this->assertNotNull($out);
        $this->assertStringContainsString('Mốc mới, đã sửa đúng chỗ lỗi.', $out);
        $this->assertStringNotContainsString('MÔ HÌNH ĐỔI', $out, 'Chỉ lấy mục 1; các mục 0 và 2 giữ bản cũ.');
        $this->assertStringContainsString('Khát nhiều và tiểu nhiều', $out);
        $this->assertStringContainsString('<aside class="autoseo-notes">', $out);
        $this->assertNull(SectionSplice::pick($this->html(), [1], '<h2>Tiêu đề khác hẳn</h2><p>x</p>', fn (string $h) => $h), 'Không tìm thấy mục theo tiêu đề thì null.');
    }

    public function test_tu_choi_khi_ket_qua_khong_dung_dinh_dang(): void
    {
        $id = fn (string $h) => $h;
        $ok = '<h2>Mốc xét nghiệm</h2><p>Nội dung mới.</p>';
        $this->assertNull(SectionSplice::merge($this->html(), [1], '<h2>Mốc xét nghiệm KHÁC</h2><p>x</p>', $id), 'Đổi tiêu đề.');
        $this->assertNull(SectionSplice::merge($this->html(), [1], $ok.'<h2>Thừa</h2><p>y</p>', $id), 'Thừa mục.');
        $this->assertNull(SectionSplice::merge($this->html(), [0, 1], $ok, $id), 'Thiếu mục.');
        $this->assertNull(SectionSplice::merge($this->html(), [1], '<p>Chữ nằm ngoài mục</p>'.$ok, $id), 'Có chữ ngoài các mục.');
        $this->assertNull(SectionSplice::merge($this->html(), [9], $ok, $id), 'Chỉ số không tồn tại.');
    }

    public function test_lam_sach_chi_ap_dung_cho_phan_moi(): void
    {
        $new = '<h2>Dấu hiệu thường gặp</h2><p>Nội dung mới.<script>x()</script></p>';
        $out = SectionSplice::merge($this->html(), [0], $new, fn (string $h) => str_replace('<script>x()</script>', '', $h));
        $this->assertStringNotContainsString('<script>', (string) $out);
        $this->assertStringContainsString('class="autoseo-notes"', (string) $out, 'Phần cũ không bị làm sạch lại (giữ thuộc tính đã dựng).');
    }
}
