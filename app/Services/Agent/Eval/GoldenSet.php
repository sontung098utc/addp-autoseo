<?php

namespace App\Services\Agent\Eval;

use InvalidArgumentException;

/** Nạp mẫu vàng từ thư mục JSON. File sai định dạng làm cả bộ thất bại, không bỏ sót âm thầm. */
final class GoldenSet
{
    /** @return list<GoldenCase> */
    public static function load(string $dir): array
    {
        $files = glob(rtrim($dir, '/').'/*.json') ?: [];
        sort($files);
        $cases = [];
        $seen = [];
        foreach ($files as $f) {
            $data = json_decode((string) file_get_contents($f), true);
            if (! is_array($data) || ! isset($data['playbook'], $data['cases']) || ! is_array($data['cases'])) {
                throw new InvalidArgumentException(basename($f).': thiếu playbook/cases hoặc JSON hỏng.');
            }
            foreach ($data['cases'] as $c) {
                if (! isset($c['id'], $c['input']) || ! is_array($c['input']) || ! isset($c['expect_blocking']) || ! is_array($c['expect_blocking'])) {
                    throw new InvalidArgumentException(basename($f).': mẫu thiếu id/input/expect_blocking.');
                }
                if (isset($seen[$c['id']])) {
                    throw new InvalidArgumentException("Trùng id mẫu: {$c['id']}");
                }
                $seen[$c['id']] = true;
                $cases[] = new GoldenCase($c['id'], $data['playbook'], $c['input'], array_values($c['expect_blocking']), (string) ($c['note'] ?? ''));
            }
        }

        return $cases;
    }
}
