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
        $this->assertStringContainsString('status === 419', $js, 'Phiên hết hạn: thông báo gọn và tự tải lại thay vì hộp thoại + trang lỗi HTML.');
        $this->assertStringContainsString('sessionExpired', $js);
        $this->assertStringContainsString("BACKGROUND_CALLS = ['loadTabs']", $js, 'Nạp ngầm tab của trang bài không hiện thanh (nội dung đã hiện).');
        $this->assertStringNotContainsString('innerHTML', $js, 'Không chèn HTML.');
    }

    /** Logic thuần JavaScript chạy trong trình duyệt nên PHPUnit chỉ kiểm hằng số/dấu vết mã; hành vi thật đã đo tay (xem báo cáo PR). */
    public function test_thanh_chi_hien_cho_thao_tac_nguoi_dung_khong_hien_cho_poll_nen(): void
    {
        $js = (string) file_get_contents(public_path('js/studio-progress.js'));
        $this->assertMatchesRegularExpression('/SHOW_AFTER_MS = 500\b/', $js, 'Chờ ≥ 0,5 giây mới hiện thanh để việc nhanh không nháy.');
        $this->assertMatchesRegularExpression('/USER_WINDOW_MS = 400\b/', $js, 'Cửa sổ "vừa thao tác" hẹp: poll tình cờ trùng lúc đang bấm không bị tính là việc người dùng.');
        $this->assertStringContainsString('function triggeredByUser', $js);
        $this->assertStringContainsString("closest('[wire\\\\:id]')", $js, 'Thao tác phải nằm trong đúng thành phần Livewire phát lượt gọi (poll của chuông/biểu tượng đội AI thuộc thành phần khác).');
        $this->assertStringNotContainsString("'pointerdown'", $js, 'Không coi pointerdown là thao tác: chỉ click/phím/đổi giá trị/gửi biểu mẫu mới sinh lượt gọi.');
        $this->assertStringContainsString("addEventListener('visibilitychange'", $js, 'Tab ẩn thì tắt thanh.');
        $this->assertStringContainsString('!document.hidden', $js, 'Tab ẩn thì không bật thanh cho lượt gọi mới.');
        $this->assertStringContainsString('myGen !== gen', $js, 'Lượt gọi cũ không được kết thúc nhầm thanh của lượt mới sau khi reset.');
    }

    public function test_nut_vua_bam_hien_dang_xu_ly_va_khoa_bam_lai(): void
    {
        $js = (string) file_get_contents(public_path('js/studio-progress.js'));
        $this->assertStringContainsString("setAttribute('data-ss-busy'", $js, 'Nút người dùng vừa bấm được đánh dấu bận ngay khi gửi lượt Livewire.');
        $this->assertStringContainsString("removeAttribute('data-ss-busy')", $js, 'Mở lại khi có phản hồi hoặc lỗi.');
        $this->assertStringContainsString('[data-ss-busy]{opacity:.7;cursor:progress!important;pointer-events:none}', $js);
        $this->assertStringContainsString("e.target.closest('[data-ss-busy]')", $js, 'Phím Enter/Space trên nút bận không gửi lượt thứ hai.');
        $this->assertStringContainsString('prefers-reduced-motion', $js);
        $this->assertMatchesRegularExpression('/byUser \? busyControl\(\)/', $js, 'Chỉ lượt do người dùng bấm mới khóa nút, wire:poll nền thì không.');
    }

    public function test_moi_file_tinh_cua_giao_dien_co_so_phien_ban_theo_ngay_sua(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();
        foreach (['css/seo-studio.css', 'js/studio-ui.js', 'js/studio-progress.js'] as $f) {
            $this->assertMatchesRegularExpression('#'.preg_quote($f, '#').'\?v='.(int) filemtime(public_path($f)).'#', $html, "$f phải kèm ?v=<ngày sửa> vì trình duyệt giữ file tĩnh 7 ngày.");
        }
    }
}
