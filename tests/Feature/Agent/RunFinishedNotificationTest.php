<?php

namespace Tests\Feature\Agent;

use App\Livewire\NotificationToaster;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Notify\RunFinishedNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Xong bài phải báo cho người dùng: (1) chuông cạnh ảnh đại diện (bảng notifications), (2) hộp nổi góc trên bên phải. */
class RunFinishedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, bool $active = true): User
    {
        return User::create(['name' => $role, 'email' => uniqid().'@x.vn', 'password' => 'secret123', 'role' => $role, 'is_active' => $active]);
    }

    private function mkRun(string $status, ?User $by = null, string $trigger = 'manual', ?string $reason = null): AgentRun
    {
        $site = Website::create(['name' => 'S', 'cms_type' => 'wordpress', 'base_url' => 'https://a'.uniqid().'.vn', 'encrypted_credentials' => ['p' => 'x'], 'status' => 'active']);
        $a = Article::create(['website_id' => $site->id, 'primary_keyword' => 'đo đường huyết tại nhà', 'workflow_status' => 'drafting']);

        return AgentRun::create(['website_id' => $site->id, 'article_id' => $a->id, 'triggered_by' => $by?->id, 'trigger' => $trigger, 'status' => $status, 'outcome_reason' => $reason, 'started_at' => now()]);
    }

    public function test_xong_bai_bao_cho_nguoi_da_giao_va_co_nut_mo_bai(): void
    {
        $writer = $this->user('writer');
        $other = $this->user('approver');
        $run = $this->mkRun('ready_for_review', $writer);

        $this->assertSame(1, RunFinishedNotifier::notify($run, $writer));
        $n = $writer->notifications()->first();
        $this->assertStringContainsString('đã viết xong', $n->data['title']);
        $this->assertStringContainsString('đo đường huyết tại nhà', $n->data['title']);
        $this->assertSame('success', $n->data['status']);
        $this->assertSame($run->id, $n->data['viewData']['run_id']);
        $this->assertStringContainsString('/admin/', $n->data['actions'][0]['url']);
        $this->assertSame(0, $other->notifications()->count(), 'Người khác không nhận khi đã có người giao.');
    }

    public function test_phien_do_lich_hoac_chien_dich_khong_co_nguoi_giao_thi_bao_cho_admin_va_nguoi_duyet_dang_hoat_dong(): void
    {
        $admin = $this->user('admin');
        $approver = $this->user('approver');
        $off = $this->user('approver', false);
        $writer = $this->user('writer');
        $run = $this->mkRun('ready_for_review', null, 'schedule');

        $this->assertSame(2, RunFinishedNotifier::notify($run));
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $approver->notifications()->count());
        $this->assertSame(0, $off->notifications()->count());
        $this->assertSame(0, $writer->notifications()->count());
    }

    public function test_noi_dung_theo_ket_qua_loi_giu_nhap_bi_chan(): void
    {
        $u = $this->user('approver');
        foreach (['failed' => ['chạy lỗi', 'danger'], 'held' => ['giữ nháp', 'warning'], 'blocked' => ['bị chặn', 'warning']] as $st => [$word, $color]) {
            $run = $this->mkRun($st, $u, 'manual', 'Lý do '.$st);
            RunFinishedNotifier::notify($run, $u);
            $n = $u->notifications()->where('data', 'like', '%"run_id":'.$run->id.'%')->first();
            $this->assertStringContainsString($word, $n->data['title']);
            $this->assertSame($color, $n->data['status']);
            $this->assertStringContainsString('Lý do '.$st, $n->data['body']);
        }
    }

    public function test_khong_gui_trung_va_khong_gui_cho_phien_dang_chay_hoac_danh_gia(): void
    {
        $u = $this->user('approver');
        $run = $this->mkRun('ready_for_review', $u);
        $this->assertSame(1, RunFinishedNotifier::notify($run, $u));
        $this->assertSame(0, RunFinishedNotifier::notify($run, $u), 'Cùng một phiên chỉ báo một lần.');
        $this->assertSame(0, RunFinishedNotifier::notify($this->mkRun('running', $u), $u));
        $this->assertSame(0, RunFinishedNotifier::notify($this->mkRun('ready_for_review', $u, 'eval'), $u), 'Phiên đánh giá không báo người dùng.');
        $this->assertSame(1, $u->notifications()->count());
    }

    public function test_dispatcher_gui_thong_bao_khi_phien_ket_thuc_va_khong_lam_hong_phien_neu_gui_loi(): void
    {
        $u = $this->user('writer');
        $run = $this->mkRun('failed', $u, 'manual', 'Hết hạn mức');
        $this->assertSame($run->id, RunFinishedNotifier::afterRun($run, $u)->id, 'Trả đúng phiên.');
        $this->assertSame(1, $u->notifications()->count());
        \Illuminate\Support\Facades\Schema::drop('notifications');
        $this->assertSame($run->id, RunFinishedNotifier::afterRun($run, $u)->id, 'Thiếu bảng thông báo thì vẫn trả phiên, không ném lỗi.');
    }

    public function test_hop_noi_chi_hien_thong_bao_moi_mot_lan_va_lam_moi_chuong(): void
    {
        $u = $this->user('approver');
        $old = $this->mkRun('ready_for_review', $u);
        RunFinishedNotifier::notify($old, $u); // có TRƯỚC khi mở trang
        $this->travel(5)->seconds();
        $this->actingAs($u);

        $c = Livewire::test(NotificationToaster::class);
        $c->call('poll')->assertNotDispatched('databaseNotificationsSent');

        $this->travel(3)->seconds();
        $new = $this->mkRun('ready_for_review', $u);
        RunFinishedNotifier::notify($new, $u);
        $c->call('poll')->assertNotified()->assertDispatched('databaseNotificationsSent');
        $c->call('poll')->assertNotDispatched('databaseNotificationsSent'); // lần sau không nổi lại
        $this->assertSame(2, $u->unreadNotifications()->count(), 'Hộp nổi không tự đánh dấu đã đọc.');
        // Đóng hộp nổi phát 'notificationClosed' kèm id của HỘP; nếu trùng id thông báo trong bảng thì Filament xóa thông báo khỏi chuông (lỗi đã gặp khi chạy thật).
        $this->assertSame(['title' => 'T'], NotificationToaster::toastPayload(['id' => 'abc', 'title' => 'T']));
        $this->assertArrayNotHasKey('id', (array) $u->notifications()->first()->data, 'Dữ liệu lưu không mang id của dòng trong bảng.');
    }

    public function test_trang_quan_tri_co_chuong_thong_bao_va_hop_noi(): void
    {
        $u = $this->user('approver');
        $this->actingAs($u);
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="notification-toaster"', $html);
        $this->assertStringContainsString('database-notifications', $html, 'Biểu tượng chuông của Filament có mặt cạnh ảnh đại diện.');
    }
}
