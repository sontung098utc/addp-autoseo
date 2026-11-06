<?php

namespace App\Filament\Support\Schedule;

use App\Filament\Support\Audit\AuditAction;
use App\Filament\Support\Audit\AuditRecorder;
use App\Filament\Support\Review\ReviewActions;
use App\Models\Publication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Đổi giờ một lượt đăng đã xếp lịch. Chỉ đổi scheduled_at_utc; không đổi publication_key,
 * không tạo lượt/bài mới. Cập nhật có điều kiện (status='scheduled') cùng transaction với log
 * để không đua với PublishDuePostsCommand/PublishArticleJob (job chuyển sang 'publishing').
 */
class ReschedulePublication
{
    /** Lượt sắp đến giờ trong vòng ngần này phút bị chốt (job có thể đang nhặt). */
    public const RESCHEDULE_LOCK_MINUTES = 10;

    /**
     * @param  Closure|null  $beforeUpdate  chỉ để test mô phỏng đua: chạy trong transaction, ngay trước UPDATE có điều kiện
     */
    public function __construct(
        private readonly AuditRecorder $audit = new AuditRecorder(),
        private readonly ?Closure $beforeUpdate = null,
    ) {
    }

    public static function lockExplanation(): string
    {
        return 'Chỉ đổi được lượt "Đã xếp lịch" còn cách giờ đăng trên '.self::RESCHEDULE_LOCK_MINUTES.' phút. Lượt đang đăng/đã đăng/lỗi/chờ đối soát không đổi được vì đã hoặc đang gửi lên CMS, đổi giờ có thể gây đăng trùng.';
    }

    /** Lý do tiếng Việt khiến không đổi lịch được (R1-R3), null nếu được. */
    public static function reasonUnavailable(?User $user, Publication $p): ?string
    {
        if (! $user || ! $user->is_active || ! $user->isApprover()) {
            return 'Chỉ người duyệt trở lên mới được đổi lịch.';
        }
        if ($p->status !== 'scheduled') {
            return 'Lượt đăng không ở trạng thái "Đã xếp lịch" nên không đổi giờ được (đã/đang đăng, lỗi hoặc cần đối soát: đổi giờ có thể gây đăng trùng).';
        }
        $current = VnTime::fromRaw($p, 'scheduled_at_utc');
        if (! $current || $current->lt(self::earliest())) {
            return 'Lượt đăng sắp đến giờ (trong '.self::RESCHEDULE_LOCK_MINUTES.' phút) hoặc đã quá giờ nên bị chốt, không đổi được.';
        }

        return null;
    }

    /** Cảnh báo (không chặn) R9. */
    public static function warning(Publication $p): ?string
    {
        $site = $p->website;
        if (! $site) {
            return 'Website của lượt đăng không còn tồn tại; đến giờ lượt đăng sẽ báo Lỗi.';
        }
        if ($site->status !== 'active') {
            return 'Website "'.$site->name.'" không còn ở trạng thái hoạt động; đến giờ lượt đăng sẽ báo Lỗi.';
        }
        if (! ReviewActions::connectorAvailable($site)) {
            return 'Website "'.$site->name.'" chưa có connector '.$site->cms_type.'; đến giờ lượt đăng sẽ báo Lỗi vì chưa có cách gửi bài.';
        }

        return null;
    }

    public function reschedule(User $actor, Publication $publication, CarbonInterface $newVn): Publication
    {
        $newUtc = CarbonImmutable::instance($newVn)->setSecond(0)->setMicro(0)->utc();
        if ($newUtc->lt(self::earliest()->utc())) {
            throw new InvalidArgumentException('Giờ mới phải ở tương lai và cách hiện tại ít nhất '.self::RESCHEDULE_LOCK_MINUTES.' phút.');
        }

        return DB::transaction(function () use ($actor, $publication, $newUtc) {
            $fresh = Publication::query()->with('articleVersion.article')->lockForUpdate()->find($publication->id);
            if (! $fresh) {
                throw new InvalidArgumentException('Không tìm thấy lượt đăng.');
            }
            if ($reason = self::reasonUnavailable($actor, $fresh)) {
                throw new InvalidArgumentException($reason);
            }

            $from = VnTime::fromRaw($fresh, 'scheduled_at_utc');

            if ($this->beforeUpdate) {
                ($this->beforeUpdate)($fresh);
            }

            $affected = DB::table('publications')
                ->where('id', $fresh->id)
                ->where('status', 'scheduled')
                ->update(['scheduled_at_utc' => VnTime::dbString($newUtc), 'updated_at' => now()]);
            if ($affected !== 1) {
                throw new InvalidArgumentException('Lượt đăng vừa thay đổi trạng thái, tải lại trang.');
            }

            $version = $fresh->articleVersion;
            $this->audit->record(AuditAction::Reschedule, $actor, $version?->article, $version, null, [
                'publication_id' => $fresh->id,
                'publication_key' => $fresh->publication_key,
                'from_utc' => $from?->toIso8601String(),
                'to_utc' => $newUtc->toIso8601String(),
            ]);

            return $fresh->refresh();
        });
    }

    /** Kéo thả: giữ nguyên giờ-phút theo giờ VN, đổi sang ngày $dayYmdVn. */
    public function moveToDay(User $actor, Publication $publication, string $dayYmdVn): Publication
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $dayYmdVn, VnTime::TZ);
        if (! $day || $day->format('Y-m-d') !== $dayYmdVn) {
            throw new InvalidArgumentException('Ngày không hợp lệ.');
        }
        $current = VnTime::fromRaw($publication, 'scheduled_at_utc');
        if (! $current) {
            throw new InvalidArgumentException('Lượt đăng chưa có giờ đăng.');
        }
        $vn = $current->setTimezone(VnTime::TZ);

        return $this->reschedule($actor, $publication, $day->setTime((int) $vn->format('H'), (int) $vn->format('i')));
    }

    private static function earliest(): CarbonImmutable
    {
        return VnTime::now()->addMinutes(self::RESCHEDULE_LOCK_MINUTES);
    }
}
