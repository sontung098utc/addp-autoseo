<?php

namespace App\Integrations\WordPress\Exceptions;

use App\Contracts\CmsErrorCode;

trait HasWordPressErrorCode
{
    protected string $errorCode = WordPressErrorCode::ERR06;

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /** Bài có thể đã được tạo trên WordPress: KHÔNG được retry tạo mới, phải đối soát. */
    public function isAmbiguous(): bool
    {
        return $this instanceof WordPressAmbiguousException;
    }

    public function isRetryable(): bool
    {
        if ($this->isAmbiguous()) {
            return false;
        }

        return in_array($this->errorCode, [
            WordPressErrorCode::ERR04,
            WordPressErrorCode::ERR05,
            WordPressErrorCode::ERR07,
        ], true);
    }

    public function httpStatus(): ?int
    {
        if (preg_match('/HTTP (\d{3})/', $this->getMessage(), $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public function category(): string
    {
        return CmsErrorCode::categoryFor($this->errorCode);
    }

    protected function initCode(string $code, ?string $detail): string
    {
        $this->errorCode = $code;
        $base = WordPressErrorCode::MESSAGES[$code] ?? 'Lỗi WordPress.';

        return "[{$code}] " . $base . ($detail ? " ({$detail})" : '');
    }
}
