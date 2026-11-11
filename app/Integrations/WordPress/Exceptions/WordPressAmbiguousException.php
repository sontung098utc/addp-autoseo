<?php

namespace App\Integrations\WordPress\Exceptions;

use App\Contracts\CmsErrorInterface;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Lỗi MƠ HỒ sau khi đã gửi yêu cầu ghi. Kế thừa ConnectionException để tương thích ngược,
 * đồng thời triển khai CmsErrorInterface (PR-D) để PublishArticleJob xử lý đồng nhất.
 */
class WordPressAmbiguousException extends ConnectionException implements CmsErrorInterface
{
    use HasWordPressErrorCode;

    public function __construct(string $code, ?string $detail = null, ?Throwable $previous = null)
    {
        parent::__construct($this->initCode($code, $detail), 0, $previous);
    }
}
