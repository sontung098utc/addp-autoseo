<?php

namespace Tests\Feature\Filament;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Thanh tiến độ ở mép trên khi chuyển trang/chờ máy chủ: script phải được nạp ở mọi trang quản trị và giữ các bảo vệ quan trọng. */
class StudioProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_trang_dang_nhap_nap_script_thanh_tien_do_kem_phien_ban(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#js/studio-progress\.js\?v=\d+#', $html);
    }

    public function test_script_ton_tai_va_giu_cac_bao_ve(): void
    {
        $js = (string) file_get_contents(public_path('js/studio-progress.js'));
        $this->assertStringContainsString("id = 'ss-progress'", $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js, 'Tôn trọng người giảm chuyển động.');
        $this->assertStringContainsString('defaultPrevented', $js, 'Không chạy khi liên kết đã bị xử lý bằng JavaScript.');
        $this->assertStringContainsString("wire:navigate", $js, 'Không chồng với thanh của Livewire khi dùng wire:navigate.');
        $this->assertStringContainsString("'_blank'", (string) preg_replace('/"/', "'", $js).'', 'Không chạy với liên kết mở tab mới.');
        $this->assertStringContainsString('leaving', $js, 'Xử lý trường hợp người dùng hủy rời trang (hộp thoại chưa lưu).');
        $this->assertStringContainsString('lastUser', $js, 'Chỉ hiện cho thao tác Livewire do người dùng vừa làm, không cho wire:poll (biểu tượng đội AI tự làm mới 5 giây/lần).');
        $this->assertStringContainsString('MAX_MS', $js, 'Chốt an toàn: không treo thanh mãi (vd. liên kết tải file).');
        $this->assertStringContainsString('ss-nav-progress-v', $js, 'Trang mới tiếp tục từ chỗ trang cũ dừng, không nhảy lùi.');
        $this->assertStringContainsString('function snap', $js, 'Về 0 không trượt ngược.');
        $this->assertStringContainsString("addEventListener('beforeunload'", $js, 'Tải lại trang (F5), gõ địa chỉ, Back/Forward cũng có thanh.');
        $this->assertStringContainsString("addEventListener('DOMContentLoaded', fin", $js, 'Hoàn tất khi nội dung đã sẵn sàng, không chờ sự kiện load (ảnh, phông chữ ngoài).');
        $this->assertStringNotContainsString('innerHTML', $js, 'Không chèn HTML.');
    }

    public function test_moi_file_tinh_cua_giao_dien_co_so_phien_ban_theo_ngay_sua(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();
        foreach (['css/seo-studio.css', 'js/studio-ui.js', 'js/studio-progress.js'] as $f) {
            $this->assertMatchesRegularExpression('#'.preg_quote($f, '#').'\?v='.(int) filemtime(public_path($f)).'#', $html, "$f phải kèm ?v=<ngày sửa> vì trình duyệt giữ file tĩnh 7 ngày.");
        }
    }
}
