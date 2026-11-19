<?php

namespace App\Services\Agent\Run;

use App\Services\Ai\AiProviderError;
use App\Services\Ai\AiQuotaExceeded;
use App\Services\Ai\AiSchemaError;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException as HttpRequestException;
use Throwable;

/**
 * Phân loại lỗi nhà cung cấp AI thành lý do THẬT bằng tiếng Việt ngắn (kèm mã HTTP và gợi ý việc nên làm) để người xem biết phải làm gì,
 * thay vì chỉ thấy tên lớp ngoại lệ. Không bao giờ đưa thông điệp gốc (có thể chứa khóa/URL) vào kết quả: chỉ dùng loại, mã HTTP và câu cố định.
 */
final class AiFailureReason
{
    public const QUOTA = 'quota';

    public const AUTH = 'auth';

    public const MODEL_MISSING = 'model_missing';

    public const TIMEOUT = 'timeout';

    public const NETWORK = 'network';

    public const SERVER = 'server';

    public const BAD_FORMAT = 'bad_format';

    public const REJECTED = 'rejected';

    public const UNKNOWN = 'unknown';

    private function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly string $hint,
        public readonly ?int $status = null,
    ) {
    }

    public static function of(Throwable $e): self
    {
        $status = self::statusOf($e);
        $http = $status !== null && $status > 0 ? " (HTTP {$status})" : '';

        if (self::chainHas($e, AiQuotaExceeded::class) || $status === 429) {
            return new self(self::QUOTA, "Nhà cung cấp AI từ chối vì hết hạn mức{$http}", 'Chờ hạn mức đặt lại, đổi sang model khác hoặc bật thanh toán cho khóa API ở Đội AI → Tích hợp AI.', $status ?? 429);
        }
        if ($status === 401 || $status === 403) {
            return new self(self::AUTH, "Nhà cung cấp AI từ chối khóa API{$http}", 'Kiểm tra lại khóa API và quyền của khóa ở Đội AI → Tích hợp AI.', $status);
        }
        if ($status === 404) {
            return new self(self::MODEL_MISSING, "Model hoặc địa chỉ AI không tồn tại{$http}", 'Kiểm tra tên model và địa chỉ nhà cung cấp ở Đội AI → Tích hợp AI.', $status);
        }
        if ($status === 408 || $status === 504 || self::networkKind($e) === 'timeout') {
            return new self(self::TIMEOUT, 'Nhà cung cấp AI không trả lời kịp (hết thời gian chờ)'.($status ? " (HTTP {$status})" : ''), 'Thử lại sau ít phút; nếu lặp lại, đổi sang model nhẹ hơn hoặc nhà cung cấp khác.', $status);
        }
        if ($status !== null && $status >= 500) {
            return new self(self::SERVER, "Nhà cung cấp AI đang gặp sự cố phía họ{$http}", 'Chờ nhà cung cấp phục hồi rồi chạy lại; có thể đặt model dự phòng ở Đội AI → Tích hợp AI.', $status);
        }
        if (self::networkKind($e) !== null || self::chainHas($e, ConnectionException::class)) {
            return new self(self::NETWORK, 'Không kết nối được tới nhà cung cấp AI (lỗi mạng hoặc DNS)', 'Kiểm tra mạng và tên miền của máy chủ rồi chạy lại.', $status);
        }
        if (self::chainHas($e, AiSchemaError::class)) {
            return new self(self::BAD_FORMAT, 'Nhà cung cấp AI trả phản hồi sai định dạng', 'Chạy lại; nếu lặp lại, đổi sang model khác ở Đội AI → Tích hợp AI.', $status);
        }
        if (self::chainHas($e, TransientAiError::class)) {
            return new self(self::SERVER, 'Nhà cung cấp AI báo lỗi tạm thời'.$http, 'Chờ ít phút rồi chạy lại.', $status);
        }
        if (self::chainHas($e, AiProviderError::class)) {
            return new self(self::REJECTED, 'Nhà cung cấp AI từ chối yêu cầu'.$http, 'Kiểm tra tên model và cấu hình ở Đội AI → Tích hợp AI.', $status);
        }

        return new self(self::UNKNOWN, 'Lỗi AI chưa phân loại được', 'Xem nhật ký hệ thống để biết chi tiết.', $status);
    }

    /** Một câu đủ ý để ghi vào diễn biến/lý do phiên: nhãn + gợi ý. */
    public function summary(): string
    {
        return $this->label.'. '.$this->hint;
    }

    public static function status(Throwable $e): ?int
    {
        return self::statusOf($e);
    }

    private static function statusOf(Throwable $e): ?int
    {
        for ($i = 0; $e !== null && $i < 4; $i++, $e = $e->getPrevious()) {
            $s = match (true) {
                $e instanceof TransientAiError => $e->status,
                $e instanceof AiProviderError => $e->status,
                $e instanceof GuzzleRequestException => $e->getResponse()?->getStatusCode(),
                $e instanceof HttpRequestException => $e->response->status(),
                default => null,
            };
            if ($s !== null && $s > 0) {
                return $s;
            }
        }

        return null;
    }

    private static function chainHas(Throwable $e, string $class): bool
    {
        for ($i = 0; $e !== null && $i < 4; $i++, $e = $e->getPrevious()) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /** 'timeout' | 'network' | null: đọc thông điệp của lỗi mạng gốc chỉ để PHÂN LOẠI, không bao giờ đưa vào kết quả. */
    private static function networkKind(Throwable $e): ?string
    {
        for ($i = 0; $e !== null && $i < 4; $i++, $e = $e->getPrevious()) {
            if (! ($e instanceof ConnectionException || $e instanceof \GuzzleHttp\Exception\ConnectException)) {
                continue;
            }
            $m = mb_strtolower($e->getMessage());
            if (str_contains($m, 'timed out') || str_contains($m, 'timeout') || str_contains($m, 'error 28')) {
                return 'timeout';
            }

            return 'network'; // ConnectionException/ConnectException không phải hết giờ thì là mất mạng hoặc không phân giải được tên miền
        }

        return null;
    }
}
