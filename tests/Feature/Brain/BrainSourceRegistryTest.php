<?php

namespace Tests\Feature\Brain;

use App\Models\Brain;
use App\Models\BrainSource;
use App\Models\User;
use App\Services\Brain\BrainService;
use App\Services\Brain\BrainSourceRegistry;
use App\Services\Brain\BrainTemplates;
use App\Services\Knowledge\KnowledgeError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AG-B3 (Kho trí tuệ P2, Tầng 3): Căn cứ chuẩn mực. Hệ thống không tự khẳng định nguồn: chỉ nguồn do người xác nhận + có bậc mới có hiệu lực. */
class BrainSourceRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'approver'): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@p2.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function brain(): Brain
    {
        return Brain::create(['name' => 'Y', 'slug' => 'y-'.uniqid(), 'status' => 'active', 'settings' => ['ai_reliance' => 'must_cite', 'risk_class' => 'high']]);
    }

    public function test_mau_suc_khoe_nhap_goi_y_nguon_o_dang_chua_xac_nhan_va_khong_duoc_tinh(): void
    {
        $key = BrainTemplates::HEALTH_KEYS[0];
        $expected = count(BrainTemplates::get($key)['suggested_sources']);
        $this->assertGreaterThan(0, $expected);

        $brain = (new BrainService)->createFromTemplate('Tim mạch', $key, [], $this->user());
        $rows = BrainSource::query()->where('brain_id', $brain->id)->get();
        $this->assertCount($expected, $rows);
        $this->assertSame(['suggested'], $rows->pluck('status')->unique()->all());
        $this->assertSame([null], $rows->pluck('tier')->unique()->all(), 'gợi ý chưa có bậc');

        $reg = BrainSourceRegistry::forBrains([$brain->id]);
        $this->assertTrue($reg->isEmpty(), 'chưa xác nhận thì sổ rỗng');
        $this->assertNull($reg->classify('https://'.$rows->first()->domain.'/bai-viet'), 'gợi ý KHÔNG được tính là nguồn');
    }

    public function test_nhap_lai_goi_y_khong_nhan_doi_va_khong_dung_hang_da_xac_nhan(): void
    {
        $brain = (new BrainService)->createFromTemplate('Tim mạch', BrainTemplates::HEALTH_KEYS[0], [], $this->user());
        $reg = new BrainSourceRegistry;
        $first = BrainSource::query()->where('brain_id', $brain->id)->first();
        $reg->confirm($first, '1', $this->user());

        $this->assertSame(0, $reg->importSuggested($brain->refresh()));
        $this->assertSame('confirmed', $first->refresh()->status);
        $this->assertSame('1', $first->tier);
    }

    public function test_xac_nhan_bat_buoc_co_bac_va_ghi_nguoi_xac_nhan(): void
    {
        $brain = $this->brain();
        $u = $this->user();
        $s = (new BrainSourceRegistry)->add($brain, 'Bộ Y tế', 'moh.gov.vn', null, null, $u);
        $this->assertSame('suggested', $s->status);

        $this->expectException(KnowledgeError::class);
        try {
            (new BrainSourceRegistry)->confirm($s, '', $u);
        } finally {
            $this->assertSame('suggested', $s->refresh()->status);
        }
    }

    public function test_them_co_bac_la_xac_nhan_luon_va_phan_loai_theo_ten_mien_va_ten(): void
    {
        $brain = $this->brain();
        $u = $this->user();
        $r = new BrainSourceRegistry;
        $r->add($brain, 'Bộ Y tế', 'https://www.MOH.gov.vn/', '1', 'Cơ quan quản lý', $u);
        $r->add($brain, 'Tạp chí ABC', 'abc-journal.org', '2', null, $u);
        $r->add($brain, 'Blog quảng cáo', 'spam-thuoc.vn', 'blocked', null, $u);
        $r->add($brain, 'Báo X', 'baox.vn', '3', null, $u);

        $reg = BrainSourceRegistry::forBrains([$brain->id]);
        $this->assertSame('1', $reg->classify('https://moh.gov.vn/van-ban/123')['tier']);
        $this->assertSame('1', $reg->classify('https://sub.moh.gov.vn/x')['tier'], 'tên miền con khớp');
        $this->assertNull($reg->classify('https://notmoh.gov.vn/x'), 'tên miền chỉ trùng đuôi chữ thì KHÔNG khớp');
        $this->assertNull($reg->classify('https://moh.gov.vn.evil.com/x'));
        $this->assertSame('2', $reg->classify('abc-journal.org')['tier']);
        $this->assertSame('2', $reg->classify('Tạp chí ABC')['tier'], 'khớp theo tên (bỏ dấu/hoa thường)');
        $this->assertSame('blocked', $reg->classify('http://spam-thuoc.vn/a')['tier']);
        $this->assertNull($reg->classify('một nguồn lạ'));
        $this->assertCount(3, $reg->citable(), 'nguồn bị chặn không nằm trong danh sách được trích');
    }

    public function test_ten_mien_sai_trung_lap_va_rut_xac_nhan(): void
    {
        $brain = $this->brain();
        $u = $this->user();
        $r = new BrainSourceRegistry;
        foreach (['', 'không phải tên miền', 'a b.com', 'localhost'] as $bad) {
            try {
                $r->add($brain, 'X', $bad, '1', null, $u);
                $this->fail("phải từ chối: $bad");
            } catch (KnowledgeError) {
                $this->assertTrue(true);
            }
        }
        $s = $r->add($brain, 'Bộ Y tế', 'moh.gov.vn', '1', null, $u);
        try {
            $r->add($brain, 'Bộ Y tế 2', 'www.moh.gov.vn', '1', null, $u);
            $this->fail('trùng tên miền');
        } catch (KnowledgeError $e) {
            $this->assertStringContainsString('đã có', $e->getMessage());
        }
        $r->unconfirm($s, $u);
        $this->assertTrue(BrainSourceRegistry::forBrains([$brain->id])->isEmpty());
    }

    public function test_bien_tap_vien_khong_duoc_sua_so_nguon(): void
    {
        $brain = $this->brain();
        $this->expectException(KnowledgeError::class);
        (new BrainSourceRegistry)->add($brain, 'Bộ Y tế', 'moh.gov.vn', '1', null, $this->user('writer'));
    }

    public function test_xoa_tri_tue_xoa_theo_so_nguon_cua_no(): void
    {
        $brain = $this->brain();
        (new BrainSourceRegistry)->add($brain, 'Bộ Y tế', 'moh.gov.vn', '1', null, $this->user());
        $this->assertSame(1, BrainSource::count());
        $brain->delete();
        $this->assertSame(0, BrainSource::count());
    }
}
