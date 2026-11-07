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
    ) {
    }
}
