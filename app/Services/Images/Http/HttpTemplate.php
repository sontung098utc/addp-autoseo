<?php

namespace App\Services\Images\Http;

/**
 * Thay chỗ trống {{tên}} trong mẫu body/đường dẫn và đọc giá trị theo đường dẫn JSON. Chỉ thay chuỗi, KHÔNG chạy mã.
 * Đường dẫn JSON: "data.0.url"; nhiều phương án cách nhau "|" ("jobId|data.jobId"), lấy phương án đầu tiên có giá trị.
 */
final class HttpTemplate
{
    public const PLACEHOLDERS = ['prompt', 'model', 'aspect', 'size', 'width', 'height', 'resolution', 'request_id', 'job_id'];

    /** @param array<string, mixed> $vars */
    public static function render(mixed $tpl, array $vars): mixed
    {
        if (is_array($tpl)) {
            return array_map(fn ($v) => self::render($v, $vars), $tpl);
        }
        if (! is_string($tpl)) {
            return $tpl;
        }
        if (preg_match('/^\{\{(\w+)\}\}$/', $tpl, $m)) {
            return $vars[$m[1]] ?? '';
        }

        return preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $tpl);
    }

    /** @return list<string> tên chỗ trống có trong mẫu */
    public static function placeholdersIn(mixed $tpl): array
    {
        if (is_array($tpl)) {
            return array_values(array_unique(array_merge([], ...array_values(array_map(fn ($v) => self::placeholdersIn($v), $tpl)))));
        }

        return is_string($tpl) && preg_match_all('/\{\{(\w+)\}\}/', $tpl, $m) ? array_values(array_unique($m[1])) : [];
    }

    /** Giá trị đầu tiên khác null theo danh sách đường dẫn "a.b|c.d". */
    public static function get(mixed $data, string $paths): mixed
    {
        foreach (explode('|', $paths) as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $cur = $data;
            foreach (explode('.', $path) as $seg) {
                if (is_array($cur) && array_key_exists($seg, $cur)) {
                    $cur = $cur[$seg];
                } else {
                    $cur = null;
                    break;
                }
            }
            if ($cur !== null) {
                return $cur;
            }
        }

        return null;
    }
}
