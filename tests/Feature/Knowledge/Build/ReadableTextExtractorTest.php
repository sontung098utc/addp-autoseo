<?php

namespace Tests\Feature\Knowledge\Build;

use App\Services\Knowledge\Build\ReadableTextExtractor;
use Tests\TestCase;

class ReadableTextExtractorTest extends TestCase
{
    private const HTML = '<!doctype html><html><head><meta charset="utf-8"><title>Giới thiệu – Công ty Ánh Dương</title><style>.x{color:red}</style><script>var a="đừng đọc";</script></head>
<body><header><a href="/">Trang chủ MENU-ĐẦU</a></header><nav><ul><li>Menu điều hướng</li></ul></nav>
<main><article><header><h1>Về chúng tôi</h1></header>
<p>Công ty Ánh Dương thành lập năm 2010, chuyên   tư vấn
 du học.</p><h2>Dịch vụ</h2><ul><li>Tư vấn hồ sơ</li><li>Luyện phỏng vấn</li></ul>
<table><tr><th>Gói</th><th>Thời gian</th></tr><tr><td>Cơ bản</td><td>3 tháng</td></tr></table>
<form><input name="q"><button>Gửi</button></form></article></main>
<aside>Quảng cáo bên lề</aside><footer>© Chân trang</footer><script>alert(1)</script></body></html>';

    public function test_giu_tieu_de_va_doan_bo_khung(): void
    {
        $r = (new ReadableTextExtractor)->extract(self::HTML);
        $this->assertSame('Giới thiệu – Công ty Ánh Dương', $r['title']);
        $this->assertSame(['Về chúng tôi', 'Dịch vụ'], $r['headings']);
        $this->assertStringContainsString('Công ty Ánh Dương thành lập năm 2010, chuyên tư vấn du học.', $r['text']);
        $this->assertStringContainsString("Tư vấn hồ sơ\nLuyện phỏng vấn", $r['text']);
        foreach (['MENU-ĐẦU', 'Menu điều hướng', 'Quảng cáo', 'Chân trang', 'đừng đọc', 'alert', 'color:red', 'Gửi'] as $bad) {
            $this->assertStringNotContainsString($bad, $r['text']);
        }
    }

    public function test_bang_thanh_dong(): void
    {
        $r = (new ReadableTextExtractor)->extract(self::HTML);
        $this->assertStringContainsString("Gói | Thời gian\nCơ bản | 3 tháng", $r['text']);
    }

    public function test_tat_dinh_va_khong_de_html(): void
    {
        $x = new ReadableTextExtractor;
        $this->assertSame($x->extract(self::HTML), $x->extract(self::HTML));
        $this->assertStringNotContainsString('<', $x->extract(self::HTML)['text']);
    }

    public function test_khong_co_title_lay_h1_va_rong_thi_rong(): void
    {
        $r = (new ReadableTextExtractor)->extract('<body><h1>Tiêu đề &amp; Đường dẫn</h1><p>Nội dung</p></body>');
        $this->assertSame('Tiêu đề & Đường dẫn', $r['title']);
        $this->assertSame(['title' => '', 'text' => '', 'headings' => []], (new ReadableTextExtractor)->extract('  '));
    }

    public function test_html_hong_van_doc_duoc(): void
    {
        $r = (new ReadableTextExtractor)->extract('<div><p>Đoạn một<p>Đoạn hai<div>Tổ ong');
        $this->assertStringContainsString("Đoạn một\nĐoạn hai\nTổ ong", $r['text']);
    }
}
