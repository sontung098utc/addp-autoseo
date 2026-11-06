<?php

namespace App\Integrations\WordPress\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Lỗi MƠ HỒ sau khi đã gửi yêu cầu ghi. Cố ý kế thừa ConnectionException vì PublishArticleJob
 * (không được sửa) chỉ chuyển sang needs_reconcile khi bắt được loại ngoại lệ này.
 */
class WordPressAmbiguousException extends ConnectionException
{
    use HasWordPressErrorCode;

    public function __construct(string $code, ?string $detail = null, ?Throwable $previous = null)
    {
        parent::__construct($this->initCode($code, $detail), 0, $previous);
    }
}
