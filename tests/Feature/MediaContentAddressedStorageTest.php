<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\Website;
use App\Services\Media\MediaPathBuilder;
use App\Services\Media\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaContentAddressedStorageTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->tmp = sys_get_temp_dir().'/cas_'.uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp.'/*') ?: []);
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function jpg(string $name, int $r = 10, int $g = 100, int $b = 200): string
    {
        $p = $this->tmp.'/'.$name;
        $im = imagecreatetruecolor(300, 200);
        imagefilledrectangle($im, 0, 0, 300, 200, imagecolorallocate($im, $r, $g, $b));
        imagejpeg($im, $p, 90);

        return $p;
    }

    private function site(string $n): Website
    {
        return Website::create(['name' => $n, 'cms_type' => 'magento2', 'base_url' => "https://{$n}.example.com", 'store_scope' => 'default', 'status' => 'active', 'encrypted_credentials' => ['consumer_key' => 't']]);
    }

    public function test_path_builder_chi_nhan_sha256_hop_le(): void
    {
        $sha = str_repeat('ab', 32);
        $this->assertSame('media/cas/ab/'.$sha, MediaPathBuilder::dir($sha));
        $this->assertTrue(MediaPathBuilder::isCanonical(MediaPathBuilder::original($sha)));
        $this->assertFalse(MediaPathBuilder::isCanonical('media/2026/11/x.webp'));
        $this->expectException(\InvalidArgumentException::class);
        MediaPathBuilder::dir('../../etc/passwd');
    }

    public function test_anh_moi_luu_theo_dia_chi_noi_dung_va_ten_file_khac_nhau_theo_noi_dung(): void
    {
        $svc = app(MediaService::class);
        $a = $svc->storeMedia($this->jpg('a.jpg'));
        $b = $svc->storeMedia($this->jpg('b.jpg', 200, 10, 10));

        $this->assertTrue(MediaPathBuilder::isCanonical($a->file_path));
        $this->assertSame(MediaPathBuilder::original($a->sha256), $a->file_path);
        $this->assertSame(MediaPathBuilder::thumb($a->sha256), $a->thumbnail_path);
        $this->assertNotSame($a->file_name, $b->file_name); // CMS lấy basename làm tên file tải lên
        $this->assertTrue(Storage::disk('public')->exists($a->file_path));
        $this->assertTrue(Storage::disk('public')->exists($a->thumbnail_path));
    }

    public function test_cung_website_dedupe_nhu_cu_va_khac_website_dung_chung_file(): void
    {
        $svc = app(MediaService::class);
        $s1 = $this->site('one');
        $s2 = $this->site('two');
        $f = $this->jpg('a.jpg');

        $a1 = $svc->storeMedia($f, ['website_id' => $s1->id]);
        $a1again = $svc->storeMedia($f, ['website_id' => $s1->id]);
        $a2 = $svc->storeMedia($f, ['website_id' => $s2->id]);
        $g = $svc->storeMedia($f); // dùng chung (website null)

        $this->assertSame($a1->id, $a1again->id);
        $this->assertNotSame($a1->id, $a2->id);
        $this->assertSame($s2->id, $a2->website_id);
        $this->assertSame($a1->file_path, $a2->file_path);
        $this->assertSame($a1->file_path, $g->file_path);
        $this->assertSame($a1->id, $a2->metadata['shared_file_from']);
        $this->assertSame(3, MediaAsset::count());
        $this->assertCount(2, Storage::disk('public')->allFiles()); // 1 ảnh + 1 thumbnail, không nhân bản
        $this->assertSame($a1->width, $a2->width);
    }

    public function test_file_goc_bi_mat_thi_tao_lai_thay_vi_tro_vao_file_khong_ton_tai(): void
    {
        $svc = app(MediaService::class);
        $f = $this->jpg('a.jpg');
        $a1 = $svc->storeMedia($f, ['website_id' => $this->site('one')->id]);
        Storage::disk('public')->delete([$a1->file_path, $a1->thumbnail_path]);

        $a2 = $svc->storeMedia($f, ['website_id' => $this->site('two')->id]);

        $this->assertTrue(Storage::disk('public')->exists($a2->file_path));
        $this->assertNull($a2->metadata['shared_file_from']);
    }

    public function test_lenh_relayout_dry_run_khong_thay_doi_gi_va_apply_chi_sao_chep(): void
    {
        $sha = hash('sha256', 'legacy');
        Storage::disk('public')->put('media/2026/11/old.webp', 'WEBPDATA');
        Storage::disk('public')->put('media/2026/11/old_thumb.webp', 'THUMB');
        $legacy = MediaAsset::create(['disk' => 'public', 'file_path' => 'media/2026/11/old.webp', 'thumbnail_path' => 'media/2026/11/old_thumb.webp', 'file_name' => 'old.webp', 'original_name' => 'o.jpg', 'mime_type' => 'image/webp', 'file_size' => 8, 'sha256' => $sha]);
        $missing = MediaAsset::create(['disk' => 'public', 'file_path' => 'media/2026/11/gone.webp', 'file_name' => 'gone.webp', 'original_name' => 'g.jpg', 'mime_type' => 'image/webp', 'file_size' => 1, 'sha256' => hash('sha256', 'gone')]);
        $before = Storage::disk('public')->allFiles();

        $this->artisan('media:relayout')->assertSuccessful();
        $this->assertSame($before, Storage::disk('public')->allFiles());
        $this->assertNull($legacy->fresh()->metadata);

        $this->artisan('media:relayout --apply')->assertSuccessful();
        $legacy->refresh();
        $this->assertSame('media/2026/11/old.webp', $legacy->file_path); // không đổi
        $this->assertSame('media/2026/11/old_thumb.webp', $legacy->thumbnail_path);
        $this->assertTrue(Storage::disk('public')->exists('media/2026/11/old.webp')); // file cũ còn nguyên
        $this->assertSame(MediaPathBuilder::original($sha), $legacy->metadata['cas_path']);
        $this->assertSame('WEBPDATA', Storage::disk('public')->get($legacy->metadata['cas_path']));
        $this->assertSame('media/2026/11/gone.webp', $missing->fresh()->file_path);

        $this->artisan('media:relayout --apply')->assertSuccessful(); // chạy lại an toàn
        $this->assertCount(4, Storage::disk('public')->allFiles()); // 2 file cũ + 2 bản sao chuẩn, không phát sinh thêm
    }
}
