<?php

namespace App\Services\Publishing;

use App\Filament\Support\Audit\AuditAction;
use App\Filament\Support\Audit\AuditRecorder;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Dừng / tiếp tục MỘT lượt đăng (không đụng Dừng khẩn cấp toàn hệ thống). Lượt 'paused' và 'awaiting_confirm' không bao giờ được gửi:
 * lệnh quét chỉ nhặt 'scheduled', PublishArticleJob từ chối hai trạng thái này. Mọi chuyển trạng thái là UPDATE có điều kiện để không đua với job.
 *  - pause: scheduled → paused (người dừng riêng bài này).
 *  - resume: paused|awaiting_confirm → scheduled. Quá giờ thì lệnh quét gửi ở lần kế tiếp.
 *  - holdForConfirmation: dùng lúc xếp lịch bài của chiến dịch có điểm dừng 'publish' (không cần người dùng thao tác).
 */
final class PublicationHold
{
    public function __construct(private readonly AuditRecorder $audit = new AuditRecorder) {}

    public static function reasonCannotPause(?User $u, Publication $p): ?string
    {
        if (! $u || ! $u->is_active || ! $u->isApprover()) {
            return 'Chỉ người duyệt trở lên mới được tạm dừng lượt đăng.';
        }

        return $p->status === 'scheduled' ? null : 'Chỉ tạm dừng được lượt "Đã xếp lịch" (lượt đang đăng hoặc đã đăng thì không dừng được nữa).';
    }

    public static function reasonCannotResume(?User $u, Publication $p): ?string
    {
        if (! $u || ! $u->is_active || ! $u->isApprover()) {
            return 'Chỉ người duyệt trở lên mới được cho đăng tiếp.';
        }

        return in_array($p->status, ['paused', 'awaiting_confirm'], true) ? null : 'Lượt đăng không đang tạm dừng.';
    }

    public function pause(User $actor, Publication $publication): Publication
    {
        return $this->move($actor, $publication, ['scheduled'], 'paused', AuditAction::PausePublication, self::reasonCannotPause(...), 'Tạm dừng bởi '.$actor->name);
    }

    public function resume(User $actor, Publication $publication): Publication
    {
        return $this->move($actor, $publication, ['paused', 'awaiting_confirm'], 'scheduled', AuditAction::ResumePublication, self::reasonCannotResume(...), null);
    }

    /** Điểm dừng 'publish': lượt vừa xếp lịch chuyển sang chờ xác nhận. Không ghi audit người dừng (hệ thống làm theo cài đặt chiến dịch). */
    public function holdForConfirmation(Publication $publication): Publication
    {
        DB::table('publications')->where('id', $publication->id)->where('status', 'scheduled')
            ->update(['status' => 'awaiting_confirm', 'last_error' => 'Chờ xác nhận: chiến dịch đặt điểm dừng "Trước khi đăng".', 'updated_at' => now()]);

        return $publication->refresh();
    }

    /** @param list<string> $from */
    private function move(User $actor, Publication $publication, array $from, string $to, AuditAction $action, \Closure $reasonFn, ?string $note): Publication
    {
        return DB::transaction(function () use ($actor, $publication, $from, $to, $action, $reasonFn, $note) {
            $fresh = Publication::query()->with('articleVersion.article')->lockForUpdate()->find($publication->id);
            if (! $fresh) {
                throw new InvalidArgumentException('Không tìm thấy lượt đăng.');
            }
            if ($why = $reasonFn($actor, $fresh)) {
                throw new InvalidArgumentException($why);
            }
            $affected = DB::table('publications')->where('id', $fresh->id)->whereIn('status', $from)
                ->update(['status' => $to, 'last_error' => $note, 'updated_at' => now()]);
            if ($affected !== 1) {
                throw new InvalidArgumentException('Lượt đăng vừa đổi trạng thái, tải lại trang.');
            }
            $version = $fresh->articleVersion;
            $this->audit->record($action, $actor, $version?->article, $version, null, ['publication_id' => $fresh->id, 'publication_key' => $fresh->publication_key, 'from' => $fresh->status, 'to' => $to]);

            return $fresh->refresh();
        });
    }
}
