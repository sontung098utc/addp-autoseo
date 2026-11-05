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
