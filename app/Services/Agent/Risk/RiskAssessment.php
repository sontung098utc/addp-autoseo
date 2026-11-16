<?php

namespace App\Services\Agent\Risk;

/** Kết quả phân hạng. `reasons` luôn có để người xem biết vì sao (không có hạng "bí ẩn"). */
final class RiskAssessment
{
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';

    /**
     * @param  list<string>  $reasons
     * @param  list<array{group: string, phrase: string}>  $sensitiveClaims
     */
    public function __construct(
        public readonly string $level,
        public readonly array $reasons,
        public readonly array $sensitiveClaims,
    ) {
    }

    /** Chỉ hạng thấp VÀ không có claim nhạy cảm mới đủ điều kiện tự đăng (khi chính sách tin cậy cho phép). */
    public function eligibleForAutoPublish(): bool
    {
        return $this->level === self::LOW && $this->sensitiveClaims === [];
    }
}
