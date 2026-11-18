<?php

namespace App\Services\Publishing;

use App\Models\Publication;
use Illuminate\Support\Facades\Log;

/**
 * Lượt đăng kẹt 'publishing' (job mất/chết giữa chừng) không được nằm đó mãi.
 * - Đã từng gửi (attempt_count > 0): không biết CMS đã nhận bài chưa => needs_reconcile (đối soát theo publication_key, KHÔNG tự đăng lại, AGENTS.md §1.4).
 * - Chưa gửi lần nào (0 lần thử): chắc chắn chưa chạm CMS => failed kèm lý do để người dùng bấm Đăng lại.
 */
final class PublicationStaleReaper
{
    public const DEFAULT_MINUTES = 15;

    /** @return int số lượt đã xử lý */
    public static function reap(int $minutes = self::DEFAULT_MINUTES): int
    {
        $n = 0;
        Publication::query()
            ->where('status', Publication::STATUS_PUBLISHING)
            ->where('updated_at', '<', now()->subMinutes(max(1, $minutes)))
            ->orderBy('id')
            ->get()
            ->each(function (Publication $p) use ($minutes, &$n) {
                self::settle($p, "Kẹt ở \"Đang đăng\" quá {$minutes} phút, không còn job nào xử lý.");
                $n++;
            });

        return $n;
    }

    public static function settle(Publication $p, string $why, bool $requeueIfNeverSent = false): void
    {
        if ((int) $p->attempt_count > 0) {
            $p->update([
                'status' => Publication::STATUS_NEEDS_RECONCILE,
                'last_error' => "{$why} Bài có thể đã lên CMS nên hệ thống chuyển sang chờ đối soát, không đăng lại. Bấm \"Đối soát\" để kiểm tra.",
            ]);
        } elseif ($requeueIfNeverSent) {
            $p->update([
                'status' => Publication::STATUS_SCHEDULED,
                'last_error' => "{$why} Chưa gửi lần nào lên CMS nên hệ thống xếp lại hàng đợi.",
            ]);
        } else {
            $p->update([
                'status' => Publication::STATUS_FAILED,
                'last_error' => "{$why} Chưa gửi lần nào lên CMS (0 lần thử). Bấm \"Đăng lại\" để đăng.",
            ]);
        }
        Log::warning("[PublicationStaleReaper] Lượt #{$p->id} kẹt, chuyển sang {$p->status}.", ['publication_id' => $p->id]);
    }
}
