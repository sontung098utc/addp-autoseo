<?php

namespace App\Filament\Support\Schedule;

/** Che chuỗi giống credential / token trong thông báo lỗi trước khi hiển thị (AGENTS.md §1.1). */
final class ErrorMasker
{
    public const MASK = '••••';

    public static function mask(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // URL: bỏ query string / userinfo (thường chứa token)
        $text = preg_replace('~(https?://)[^/\s@]+@~i', '$1'.self::MASK.'@', $text);
        $text = preg_replace('~(https?://[^\s?#]+)\?[^\s]*~i', '$1?'.self::MASK, $text);
        // Authorization: Bearer xxx / Basic xxx
        $text = preg_replace('~\b(Bearer|Basic)\s+[A-Za-z0-9._\~+/=\-]+~i', '$1 '.self::MASK, $text);
        // key=value / key: value với tên nhạy cảm
        $text = preg_replace(
            '~\b((?:api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret|password|passwd|pwd|authorization|app[_-]?password)["\']?\s*[:=]\s*["\']?)[^\s"\',;&]+~i',
            '$1'.self::MASK,
            $text
        );
        // Chuỗi dài giống khóa (>= 24 ký tự chữ-số-gạch, có cả chữ và số)
        $text = preg_replace_callback('~\b[A-Za-z0-9_\-]{24,}\b~', fn ($m) => preg_match('/\d/', $m[0]) && preg_match('/[A-Za-z]/', $m[0]) ? self::MASK : $m[0], $text);

        return $text;
    }

    public static function short(?string $text, int $max = 80): string
    {
        return \Illuminate\Support\Str::limit(self::mask($text), $max);
    }
}
