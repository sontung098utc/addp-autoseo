<?php

namespace App\Filament\Support\Review;

use App\Jobs\PublishArticleJob;
use App\Models\Publication;
use Filament\Notifications\Notification;

/**
 * Đăng NGAY một lượt đăng (đã tạo bằng ReviewWorkflow::publishNow, hoặc lượt đã xếp lịch/lỗi) rồi báo kết quả thật cho người bấm.
 * Chạy PublishArticleJob đồng bộ như nút "Đăng ngay" ở Lịch xuất bản: bước đăng luôn tra theo publication_key trước khi tạo bài nên không đăng trùng.
 */
final class PublishNow
{
    public static function run(Publication $p): void
    {
        PublishArticleJob::dispatchSync($p);
        $p->refresh();
        $site = $p->website?->name ?? 'CMS';
        match ($p->status) {
            'published' => Notification::make()->title('Đã xuất bản thành công lên '.$site)->body($p->published_url ? 'URL: '.$p->published_url : null)->success()->send(),
            'needs_reconcile' => Notification::make()->title('Chưa biết bài đã lên chưa')->body('CMS không trả lời rõ (mạng/timeout). Hệ thống sẽ đối soát theo khóa xuất bản, đừng đăng lại. '.($p->last_error ?? ''))->warning()->persistent()->send(),
            'scheduled' => Notification::make()->title('Chưa đăng được')->body($p->last_error ?: 'Lượt đăng được giữ lại ở trạng thái đã xếp lịch.')->warning()->send(),
            default => Notification::make()->title('Xuất bản thất bại')->body($p->last_error ?: 'Lỗi không xác định')->danger()->persistent()->send(),
        };
    }
}
