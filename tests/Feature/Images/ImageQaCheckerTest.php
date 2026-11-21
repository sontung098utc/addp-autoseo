<?php

namespace Tests\Feature\Images;

use App\Models\MediaAsset;
use App\Models\Website;
use App\Services\Images\ImageQaChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Kiểm ảnh bằng luật: bắt ảnh trống, trùng, thấp phân giải, sai tỉ lệ, thiếu alt; nhắc xem chữ khi ảnh có chữ. */
class ImageQaCheckerTest extends TestCase
{
    use RefreshDatabase;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->site = Website::create(['name' => 'Y', 'cms_type' => 'wordpress', 'base_url' => 'https://y.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
    }

    private function png(int $w, int $h, bool $blank): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 240, 240, 240));
        if (! $blank) {
            for ($i = 0; $i < 40; $i++) {
                imagefilledellipse($im, random_int(0, $w), random_int(0, $h), random_int(20, 200), random_int(20, 200), imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
            }
        }
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function asset(string $name, int $w, int $h, bool $blank, string $alt = 'Sơ đồ đĩa ăn cân đối cho người tiểu đường', string $sha = ''): MediaAsset
    {
        $bytes = $this->png($w, $h, $blank);
        Storage::disk('public')->put('m/'.$name, $bytes);

        return MediaAsset::create(['website_id' => $this->site->id, 'disk' => 'public', 'file_path' => 'm/'.$name, 'file_name' => $name, 'original_name' => $name, 'mime_type' => 'image/png', 'file_size' => strlen($bytes),
            'width' => $w, 'height' => $h, 'sha256' => $sha ?: hash('sha256', $bytes), 'title' => 't', 'alt_text' => $alt, 'metadata' => ['model' => 'real-image']]);
    }

    public function test_anh_tot_khong_loi_va_anh_xau_bi_bat(): void
    {
        $good = $this->asset('good.png', 1280, 720, false);
        $blank = $this->asset('blank.png', 1280, 720, true);
        $small = $this->asset('small.png', 300, 169, false);
        $wrongAspect = $this->asset('tall.png', 800, 1200, false);
        $noAlt = $this->asset('noalt.png', 1280, 720, false, 'x');
        $slots = [
            ['id' => 'cover', 'role' => 'featured', 'aspect' => '16:9'], ['id' => 's1', 'role' => 'inline', 'aspect' => '16:9'], ['id' => 's2', 'role' => 'inline', 'aspect' => '16:9'],
            ['id' => 's3', 'role' => 'inline', 'aspect' => '16:9'], ['id' => 's4', 'role' => 'inline', 'aspect' => '16:9'],
        ];
        $items = ['cover' => ['status' => 'ok', 'asset_id' => $good->id], 's1' => ['status' => 'ok', 'asset_id' => $blank->id], 's2' => ['status' => 'ok', 'asset_id' => $small->id],
            's3' => ['status' => 'ok', 'asset_id' => $wrongAspect->id], 's4' => ['status' => 'ok', 'asset_id' => $noAlt->id]];
        $r = ImageQaChecker::check($slots, $items);
        $by = collect($r['rows'])->keyBy('slot');
        $this->assertSame([], $by['cover']['issues']);
        $this->assertContains('nearly_blank', $by['s1']['issues']);
        $this->assertContains('low_res', $by['s2']['issues']);
        $this->assertContains('aspect', $by['s3']['issues']);
        $this->assertContains('no_alt', $by['s4']['issues']);
        $this->assertGreaterThanOrEqual(4, $r['serious']);
        $this->assertStringContainsString('Ảnh bìa', ImageQaChecker::check($slots, ['cover' => ['status' => 'ok', 'asset_id' => $blank->id]])['summary']);
    }

    public function test_hai_anh_trung_nhau_va_anh_co_chu_duoc_nhac(): void
    {
        $a = $this->asset('a.png', 1280, 720, false, sha: str_repeat('a', 64));
        $b = $this->asset('b.png', 1280, 720, false, sha: str_repeat('a', 64));
        $slots = [['id' => 'cover', 'role' => 'featured', 'aspect' => '16:9', 'overlay_text' => ['Ăn gì khi bị tiểu đường']], ['id' => 's1', 'role' => 'inline', 'aspect' => '16:9']];
        $r = ImageQaChecker::check($slots, ['cover' => ['status' => 'ok', 'asset_id' => $a->id], 's1' => ['status' => 'ok', 'asset_id' => $b->id]]);
        $by = collect($r['rows'])->keyBy('slot');
        $this->assertContains('text_check', $by['cover']['issues']);
        $this->assertContains('duplicate', $by['s1']['issues']);
        $this->assertStringContainsString('Ăn gì khi bị tiểu đường', $r['summary']);
        $this->assertSame(1, $r['serious'], 'nhắc xem chữ không tính là lỗi nặng');
    }

    public function test_anh_that_bai_hoac_khong_co_thi_khong_ghi_gi(): void
    {
        $this->assertSame('', ImageQaChecker::check([['id' => 'cover', 'role' => 'featured']], ['cover' => ['status' => 'error']])['summary']);
    }
}
