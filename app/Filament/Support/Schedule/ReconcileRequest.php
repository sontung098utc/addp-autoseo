<?php

namespace App\Filament\Support\Schedule;

use App\Jobs\ReconcilePublicationJob;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Đối soát ngay": chỉ gửi ReconcilePublicationJob (cơ chế của Dev1) cho lượt needs_reconcile.
 * Không tạo Publication/bài mới, không đăng lại.
 */
final class ReconcileRequest
{
    public static function reasonUnavailable(?User $user, Publication $p): ?string
    {
        if (! $user || ! $user->is_active || ! $user->isApprover()) {
            return 'Chỉ người duyệt (approver) trở lên được đối soát.';
        }
        if ($p->status !== 'needs_reconcile') {
            return 'Chỉ lượt ở trạng thái "Chờ đối soát" mới cần đối soát; các trạng thái khác không có việc để đối soát.';
        }

        return null;
    }

    /** @return array{ok: bool, message: string} */
    public static function request(Publication $p, ?User $user): array
    {
        $fresh = $p->fresh() ?? $p;
        if ($reason = self::reasonUnavailable($user, $fresh)) {
            return ['ok' => false, 'message' => $reason];
        }

        Log::info('publication.reconcile_requested', ['publication_id' => $fresh->id, 'actor_id' => $user->id]);

        try {
            ReconcilePublicationJob::dispatch($fresh);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Đối soát chưa hoàn tất (CMS chưa phản hồi). Lượt đăng giữ nguyên "Chờ đối soát", không đăng lại.'];
        }

        $after = $fresh->fresh();

        return ['ok' => true, 'message' => match ($after?->status) {
            'published' => 'Đối soát xong: bài ĐÃ có trên CMS, lượt đăng chuyển sang "Đã đăng".',
            'failed' => 'Đối soát xong: CMS chưa ghi nhận bài. Lượt đăng chuyển sang "Lỗi", có thể lên lịch lại an toàn.',
            default => 'Đã gửi yêu cầu đối soát. Kết quả sẽ cập nhật khi tiến trình hoàn tất.',
        }];
    }
}
