<?php

namespace App\Services\Agent\Context;

use Illuminate\Support\Str;

/** AG-S25: chuẩn hóa tiếng Việt không dấu + tách từ khóa dùng chung cho chọn ảnh và gợi ý liên kết. Tất định, không AI. */
final class TextMatch
{
    private const STOP = ['la', 'va', 'cua', 'cho', 'cac', 'nhung', 'mot', 'co', 'trong', 'the', 'nao', 'gi', 'de', 'khi', 'voi', 'tu', 'den', 'the', 'and', 'the', 'of', 'for'];

    public static function fold(string $s): string
    {
        $s = Str::ascii(mb_strtolower(strip_tags($s)));

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '');
    }

    /** @return list<string> các từ duy nhất (bỏ từ dừng, từ 1 ký tự) */
    public static function tokens(string $s): array
    {
        $out = [];
        foreach (explode(' ', self::fold($s)) as $t) {
            if (strlen($t) >= 2 && ! in_array($t, self::STOP, true)) {
                $out[$t] = true;
            }
        }

        return array_keys($out);
    }

    /** @param list<mixed> $outline @return list<string> từ trong dàn ý (tối đa 40) */
    public static function outlineTokens(array $outline): array
    {
        $text = implode(' ', array_map(fn ($o) => is_scalar($o) ? (string) $o : (is_array($o) ? implode(' ', array_filter($o, 'is_scalar')) : ''), array_slice($outline, 0, 12)));

        return array_slice(self::tokens($text), 0, 40);
    }

    /** Tỉ lệ $needles xuất hiện trong $haystackTokens, 0..1. */
    public static function coverage(array $needles, array $haystackTokens): float
    {
        return $needles === [] ? 0.0 : count(array_intersect($needles, $haystackTokens)) / count($needles);
    }
}
