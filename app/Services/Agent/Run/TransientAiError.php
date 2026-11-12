<?php

namespace App\Services\Agent\Run;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException as HttpRequestException;
use RuntimeException;
use Throwable;

/**
 * Phân loại lỗi nhà cung cấp AI: lỗi TẠM THỜI (timeout, không nối được, 5xx, 429, 408) mới được thử lại.
 * Lỗi còn lại (khóa sai 401/403, phản hồi sai, lỗi lập trình) không thử lại vì thử lại không thay đổi kết quả.
 * Một nhà cung cấp tự biết lỗi của mình là tạm thời có thể ném chính lớp này.
 */
final class TransientAiError extends RuntimeException
{
    /** Số giây nhà cung cấp yêu cầu chờ (Retry-After / RetryInfo), null nếu không nói; status là mã HTTP nếu có. */
    public ?int $retryAfter = null;

    public ?int $status = null;

    public static function withHint(string $message, ?int $status, ?int $retryAfter): self
    {
        $e = new self($message);
        $e->status = $status;
        $e->retryAfter = $retryAfter;

        return $e;
    }

    /** Gợi ý chờ của nhà cung cấp trong chuỗi lỗi (nếu có). */
    public static function retryAfterOf(Throwable $e): ?int
    {
        for ($i = 0; $e !== null && $i < 3; $i++, $e = $e->getPrevious()) {
            if ($e instanceof self) {
                return $e->retryAfter;
            }
        }

        return null;
    }

    /** Mã HTTP của lỗi tạm thời trong chuỗi lỗi (nếu có). */
    public static function statusOf(Throwable $e): ?int
    {
        for ($i = 0; $e !== null && $i < 3; $i++, $e = $e->getPrevious()) {
            $s = match (true) {
                $e instanceof self => $e->status,
                $e instanceof GuzzleRequestException => $e->getResponse()?->getStatusCode(),
                $e instanceof HttpRequestException => $e->response->status(),
                default => null,
            };
            if ($s !== null) {
                return $s;
            }
        }

        return null;
    }

    public static function is(Throwable $e): bool
    {
        for ($i = 0; $e !== null && $i < 3; $i++, $e = $e->getPrevious()) {
            if ($e instanceof self || $e instanceof ConnectException || $e instanceof ConnectionException) {
                return true;
            }
            $status = match (true) {
                $e instanceof GuzzleRequestException => $e->getResponse()?->getStatusCode(),
                $e instanceof HttpRequestException => $e->response->status(),
                default => null,
            };
            if ($status !== null && ($status >= 500 || in_array($status, [408, 429], true))) {
                return true;
            }
        }

        return false;
    }

    /** Mô tả ngắn, an toàn để ghi nhật ký: tên lỗi + mã HTTP, không kèm thông điệp gốc (có thể chứa khóa/URL). */
    public static function describe(Throwable $e): string
    {
        $status = match (true) {
            $e instanceof GuzzleRequestException => $e->getResponse()?->getStatusCode(),
            $e instanceof HttpRequestException => $e->response->status(),
            default => null,
        };

        return class_basename($e).($status ? " (HTTP {$status})" : '');
    }
}
