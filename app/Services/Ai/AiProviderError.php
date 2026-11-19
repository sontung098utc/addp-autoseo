<?php

namespace App\Services\Ai;

use RuntimeException;

/** Lỗi CỨNG từ nhà cung cấp AI (khóa bị từ chối, yêu cầu sai…): thử lại không giúp ích. Thông điệp đã được che, không chứa khóa. */
class AiProviderError extends RuntimeException
{
    /** Mã HTTP nhà cung cấp trả (nếu có) để phân loại lỗi cho người xem (AiFailureReason). */
    public ?int $status = null;

    public static function withStatus(string $message, ?int $status): static
    {
        $e = new static($message);
        $e->status = $status;

        return $e;
    }
}
