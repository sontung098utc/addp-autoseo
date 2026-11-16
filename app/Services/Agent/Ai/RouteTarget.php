<?php

namespace App\Services\Agent\Ai;

use App\Contracts\AiProviderInterface;

/** Một đích đã giải quyết: adapter sẵn dùng + model + trần chi phí mỗi lần gọi. */
final class RouteTarget
{
    public function __construct(
        public readonly AiProviderInterface $ai,
        public readonly int $providerId,
        public readonly string $providerName,
        public readonly string $kind,
        public readonly string $model,
        public readonly ?float $costCapVnd,
    ) {
    }

    public function label(): string
    {
        return "{$this->providerName} · {$this->model}";
    }
}
