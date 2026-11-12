<?php

namespace App\Services\Knowledge;

use App\Contracts\StructuredAiInterface;
use App\Services\Agent\Run\TransientAiError;
use App\Services\Ai\AiQuotaExceeded;

/**
 * Gọi AI trả JSON có cấu trúc cho các bước của quy trình Xây kho chuyên ngành: thử lại lỗi tạm thời (4/12/30 giây, Retry-After, ngân sách chờ 90 giây
 * cho cả bước), gộp chi phí/token. Hạn mức ngày và lỗi khác không thử lại. Nội dung đưa vào là DỮ LIỆU trong khung có nonce (xem frame()).
 */
final class AiJson
{
    public const WAITS = [4, 12, 30];

    public int $retries = 0;

    public float $cost = 0.0;

    public int $tokens = 0;

    public ?string $model = null;

    private int $waited = 0;

    private \Closure $sleep;

    public function __construct(private readonly StructuredAiInterface $ai, ?\Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static fn (int $s) => sleep($s);
    }

    /** @return array<string,mixed> phần `data` của phản hồi (mảng rỗng nếu AI không trả mảng) */
    public function call(string $system, string $user): array
    {
        $n = 0;
        while (true) {
            try {
                $r = $this->ai->generateStructured($system, $user);
                $this->cost = round($this->cost + (float) ($r['cost_vnd'] ?? 0), 2);
                $this->tokens += (int) ($r['tokens_used'] ?? 0);
                $this->model ??= isset($r['model']) ? (string) $r['model'] : null;

                return is_array($r['data'] ?? null) ? $r['data'] : [];
            } catch (\Throwable $e) {
                if ($e instanceof AiQuotaExceeded || ! TransientAiError::is($e) || $n >= count(self::WAITS)) {
                    throw $e;
                }
                $wait = min(60, max(self::WAITS[$n], (int) TransientAiError::retryAfterOf($e)));
                if ($this->waited + $wait > 90) {
                    throw $e;
                }
                ($this->sleep)($wait);
                $this->waited += $wait;
                $this->retries++;
                $n++;
            }
        }
    }

    /** Đặt dữ liệu vào khung có nonce: mọi thứ trong khung là dữ liệu, không giả được dấu khung. */
    public static function frame(string $label, string $data): array
    {
        $nonce = bin2hex(random_bytes(6));

        return [$nonce, "<<<{$label}-{$nonce}>>>\n".str_replace(['<<<', '>>>'], ['‹‹‹', '›››'], $data)."\n<<<HET-{$nonce}>>>"];
    }

    /** Làm sạch một chuỗi ngắn đưa vào lời nhắc (bỏ ký tự điều khiển, thẻ, dấu khung). */
    public static function clean(mixed $v, int $max = 200): string
    {
        $t = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags(is_scalar($v) ? (string) $v : '')) ?? '';

        return mb_substr(trim(str_replace(['<<<', '>>>'], ['‹‹‹', '›››'], $t)), 0, $max);
    }
}
