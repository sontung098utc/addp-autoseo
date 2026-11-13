<?php

namespace App\Services\Images;

use Illuminate\Http\Client\Response;

/**
 * Lỗi nhà cung cấp ảnh. Thông điệp chỉ gồm mã HTTP/lý do ngắn, đã qua ErrorMasker; không kèm URL hay nội dung phản hồi.
 * Mang thêm mã HTTP và Retry-After để phân biệt HẾT HẠN NGẠCH (không thử lại, dừng cả bước) với lỗi tạm thời (thử lại có giới hạn).
 */
final class ImageGenerationError extends \RuntimeException
{
    public function __construct(string $message = '', public readonly ?int $status = null, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }

    public static function http(Response $res): self
    {
        $status = $res->status();
        $after = (int) $res->header('Retry-After') ?: null;

        return new self('Nhà cung cấp ảnh trả lỗi HTTP '.$status.($status === 429 ? ' (hết hạn ngạch hoặc quá tải tạo ảnh'.($after ? ', chờ khoảng '.max(1, intdiv($after, 60)).' phút' : '').')' : '.'), $status, $after);
    }

    /** Hết hạn ngạch/quá tải: thử lại ngay vô ích. */
    public function isQuota(): bool
    {
        return $this->status === 429 || preg_match('/(RESOURCE_EXHAUSTED|hết hạn (ngạch|mức)|quota)/iu', $this->getMessage()) === 1;
    }
}
