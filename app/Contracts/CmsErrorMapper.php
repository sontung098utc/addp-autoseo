<?php

namespace App\Contracts;

use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use InvalidArgumentException;
use Throwable;

/**
 * Ánh xạ ngoại lệ từ tầng HTTP (Guzzle / Laravel Http) sang CmsError chuẩn ERR01..ERR07 (PR-D).
 */
final class CmsErrorMapper
{
    /** errno cURL xảy ra TRƯỚC khi request được gửi đi (DNS, refused, TLS/cert). */
    private const PRE_SEND_ERRNO = [6, 7, 35, 51, 58, 60];

    public static function fromThrowable(Throwable $e, bool $isWriteOperation = false, ?string $contextDetail = null): CmsError
    {
        if ($e instanceof CmsError) {
            return $e;
        }

        $detail = $contextDetail ?: $e->getMessage();

        if ($e instanceof InvalidArgumentException) {
            return CmsError::definitive(CmsErrorCode::ERR03, $detail, retryable: false, previous: $e);
        }

        if ($e instanceof GuzzleConnectException) {
            return self::fromGuzzleConnectException($e, $isWriteOperation, $contextDetail);
        }

        if ($e instanceof LaravelConnectionException) {
            $isTimeout = self::looksLikeTimeout($e->getMessage());
            if ($isWriteOperation && $isTimeout) {
                return CmsError::ambiguous(CmsErrorCode::ERR01, $detail, previous: $e);
            }

            return $isWriteOperation
                ? CmsError::ambiguous(CmsErrorCode::ERR01, $detail, previous: $e)
                : CmsError::definitive(CmsErrorCode::ERR07, $detail, retryable: true, previous: $e);
        }

        if ($e instanceof GuzzleRequestException && $e->hasResponse()) {
            $status = $e->getResponse()->getStatusCode();

            return self::fromHttpStatus($status, $isWriteOperation, $detail, $e);
        }

        if ($e instanceof LaravelRequestException && $e->response !== null) {
            return self::fromHttpStatus($e->response->status(), $isWriteOperation, $detail, $e);
        }

        if ($isWriteOperation) {
            return CmsError::ambiguous(CmsErrorCode::ERR01, $detail, previous: $e);
        }

        return CmsError::definitive(CmsErrorCode::ERR06, $detail, retryable: false, previous: $e);
    }

    public static function fromGuzzleConnectException(GuzzleConnectException $e, bool $isWriteOperation = false, ?string $contextDetail = null): CmsError
    {
        $ctx = $e->getHandlerContext();
        $errno = (int) ($ctx['errno'] ?? 0);
        $connectTime = (float) ($ctx['connect_time'] ?? 0.0);
        $detail = $contextDetail ?: $e->getMessage();

        $isExplicitTimeout = ($errno === 28 && ($connectTime > 0.0 || self::looksLikeTimeout($e->getMessage())))
            || ($errno === 0 && self::looksLikeTimeout($e->getMessage()));

        $isPreSend = in_array($errno, self::PRE_SEND_ERRNO, true)
            || ($errno === 28 && $connectTime <= 0.0 && ! self::looksLikeTimeout($e->getMessage()))
            || ($errno === 0 && ! $isExplicitTimeout);

        if ($isPreSend) {
            return CmsError::definitive(CmsErrorCode::ERR07, $detail, retryable: true, previous: $e);
        }

        if ($isWriteOperation) {
            return CmsError::ambiguous(CmsErrorCode::ERR01, $detail, previous: $e);
        }

        return CmsError::definitive(CmsErrorCode::ERR01, $detail, retryable: true, previous: $e);
    }

    public static function fromHttpStatus(int $status, bool $isWriteOperation = false, ?string $detail = null, ?Throwable $previous = null): CmsError
    {
        $info = $detail ? "HTTP {$status}: {$detail}" : "HTTP {$status}";

        if ($status === 401 || $status === 403) {
            return CmsError::definitive(CmsErrorCode::ERR02, $info, retryable: false, httpStatus: $status, previous: $previous);
        }

        if ($status === 404 || ($status >= 300 && $status < 400)) {
            return CmsError::definitive(CmsErrorCode::ERR03, $info, retryable: false, httpStatus: $status, previous: $previous);
        }

        if ($status === 429) {
            return CmsError::definitive(CmsErrorCode::ERR04, $info, retryable: true, httpStatus: $status, previous: $previous);
        }

        if ($status >= 500) {
            return $isWriteOperation
                ? CmsError::ambiguous(CmsErrorCode::ERR05, $info, httpStatus: $status, previous: $previous)
                : CmsError::definitive(CmsErrorCode::ERR05, $info, retryable: true, httpStatus: $status, previous: $previous);
        }

        return CmsError::definitive(CmsErrorCode::ERR06, $info, retryable: false, httpStatus: $status, previous: $previous);
    }

    private static function looksLikeTimeout(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'timed out')
            || str_contains($lower, 'timeout')
            || str_contains($lower, 'curl error 28');
    }
}
