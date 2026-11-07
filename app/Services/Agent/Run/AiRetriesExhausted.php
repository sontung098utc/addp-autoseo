<?php

namespace App\Services\Agent\Run;

use RuntimeException;
use Throwable;

/** Nhà cung cấp AI vẫn lỗi tạm thời sau mọi lần thử lại. Phiên chạy chuyển 'failed'; KHÔNG thay bằng AI giả lập. */
final class AiRetriesExhausted extends RuntimeException
{
    public function __construct(public readonly string $what, public readonly int $attempts, Throwable $last)
    {
        parent::__construct("AI lỗi tạm thời sau {$attempts} lần thử ({$what}): ".TransientAiError::describe($last), 0, $last);
    }
}
