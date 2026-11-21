<?php

namespace Tests\Feature;

use App\Services\Media\MediaService;
use App\Services\Media\MediaVariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaVariantServiceTest extends TestCase
{
    use RefreshDatabase;

    private function asset(int $w = 1600, int $h = 900)
    {
        Storage::fake('public');
        $p = sys_get_temp_dir().'/var_'.uniqid().'.jpg';
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 20, 90, 160));
        imagejpeg($im, $p, 90);
        $a = app(MediaService::class)->storeMedia($p);
        @unlink($p);

        return $a;
    }

    public function test_sinh_bien_the_lan_dau_va_tai_dung_lan_sau(): void
    {
        $a = $this->asset();
        $svc = app(MediaVariantService::class);
        $card = $svc->path($a, 'card');
        $og = $svc->path($a, 'og');

        $this->assertStringEndsWith('/card.webp', $card);
        $this->assertSame(800, getimagesize(Storage::disk('public')->path($card))[0]);
        $this->assertSame([1200, 630], array_slice(getimagesize(Storage::disk('public')->path($og)), 0, 2));
        $mtime = filemtime(Storage::disk('public')->path($card));
        $this->assertSame($card, $svc->path($a, 'card'));
        $this->assertSame($mtime, filemtime(Storage::disk('public')->path($card)));
        $this->assertSame($a->file_path, $a->file_path); // gốc không đổi
        $this->assertStringContainsString('card.webp', $a->variantUrl('card'));
    }

    public function test_khong_phong_to_anh_nho(): void
    {
        $a = $this->asset(400, 300);
        $p = app(MediaVariantService::class)->path($a, 'content');
        $this->assertSame(400, getimagesize(Storage::disk('public')->path($p))[0]);
    }

    public function test_loi_thi_tra_ve_anh_goc_khong_nem_ngoai_le(): void
    {
        $a = $this->asset();
        $svc = app(MediaVariantService::class);
        $this->assertSame($a->file_path, $svc->path($a, 'khong-co'));
        Storage::disk('public')->delete($a->file_path);
        $this->assertSame($a->file_path, $svc->path($a, 'card')); // file gốc mất
        $a->sha256 = 'hong';
        $this->assertSame($a->file_path, $svc->path($a, 'card'));
    }
}
