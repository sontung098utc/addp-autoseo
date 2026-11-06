<?php

namespace App\Integrations\WordPress\Exceptions;

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

    protected function initCode(string $code, ?string $detail): string
    {
        $this->errorCode = $code;
        $base = WordPressErrorCode::MESSAGES[$code] ?? 'Lỗi WordPress.';

        return "[{$code}] " . $base . ($detail ? " ({$detail})" : '');
    }
}
