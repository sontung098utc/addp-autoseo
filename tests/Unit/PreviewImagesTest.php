<?php

namespace Tests\Unit;

use App\Filament\Support\Article\PreviewImages;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Khung xem trước (sandbox rỗng) không tải được ảnh từ website: ảnh thư viện được nhúng data URI, còn lại giữ nguyên. */
class PreviewImagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.disks.public.url' => 'http://site.test/storage']);
        Storage::fake('public');
        Storage::disk('public')->put('media/2026/11/a.webp', 'RIFFxxxxWEBP');
    }

    public function test_nhung_anh_thu_vien_thanh_data_uri(): void
    {
        $out = PreviewImages::inline('<p>x</p><img src="http://site.test/storage/media/2026/11/a.webp" alt="a">');

        $this->assertStringContainsString('src="data:image/webp;base64,'.base64_encode('RIFFxxxxWEBP').'"', $out);
        $this->assertStringContainsString('alt="a"', $out);
    }

    public function test_giu_nguyen_anh_ngoai_thu_vien_va_duong_dan_la(): void
    {
        foreach ([
            'https://other.test/media/2026/11/a.webp', // không có /storage/ trong đường dẫn: ảnh ngoài thật sự
            'http://site.test/storage/media/2026/11/khong-co.webp',
            'http://site.test/storage/secret/a.webp',
            'http://site.test/storage/media/../.env',
            'http://site.test/storage/media/2026/11/a.php',
        ] as $src) {
            $html = '<img src="'.$src.'">';
            $this->assertSame($html, PreviewImages::inline($html), $src);
        }
    }

    public function test_gioi_han_so_anh_nhung(): void
    {
        $html = str_repeat('<img src="http://site.test/storage/media/2026/11/a.webp">', PreviewImages::MAX_IMAGES + 2);
        $this->assertSame(PreviewImages::MAX_IMAGES, substr_count(PreviewImages::inline($html), 'data:image/webp'));
    }

    public function test_anh_cung_o_public_nhung_khac_dia_chi_goc_van_duoc_nhung(): void
    {
        // Bài tạo từ tiến trình có APP_URL khác (ví dụ http://localhost:8000): vẫn đọc từ thư mục media cục bộ, không tải gì từ ngoài.
        $out = PreviewImages::inline('<img src="http://localhost:8000/storage/media/2026/11/a.webp">');
        $this->assertStringContainsString('data:image/webp;base64,', $out);
        $this->assertSame('<img src="http://localhost:8000/storage/media/2026/11/khong-co.webp">', PreviewImages::inline('<img src="http://localhost:8000/storage/media/2026/11/khong-co.webp">'));
        $this->assertSame('<img src="http://x.test/storage/media/../.env">', PreviewImages::inline('<img src="http://x.test/storage/media/../.env">'));
    }
}
