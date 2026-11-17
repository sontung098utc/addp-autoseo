<?php

namespace App\Services\Agent\Notify;

use App\Filament\Resources\AgentRunResource;
use App\Models\AgentRun;
use App\Models\User;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Thông báo khi đội AI chạy XONG một bài (thành công, giữ nháp, bị chặn hoặc lỗi). Lưu vào bảng `notifications`:
 * (1) hiện ở biểu tượng chuông cạnh ảnh đại diện, (2) thành hộp nổi góc trên bên phải (NotificationToaster đọc bảng này theo nhịp).
 *
 * Người nhận: người đã giao bài; phiên do lịch/chiến dịch chạy (không có người giao) thì mọi quản trị viên và người duyệt đang hoạt động.
 * Không bao giờ làm hỏng phiên chạy: mọi lỗi chỉ ghi nhật ký. Mỗi (phiên, người nhận) chỉ một thông báo.
 */
final class RunFinishedNotifier
{
    /** Phiên chạy thử nghiệm/đánh giá không gửi thông báo cho người dùng. */
    private const SILENT_TRIGGERS = ['eval'];

    /** Dùng ở nơi trả phiên về cho người gọi: gửi thông báo (nếu có) rồi trả đúng phiên đó, không đổi gì. */
    public static function afterRun(AgentRun $run, ?User $actor = null, ?string $trigger = null): AgentRun
    {
        self::notify($run, $actor, $trigger);

        return $run;
    }

    public static function notify(AgentRun $run, ?User $actor = null, ?string $trigger = null): int
    {
        try {
            if (! Schema::hasTable('notifications') || in_array($trigger ?? (string) $run->trigger, self::SILENT_TRIGGERS, true) || ! in_array($run->status, ['ready_for_review', 'held', 'blocked', 'failed'], true)) {
                return 0;
            }
            $n = 0;
            foreach (self::recipients($run, $actor) as $user) {
                if (self::alreadySent($user, $run)) {
                    continue;
                }
                self::build($run)->sendToDatabase($user);
                $n++;
            }

            return $n;
        } catch (Throwable $e) {
            Log::warning('RunFinishedNotifier: không gửi được thông báo', ['run' => $run->id, 'loi' => mb_substr($e->getMessage(), 0, 200)]);

            return 0;
        }
    }

    /** @return list<User> */
    private static function recipients(AgentRun $run, ?User $actor): array
    {
        $actor ??= $run->triggered_by ? User::query()->find($run->triggered_by) : null;
        if ($actor instanceof User && $actor->is_active) {
            return [$actor];
        }

        return User::query()->where('is_active', true)->whereIn('role', ['admin', 'approver'])->get()->all();
    }

    private static function alreadySent(User $user, AgentRun $run): bool
    {
        return DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey())
            ->where('data', 'like', '%"run_id":'.(int) $run->id.'%')->exists();
    }

    public static function build(AgentRun $run): Notification
    {
        $kw = $run->article?->primary_keyword;
        $label = ($run->article_id ? 'Bài #'.$run->article_id : 'Phiên #'.$run->id).($kw ? ' «'.mb_strimwidth((string) $kw, 0, 60, '…').'»' : '');
        $url = AgentRunResource::viewUrl($run);
        $reason = trim((string) $run->outcome_reason);
        [$title, $body, $status, $icon, $btn] = match ($run->status) {
            'ready_for_review' => [$label.' đã viết xong', 'Có bản nháp chờ bạn duyệt.'.($reason !== '' ? ' '.$reason : ''), 'success', 'heroicon-o-check-circle', 'Mở bài'],
            'held' => [$label.': giữ nháp, cần bạn xem', $reason !== '' ? $reason : 'Đội AI giữ bản nháp lại để người xem trước.', 'warning', 'heroicon-o-hand-raised', 'Xem diễn biến'],
            'blocked' => [$label.': bị chặn trước khi chạy', $reason !== '' ? $reason : 'Phiên bị chặn bởi luật an toàn.', 'warning', 'heroicon-o-no-symbol', 'Xem lý do'],
            default => [$label.': chạy lỗi', $reason !== '' ? $reason : 'Phiên dừng vì lỗi.', 'danger', 'heroicon-o-exclamation-triangle', 'Xem lỗi'],
        };

        return Notification::make()->title($title)->body(mb_strimwidth($body, 0, 400, '…'))->status($status)->icon($icon)->iconColor($status)
            ->viewData(['run_id' => (int) $run->id, 'article_id' => (int) $run->article_id])
            ->actions([Action::make('open')->label($btn)->url($url)->button()->markAsRead()]);
    }
}
