<?php

namespace App\Livewire;

use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

/**
 * Hộp nổi góc trên bên phải cho thông báo MỚI (cùng kiểu thông báo hiện có của Filament). Đọc bảng `notifications` của người dùng theo nhịp 15 giây
 * và hiện những thông báo chưa đọc phát sinh SAU khi trang mở (thông báo cũ chỉ nằm ở biểu tượng chuông, không bật lại mỗi lần chuyển trang).
 * Không tự đánh dấu đã đọc và không xóa: thông báo vẫn nằm ở chuông sau khi hộp nổi đóng; người dùng đọc/xóa ở chuông. Một thông báo chỉ nổi một lần trong mỗi trang (nhớ id).
 */
class NotificationToaster extends Component
{
    /** Mốc thời gian lúc mở trang (ISO 8601): chỉ thông báo tạo từ mốc này mới nổi. */
    public string $since = '';

    /** @var list<string> id đã nổi trong trang này */
    public array $shown = [];

    public function mount(): void
    {
        $this->since = now()->subSeconds(2)->toIso8601String();
    }

    public function poll(): void
    {
        $user = Auth::user();
        if (! $user || ! Schema::hasTable('notifications')) {
            return;
        }
        $new = $user->unreadNotifications()->where('created_at', '>=', \Illuminate\Support\Carbon::parse($this->since))->whereNotIn('id', $this->shown ?: [''])
            ->oldest()->limit(5)->get();
        foreach ($new as $n) {
            $this->shown[] = (string) $n->id;
            Notification::fromArray(self::toastPayload((array) $n->data))->duration(12000)->send();
        }
        if ($new->isNotEmpty()) {
            $this->shown = array_slice($this->shown, -50);
            $this->dispatch('databaseNotificationsSent'); // làm mới số trên biểu tượng chuông ngay
        }
    }

    /**
     * Dữ liệu cho hộp nổi, BỎ id của thông báo trong bảng: đóng hộp phát 'notificationClosed' kèm id, và Filament xóa dòng cùng id khỏi bảng,
     * làm thông báo biến mất khỏi chuông. Không có id thì Filament tự cấp id riêng cho hộp.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toastPayload(array $data): array
    {
        unset($data['id']);

        return $data;
    }

    public function render()
    {
        return view('livewire.notification-toaster');
    }
}
