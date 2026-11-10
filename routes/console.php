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
