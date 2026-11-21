<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\MediaCollection;
use App\Services\Media\MediaHousekeeping;
use App\Services\Media\MediaPathBuilder;
use App\Services\Media\MediaSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaHousekeepingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function asset(string $key, array $o = []): MediaAsset
    {
        $created = $o['created_at'] ?? null;
        unset($o['created_at']);
        $a = MediaAsset::create($o + ['disk' => 'public', 'file_path' => "media/$key.webp", 'file_name' => "$key.webp", 'original_name' => "$key.jpg", 'mime_type' => 'image/webp', 'file_size' => 100, 'sha256' => hash('sha256', $key), 'title' => $key, 'width' => 800, 'height' => 600]);
        if ($created) {
            $a->forceFill(['created_at' => $created])->save();
        }

        return $a;
    }

    public function test_bao_cao_thieu_file_mo_coi_trung_va_chua_dung_khong_xoa_gi(): void
    {
        Storage::disk('public')->put('media/ok.webp', 'x');
        Storage::disk('public')->put('media/mo-coi.webp', 'x');
        $sha = hash('sha256', 'cas');
        $dir = MediaPathBuilder::dir($sha);
        Storage::disk('public')->put("$dir/".MediaPathBuilder::fileName($sha), 'x');
        Storage::disk('public')->put("$dir/card.webp", 'x'); // biến thể sinh lười: không phải mồ côi
        $ok = $this->asset('ok', ['created_at' => now()->subDays(200)]);
        $this->asset('thieu'); // file không tồn tại
        $this->asset('cas', ['sha256' => $sha, 'file_path' => "$dir/".MediaPathBuilder::fileName($sha)]);
        $this->asset('dup1', ['sha256' => hash('sha256', 'd'), 'file_path' => 'media/d1.webp']);
        $this->asset('dup2', ['sha256' => hash('sha256', 'd'), 'file_path' => 'media/d2.webp']);

        $before = Storage::disk('public')->allFiles();
        $r = app(MediaHousekeeping::class)->report(90);

        $this->assertSame(['media/mo-coi.webp'], $r['orphan_files']);
        $this->assertContains('media/thieu.webp', array_column($r['missing'], 'file_path'));
        $this->assertCount(1, $r['duplicates']);
        $this->assertSame([$ok->id], array_column($r['unused'], 'id')); // chỉ ảnh đủ cũ
        $this->assertSame($before, Storage::disk('public')->allFiles()); // không xóa file nào
        $this->assertSame(5, MediaAsset::count()); // không xóa bản ghi nào
    }

    public function test_lenh_housekeeping_chi_bao_cao_va_luu_tru_khong_xoa(): void
    {
        Storage::disk('public')->put('media/ok.webp', 'x');
        $a = $this->asset('ok', ['created_at' => now()->subDays(200)]);

        $this->artisan('media:housekeeping')->assertSuccessful();
        $this->assertSame('ready', $a->fresh()->status);

        $this->assertSame(1, app(MediaHousekeeping::class)->archiveUnused(90));
        $this->assertSame('archived', $a->fresh()->status);
        $this->assertTrue(Storage::disk('public')->exists('media/ok.webp'));
        $this->assertNotNull(MediaAsset::find($a->id));
    }

    public function test_goi_y_phan_loai_la_json_co_cau_truc_chi_ap_dung_khi_duyet(): void
    {
        $f = MediaCollection::create(['name' => 'Sofa', 'slug' => 'sofa']);
        $a = $this->asset('x', ['title' => 'Sofa da nau', 'width' => 600, 'height' => 1200 * 1.0, 'tags' => ['phòng khách']]);
        $a->update(['height' => 1100]);
        $s = app(MediaSuggester::class)->store($a->refresh());

        $this->assertSame('infographic', $s['kind']);
        $this->assertSame($f->id, $s['folder_id']);
        $this->assertSame(['phòng khách'], $s['tags']);
        $this->assertNotEmpty($s['reasons']);
        $a->refresh();
        $this->assertSame(['photo', null], [$a->kind, $a->folder_id]); // chưa áp dụng thì không đổi gì
        $this->assertSame($s, $a->metadata['suggestion']);

        $this->assertTrue(app(MediaSuggester::class)->apply($a));
        $a->refresh();
        $this->assertSame(['infographic', $f->id], [$a->kind, $a->folder_id]);
        $this->assertArrayNotHasKey('suggestion', (array) $a->metadata);
    }

    public function test_ap_dung_bo_qua_gia_tri_khong_hop_le_trong_goi_y(): void
    {
        $a = $this->asset('y', ['metadata' => ['suggestion' => ['kind' => 'rm -rf', 'folder_id' => 99999]]]);
        app(MediaSuggester::class)->apply($a);
        $a->refresh();
        $this->assertSame(['photo', null], [$a->kind, $a->folder_id]);
    }
}
