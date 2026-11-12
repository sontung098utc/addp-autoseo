<?php

namespace App\Services\Agent\Run;

/** Giới hạn của một phiên chạy. Mặc định an toàn; Dev1 nối vào cài đặt thật ở AG-B09 (ngân sách, ngắt mạch). */
final class RunPolicy
{
    public function __construct(
        public readonly int $maxRepairRounds = 3,
        public readonly float $budgetVnd = 50000.0,
        public readonly string $trustTier = 'T1',
        /** Số lần THỬ LẠI sau lần gọi đầu khi nhà cung cấp lỗi tạm thời (tổng tối đa 1 + 3 lần gọi). */
        public readonly int $maxRetries = 3,
        /** @var list<int> giây chờ trước mỗi lần thử lại; thiếu phần tử thì dùng phần tử cuối */
        public readonly array $backoffSeconds = [2, 5, 12],
        /** Khi nhà cung cấp nói rõ cần chờ bao lâu (Retry-After) thì được thử lại nhiều lần hơn, nhưng mỗi lần chờ không quá và tổng chờ không quá các trần sau. */
        public readonly int $maxRateLimitRetries = 6,
        public readonly int $maxRetryWaitSeconds = 90,
        public readonly int $retryBudgetSeconds = 240,
    ) {
    }
}
