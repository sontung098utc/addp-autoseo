<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Quản lý việc lập lịch xuất bản bài viết hằng ngày theo múi giờ Việt Nam
Schedule::command('autoseo:publish-due-posts')
    ->everyMinute()
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping()
    ->runInBackground();

// Đối soát định kỳ các bài viết bị timeout mạng hoặc lỗi 502/504
Schedule::command('autoseo:reconcile-publications')
    ->everyFiveMinutes()
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping()
    ->runInBackground();

// AG-S01: nhịp chiến dịch của đội AI. Không dùng AI giả lập; thiếu khóa AI thật thì mỗi nhịp chỉ ghi "chưa cấu hình". Dev1 thay bằng hàng đợi (AG-B10).
Schedule::command('agent:tick')
    ->everyFifteenMinutes()
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping()
    ->runInBackground();

// Autopilot (chế độ Toàn quyền): giữ hàng chờ kế hoạch đủ đầy bằng khảo sát và lập kế hoạch. Đồng bộ theo nhịp; chạy nền thật là AG-B10.
Schedule::command('autopilot:tick')
    ->everyThirtyMinutes()
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping()
    ->runInBackground();

// Dọn phiên đội AI bị bỏ dở (tiến trình chết giữa chừng nên phiên mãi "đang chạy", bài mãi kẹt "Đang viết"): đóng phiên quá 60 phút không có bước mới.
// Chạy riêng, không phụ thuộc chiến dịch (agent:tick cũng gọi trước mỗi nhịp nhưng chỉ khi nhịp đó chạy được).
Schedule::command('agent:reap-stale')
    ->everyTenMinutes()
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping()
    ->runInBackground();

// Xu hướng miễn phí: mỗi ngày ghi lại gợi ý tự động của Google cho chủ đề các chiến dịch, để sau vài ngày so được cụm nào mới nổi. Gọi Google rất nhẹ, nghỉ 1 giây giữa các chủ đề.
Schedule::command('trends:snapshot')
    ->dailyAt('03:30')
    ->timezone('Asia/Ho_Chi_Minh')
    ->withoutOverlapping()
    ->runInBackground();

// Xử lý hàng đợi (PublishArticleJob, ReconcilePublicationJob...) ngay dưới cron `schedule:run` sẵn có, không cần supervisor: mỗi phút chạy tối đa 50 giây rồi thoát.
// Chỉ bật khi hàng đợi KHÔNG phải `sync` (sync chạy job ngay trong tiến trình gửi nên không cần tiến trình xử lý).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1')
    ->everyMinute()
    ->when(fn () => config('queue.default') !== 'sync')
    ->withoutOverlapping(5)
    ->runInBackground();
