<?php

namespace App\Filament\Support\Schedule;

use App\Filament\Support\StatusPresenter;
use App\Models\Publication;

/** Giải thích "vì sao ở trạng thái này / bước tiếp theo" + dòng thời gian từ dữ liệu thật của lượt đăng. */
final class ScheduleExplainer
{
    /** @return array{why: string, next: string} */
    public static function explain(string $status): array
    {
        return match ($status) {
            'scheduled' => [
                'why' => 'Lượt đăng đã được xếp lịch và đang chờ đến giờ.',
                'next' => 'Đến giờ, hệ thống tự gửi bài lên CMS. Bạn không cần làm gì.',
            ],
            'publishing' => [
                'why' => 'Hệ thống đang gửi bài lên CMS.',
                'next' => 'Chờ kết quả. Nếu CMS không phản hồi, lượt đăng chuyển sang "Chờ đối soát" (không tự đăng lại).',
            ],
            'published' => [
                'why' => 'CMS đã xác nhận nhận bài.',
                'next' => 'Không cần xử lý. Mở link bài để kiểm tra hiển thị.',
            ],
            'failed' => [
                'why' => 'Gửi bài không thành công (xem thông tin lỗi, đã che thông tin nhạy cảm).',
                'next' => 'Sửa nguyên nhân (kết nối website, nội dung) rồi lên lịch lại từ bài viết. Lưu ý: lượt này không tự đăng lại.',
            ],
            'needs_reconcile' => [
                'why' => 'CMS không phản hồi sau khi gửi bài. Hệ thống sẽ đối soát theo khóa xuất bản; KHÔNG đăng lại để tránh trùng bài.',
                'next' => 'Bấm "Đối soát ngay" để hỏi CMS xem bài đã tồn tại chưa: nếu có, lượt đăng chuyển "Đã đăng"; nếu chưa, chuyển "Lỗi" và an toàn để lên lịch lại.',
            ],
            'held' => [
                'why' => 'Lượt đăng đang được tạm giữ.',
                'next' => 'Chờ được thả lại.',
            ],
            default => ['why' => 'Trạng thái không xác định.', 'next' => 'Liên hệ quản trị hệ thống.'],
        };
    }

    /**
     * Các bước dòng thời gian; state: done | current | pending | error | warning.
     *
     * @return list<array{title: string, detail: string, state: string}>
     */
    public static function timeline(Publication $p): array
    {
        $scheduled = VnTime::fromRaw($p, 'scheduled_at_utc');
        $published = VnTime::fromRaw($p, 'published_at_utc');
        $status = $p->status;

        $steps = [[
            'title' => 'Xếp lịch',
            'detail' => 'Giờ đăng dự kiến '.VnTime::format($scheduled).' (giờ VN) · '.VnTime::formatUtc($scheduled),
            'state' => 'done',
        ]];

        $attempts = (int) $p->attempt_count;
        $sendState = match ($status) {
            'scheduled' => 'pending',
            'publishing' => 'current',
            default => $attempts > 0 || $status !== 'scheduled' ? 'done' : 'pending',
        };
        $steps[] = [
            'title' => 'Gửi lên CMS',
            'detail' => $attempts > 0 ? "Đã thử {$attempts} lần" : 'Chưa gửi lần nào',
            'state' => $sendState,
        ];

        $steps[] = match ($status) {
            'published' => ['title' => 'Kết quả: '.StatusPresenter::label($status, 'publication'), 'detail' => 'Đăng lúc '.VnTime::format($published).' (giờ VN)', 'state' => 'done'],
            'failed' => ['title' => 'Kết quả: '.StatusPresenter::label($status, 'publication'), 'detail' => ErrorMasker::mask($p->last_error) ?: 'Không có thông tin lỗi', 'state' => 'error'],
            'needs_reconcile' => ['title' => 'Kết quả: '.StatusPresenter::label($status, 'publication'), 'detail' => ErrorMasker::mask($p->last_error) ?: 'Chưa rõ bài đã lên CMS hay chưa', 'state' => 'warning'],
            default => ['title' => 'Kết quả', 'detail' => 'Chưa có', 'state' => 'pending'],
        };

        return $steps;
    }
}
