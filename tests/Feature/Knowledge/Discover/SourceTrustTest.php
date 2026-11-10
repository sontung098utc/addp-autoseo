<?php

namespace Tests\Feature\Knowledge\Discover;

use App\Models\Brain;
use App\Models\BrainSource;
use App\Services\Knowledge\Discover\SourceTrust;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceTrustTest extends TestCase
{
    use RefreshDatabase;

    private function brain(): Brain
    {
        return Brain::create(['name' => 'Y tế', 'slug' => 'y_te', 'domain_key' => 'y_te', 'language' => 'vi', 'status' => 'active', 'description' => '']);
    }

    public function test_trusted_theo_danh_sach_va_ten_mien_con(): void
    {
        foreach (['https://www.who.int/x', 'https://pubmed.ncbi.nlm.nih.gov/1', 'https://vncdc.gov.vn/a', 'https://abc.edu.vn/b', 'https://www.mayoclinic.org/c'] as $u) {
            $t = SourceTrust::assess($u, null);
            $this->assertSame('trusted', $t['level'], $u);
            $this->assertNotSame('', $t['reason']);
        }
        $this->assertSame('health', SourceTrust::assess('https://nhs.uk/x', null, 'health')['field']);
    }

    public function test_blocked_mang_xa_hoi_cho_dien_dan(): void
    {
        foreach (['https://www.facebook.com/x', 'https://vt.tiktok.com/a', 'https://www.youtube.com/watch?v=1', 'https://quora.com/q', 'https://shopee.vn/p', 'https://lazada.vn/p', 'not a url'] as $u) {
            $this->assertSame('blocked', SourceTrust::assess($u, null)['level'], $u);
        }
    }

    public function test_neutral_con_lai_va_khong_nham_ten_mien_giong(): void
    {
        $this->assertSame('neutral', SourceTrust::assess('https://suckhoe-blog.vn/a', null)['level']);
        $this->assertSame('neutral', SourceTrust::assess('https://notwho.int.fake.com/a', null)['level']);
        $this->assertSame('neutral', SourceTrust::assess('https://fakewho.int/a', null)['level']);
    }

    public function test_so_nguon_xac_nhan_thanh_trusted_va_chan_thang_danh_sach(): void
    {
        $b = $this->brain();
        BrainSource::create(['brain_id' => $b->id, 'name' => 'Hội X', 'domain' => 'hoix.vn', 'tier' => '2', 'status' => 'confirmed']);
        BrainSource::create(['brain_id' => $b->id, 'name' => 'WHO chặn thử', 'domain' => 'who.int', 'tier' => 'blocked', 'status' => 'confirmed']);
        BrainSource::create(['brain_id' => $b->id, 'name' => 'Gợi ý', 'domain' => 'goiy.vn', 'status' => 'suggested']);
        BrainSource::create(['brain_id' => $b->id, 'name' => 'Bậc 4', 'domain' => 'khac.vn', 'tier' => '4', 'status' => 'confirmed']);

        $this->assertSame('trusted', SourceTrust::assess('https://www.hoix.vn/a', $b)['level']);
        $this->assertSame('blocked', SourceTrust::assess('https://who.int/a', $b)['level']);
        $this->assertSame('neutral', SourceTrust::assess('https://goiy.vn/a', $b)['level'], 'chưa xác nhận thì không tính');
        $this->assertSame('neutral', SourceTrust::assess('https://khac.vn/a', $b)['level']);
        $this->assertSame('neutral', SourceTrust::assess('https://hoix.vn/a', null)['level'], 'không có Trí tuệ thì không đọc sổ');
    }
}
