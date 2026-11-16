<?php

namespace App\Services\Agent\Ai;

use RuntimeException;

/** Loại việc chưa có route dùng được (chưa cấu hình, provider thiếu khóa/đang tắt). Tác vụ KHÔNG chạy; không bao giờ thay bằng Mock. */
final class AiRouteUnavailable extends RuntimeException
{
    public function __construct(public readonly string $task, string $why)
    {
        parent::__construct($why);
    }
}
