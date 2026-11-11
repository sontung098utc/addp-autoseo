<?php

namespace App\Contracts;

use App\Filament\Support\Schedule\ErrorMasker;
use RuntimeException;
use Throwable;

/**
 * Ngoại lệ chuẩn hóa cho mọi CMS connector (PR-D).
 * Mọi thông điệp chi tiết đều được lọc qua ErrorMasker trước khi đưa vào message.
 */
class CmsError extends RuntimeException implements CmsErrorInterface
{
    public readonly string $errorCode;
    public readonly bool $retryable;
    public readonly bool $needsReconcile;
    public readonly ?int $httpStatus;

    public function __construct(
        string $errorCode,
        bool $retryable = false,
        bool $needsReconcile = false,
        ?string $detail = null,
        ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        $this->errorCode = $errorCode;
        $this->retryable = $retryable;
        $this->needsReconcile = $needsReconcile;
        $this->httpStatus = $httpStatus;

        $base = CmsErrorCode::messageFor($errorCode);
        $safeDetail = $detail !== null && $detail !== '' ? ErrorMasker::mask($detail) : null;
        $message = "[{$errorCode}] {$base}" . ($safeDetail ? " ({$safeDetail})" : '');

        parent::__construct($message, 0, $previous);
    }

    /**
     * Lỗi mơ hồ sau khi đã gửi yêu cầu ghi -> bắt buộc needs_reconcile, KHÔNG retry mù.
     */
    public static function ambiguous(
        string $code = CmsErrorCode::ERR01,
        ?string $detail = null,
        ?int $httpStatus = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            errorCode: $code,
            retryable: false,
            needsReconcile: true,
            detail: $detail,
            httpStatus: $httpStatus,
            previous: $previous,
        );
    }

    /**
     * Lỗi xác định (chắc chắn chưa ghi thành công lên CMS).
     */
    public static function definitive(
        string $code,
        ?string $detail = null,
        bool $retryable = false,
        ?int $httpStatus = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            errorCode: $code,
            retryable: $retryable,
            needsReconcile: false,
            detail: $detail,
            httpStatus: $httpStatus,
            previous: $previous,
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    public function isAmbiguous(): bool
    {
        return $this->needsReconcile;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function category(): string
    {
        return CmsErrorCode::categoryFor($this->errorCode);
    }
}
