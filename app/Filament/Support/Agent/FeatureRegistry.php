<?php

namespace App\Filament\Support\Agent;

/**
 * AG-U02: sổ tính năng — NGUỒN DUY NHẤT cho câu hỏi "tính năng này đã chạy thật chưa?" (trang "Cách hoạt động").
 *
 * Mỗi module là MỘT FILE trong Features/*.php (trả về mảng các mục), để nhiều task thêm mục mà không đụng nhau.
 * Quy tắc cứng (có test): mức "active" (Đang hoạt động) BẮT BUỘC có `evidence` (bằng chứng cụ thể: test, commit, dữ liệu thật);
 * không ai tô "xanh" bằng tay. Sổ này phản ánh trạng thái của nhánh đang chạy, không phải kế hoạch.
 *
 * Mục: id, title, what, readiness(active|demo|not_connected|in_development|idea), evidence, caveat, task, route (FQCN của Resource/Page).
 */
final class FeatureRegistry
{
    public const READINESS = ['active', 'demo', 'not_connected', 'in_development', 'idea'];

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $entries = [];
        foreach (glob(__DIR__.'/Features/*.php') ?: [] as $file) {
            $rows = require $file;
            foreach ((array) $rows as $row) {
                $entries[] = $row + ['evidence' => '', 'caveat' => '', 'task' => '', 'route' => null];
            }
        }

        return $entries;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public static function byReadiness(string $readiness): array
    {
        return array_values(array_filter(self::all(), fn (array $e) => $e['readiness'] === $readiness));
    }

    /** Đếm theo mức sẵn sàng. @return array<string, int> */
    public static function counts(): array
    {
        $c = array_fill_keys(self::READINESS, 0);
        foreach (self::all() as $e) {
            $c[$e['readiness']]++;
        }

        return $c;
    }
}
