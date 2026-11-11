<?php

namespace Tests\Feature\Knowledge;

use App\Filament\Pages\KnowledgePage;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Services\Knowledge\KnowledgeDedupe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** AG-S20: màn Kho kiến thức — render, quyền, hộp duyệt chặn mâu thuẫn, XSS, phiên bản khi sửa. */
class KnowledgePageTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => true]);
    }

    private function item(array $a = []): KnowledgeItem
    {
        $a += ['type' => 'fact', 'title' => 'Mục', 'body' => 'Nội dung mục kiến thức đủ dài.', 'status' => 'proposed', 'version' => 1, 'created_by' => 'rule'];
        $a['content_hash'] = KnowledgeDedupe::hash($a['title'], $a['body']);

        return KnowledgeItem::create($a);
    }

    public function test_trang_render_va_quyen_truy_cap(): void
    {
        $this->actingAs($this->user('admin'));
        $this->assertTrue(KnowledgePage::canAccess());
        Livewire::test(KnowledgePage::class)->assertOk()->assertSee('Hộp duyệt')->assertSee('Cảnh báo')->assertSee('Tài liệu');
        $this->get('/admin/tri-thuc-tat-ca')->assertOk(); // AG-B1: URL cũ /admin/kho-kien-thuc chuyển hướng sang Kho trí tuệ
        $this->get('/admin/kho-kien-thuc')->assertRedirect('/admin/kho-tri-tue/tri-tue');

        auth()->logout();
        $this->assertFalse(KnowledgePage::canAccess());
        $this->get('/admin/tri-thuc-tat-ca')->assertRedirect();
    }

    public function test_bien_tap_vien_chi_xem_muc_da_duyet_va_khong_thao_tac(): void
    {
        $this->item(['title' => 'Nháp bí mật', 'status' => 'proposed']);
        $ok = $this->item(['title' => 'Đã duyệt công khai', 'status' => 'approved']);
        $this->actingAs($this->user('writer'));
        Livewire::test(KnowledgePage::class)->assertOk()
            ->assertSee('Đã duyệt công khai')->assertDontSee('Nháp bí mật')->assertDontSee('Hộp duyệt')
            ->call('approve', $ok->id)->assertForbidden();
        Livewire::test(KnowledgePage::class)->call('pasteDoc')->assertForbidden();
        Livewire::test(KnowledgePage::class)->call('openItem', KnowledgeItem::where('status', 'proposed')->value('id'))->assertForbidden();
    }

    public function test_hop_duyet_chan_khi_con_mau_thuan_roi_cho_chon_ban_dung(): void
    {
        $this->actingAs($this->user('approver'));
        $a = $this->item(['title' => 'Giá A', 'body' => 'Giá gói cơ bản là một triệu.', 'status' => 'approved', 'entity' => 'Gói', 'field' => 'giá', 'value' => '1']);
        $b = $this->item(['title' => 'Giá B', 'body' => 'Giá gói cơ bản là hai triệu.', 'entity' => 'Gói', 'field' => 'giá', 'value' => '2']);
        $c = Livewire::test(KnowledgePage::class)->assertSee('Mâu thuẫn')->call('approve', $b->id);
        $this->assertSame('proposed', $b->refresh()->status, 'bị chặn');
        $c->call('pickCorrect', $b->id);
        $this->assertSame('approved', $b->refresh()->status);
        $this->assertSame('outdated', $a->refresh()->status);
    }

    public function test_dan_tai_lieu_tach_va_duyet_ca_nguon(): void
    {
        $this->actingAs($this->user('admin'));
        Livewire::test(KnowledgePage::class)
            ->set('paste.title', 'Chính sách')->set('paste.text', "Hoàn tiền\nHoàn tiền trong bảy ngày làm việc.\n\nBảo hành\nBảo hành mười hai tháng cho mọi sản phẩm.")
            ->call('pasteDoc');
        $src = KnowledgeSource::first();
        $this->assertSame(2, KnowledgeItem::where('source_id', $src->id)->where('status', 'proposed')->count());
        Livewire::test(KnowledgePage::class)->call('approveSource', $src->id);
        $this->assertSame(2, KnowledgeItem::where('status', 'approved')->count());
    }

    public function test_them_tay_mot_muc_vao_hop_duyet(): void
    {
        $this->actingAs($this->user('admin'));
        Livewire::test(KnowledgePage::class)->set('new.title', 'Giá')->set('new.body', 'Gói cơ bản 8 triệu')->set('new.tags', 'giá, gói')->call('addItem');
        $i = KnowledgeItem::first();
        $this->assertSame('proposed', $i->status);
        $this->assertSame(['giá', 'gói'], $i->tags);
    }

    public function test_sua_muc_da_duyet_tao_phien_ban_moi_qua_giao_dien(): void
    {
        $this->actingAs($this->user('admin'));
        $i = $this->item(['status' => 'approved']);
        Livewire::test(KnowledgePage::class)->call('openItem', $i->id)->call('startEdit')->set('edit.body', 'Nội dung đã thay đổi hoàn toàn.')->call('saveEdit');
        $i->refresh();
        $this->assertSame(2, $i->version);
        $this->assertSame('proposed', $i->status);
        $this->assertSame(2, $i->versions()->count());
    }

    public function test_xss_noi_dung_kho_va_nguon_duoc_escape(): void
    {
        $this->actingAs($this->user('admin'));
        $src = KnowledgeSource::create(['kind' => 'paste', 'title' => '<script>alert(9)</script>', 'raw_text' => '<img src=x onerror=alert(1)> Nội dung độc hại đủ dài', 'status' => 'split']);
        $i = $this->item(['title' => '<script>alert(1)</script>', 'body' => '<img src=x onerror=alert(2)> Nội dung độc hại đủ dài', 'source_id' => $src->id]);
        $html = Livewire::test(KnowledgePage::class)->call('openItem', $i->id)->html();
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_chi_tiet_hien_so_bai_da_dung(): void
    {
        $this->actingAs($this->user('admin'));
        $i = $this->item(['status' => 'approved']);
        Livewire::test(KnowledgePage::class)->call('openItem', $i->id)->assertSee('Đã dùng trong 0 bài')->assertSee('Lịch sử phiên bản');
    }
}
