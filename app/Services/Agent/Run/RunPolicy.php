<?php

namespace App\Services\Agent\Run;

/** Giới hạn của một phiên chạy. Mặc định an toàn; Dev1 nối vào cài đặt thật ở AG-B09 (ngân sách, ngắt mạch). */
final class RunPolicy
{
    public function __construct(
        public readonly int $maxRepairRounds = 3,
        public readonly float $budgetVnd = 50000.0,
        public readonly string $trustTier = 'T1',
    ) {
    }
}
