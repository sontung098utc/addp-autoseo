<?php

namespace App\Filament\Support\AiUsage;

/** Định dạng tiền VND: làm tròn đồng, dấu chấm ngăn nghìn, ví dụ 2.893 ₫. */
final class Vnd
{
    public static function format(float|int|null $amount): string
    {
        if ($amount === null) {
            return '—';
        }

        return number_format((int) round($amount), 0, ',', '.').' ₫';
    }

    public static function int(int|float $n): string
    {
        return number_format((int) round($n), 0, ',', '.');
    }

    public static function percent(float $ratio): string
    {
        return number_format($ratio * 100, 0, ',', '.').'%';
    }
}
