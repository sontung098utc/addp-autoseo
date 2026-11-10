<?php

namespace App\Services\Keywords;

use App\Contracts\KeywordMetricsProvider;
use App\Models\Keyword;

/** Số liệu GIẢ LẬP, tất định theo từ khóa, chỉ để thử giao diện ở môi trường local/test. Không phải lượt tìm thật. */
final class MockKeywordMetricsProvider implements KeywordMetricsProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function metrics(array $keywords, array $options = []): array
    {
        $rows = [];
        foreach ($keywords as $kw) {
            $key = Keyword::keyFor((string) $kw);
            if ($key === '') {
                continue;
            }
            $h = crc32($key);
            $rows[$key] = ['volume' => 10 * (1 + $h % 500), 'competition' => round((($h >> 8) % 1000) / 1000, 3), 'cpc' => round((($h >> 4) % 300) / 100, 2)];
        }

        return ['rows' => $rows, 'cost_vnd' => 0.0, 'provider' => 'mock', 'fetched_at' => now()->toIso8601String()];
    }
}
