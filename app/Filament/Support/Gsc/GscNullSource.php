<?php

namespace App\Filament\Support\Gsc;

use Carbon\CarbonImmutable;

/** Nguồn rỗng: dùng ở production cho tới khi có kết nối Google thật. Không bao giờ bày số mẫu. */
final class GscNullSource implements GscSource
{
    public function rows(string $dimension, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }

    public function isEmpty(): bool
    {
        return true;
    }
}
