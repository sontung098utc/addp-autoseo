<?php

namespace Tests\Unit;

use App\Services\Content\ReferenceListRenderer;
use PHPUnit\Framework\TestCase;

class ReferenceListRendererTest extends TestCase
{
    public function test_them_khoi_nguon_chi_voi_lien_ket_http_that(): void
    {
        $out = ReferenceListRenderer::append('<p>a</p>', [
            ['title' => 'Hướng dẫn', 'domain' => 'who.int', 'url' => 'https://www.who.int/x?a=1&b=2'],
            ['title' => 'Xấu', 'domain' => 'e.com', 'url' => 'javascript:alert(1)'],
        ]);
        $this->assertStringContainsString('Nguồn tham khảo', $out);
        $this->assertStringContainsString('href="https://www.who.int/x?a=1&amp;b=2"', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertSame('<p>a</p>', ReferenceListRenderer::append('<p>a</p>', []));
    }

    public function test_noi_tiep_vao_khoi_nguon_co_san(): void
    {
        $html = '<p>a</p><div class="autoseo-refs"><p><strong>Nguồn tham khảo</strong></p><ol><li>Cũ</li></ol></div>';
        $out = ReferenceListRenderer::append($html, [['title' => 'Mới', 'domain' => 'nhs.uk', 'url' => 'https://nhs.uk/y']]);
        $this->assertSame(1, substr_count($out, 'autoseo-refs'));
        $this->assertStringContainsString('<li>Cũ</li><li><a href="https://nhs.uk/y"', $out);
    }

    public function test_dau_ma_tai_lieu_thanh_chu_thich_so_bam_duoc_va_dong_nguon_co_id(): void
    {
        $all = [
            ['title' => 'Hướng dẫn A', 'domain' => 'who.int', 'url' => 'https://who.int/a'],
            ['title' => 'Nghiên cứu B', 'domain' => 'nih.gov', 'url' => 'https://nih.gov/b'],
            ['title' => 'Trang C', 'domain' => 'moh.gov.vn', 'url' => 'https://moh.gov.vn/c'],
        ];
        $out = ReferenceListRenderer::apply('<p>Mốc 90/60.[T2] Khác.[T1, T2] Sai.[T9]</p>', $all, [$all[0], $all[2]]);
        $this->assertStringContainsString('<sup class="autoseo-cite" title="Nguồn: Nghiên cứu B (nih.gov)"><a href="#nguon-1">[1]</a></sup>', $out);
        $this->assertStringContainsString('<a href="#nguon-2">[2]</a>', $out, '[T1] là nguồn thứ hai được trích nên mang số 2.');
        $this->assertStringNotContainsString('[T', $out, 'Dấu thô và mã không có thật không được lọt ra bài.');
        $this->assertStringContainsString('<li id="nguon-1"><a href="https://nih.gov/b"', $out);
        $this->assertStringContainsString('<li id="nguon-2"><a href="https://who.int/a"', $out);
        $this->assertStringContainsString('<li><a href="https://moh.gov.vn/c"', $out, 'Nguồn được dùng nhưng chưa có dấu trích vẫn được liệt kê, không đánh số neo.');
    }

    public function test_danh_so_tiep_sau_chu_thich_kho(): void
    {
        $html = '<p>A<sup class="autoseo-cite"><a href="#nguon-1">[1]</a></sup> B.[T1]</p><div class="autoseo-refs"><p><strong>Nguồn tham khảo</strong></p><ol><li id="nguon-1">Kho</li></ol></div>';
        $out = ReferenceListRenderer::apply($html, [['title' => 'T', 'domain' => 'who.int', 'url' => 'https://who.int/a']], []);
        $this->assertStringContainsString('<a href="#nguon-2">[2]</a>', $out);
        $this->assertSame(1, substr_count($out, 'autoseo-refs'));
        $this->assertStringContainsString('<li id="nguon-2"><a href="https://who.int/a"', $out);
    }
}
