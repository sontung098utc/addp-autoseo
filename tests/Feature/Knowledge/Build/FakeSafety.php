<?php

namespace Tests\Feature\Knowledge\Build;

use App\Services\Security\UrlSafetyService;

/** Kiểm an toàn thật (giao thức, host nội bộ, dải IP) nhưng không phân giải DNS thật: mọi tên miền công khai → một IP công khai. */
final class FakeSafety extends UrlSafetyService
{
    protected function resolveIps(string $host): array
    {
        return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ['93.184.216.34'];
    }
}
