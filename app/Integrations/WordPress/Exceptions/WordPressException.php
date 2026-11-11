<?php

namespace App\Integrations\WordPress\Exceptions;

use App\Contracts\CmsErrorInterface;
use RuntimeException;
use Throwable;

/** Lỗi XÁC ĐỊNH (chắc chắn chưa tạo bài): PublishArticleJob đánh dấu failed. */
class WordPressException extends RuntimeException implements CmsErrorInterface
{
    use HasWordPressErrorCode;

    public function __construct(string $code, ?string $detail = null, ?Throwable $previous = null)
    {
        parent::__construct($this->initCode($code, $detail), 0, $previous);
    }
}
