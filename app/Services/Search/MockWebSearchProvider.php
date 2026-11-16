<?php

namespace App\Services\Search;

use App\Contracts\WebSearchProvider;
use Illuminate\Support\Str;

/** Kết quả giả, tất định, CHỈ cho môi trường local/test. Luôn có nhãn "mô phỏng"; không bao giờ dùng im lặng ở production. */
final class MockWebSearchProvider implements WebSearchProvider
{
    public function search(string $query, array $options = []): array
    {
        $q = trim($query);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(Str::ascii($q))), '-') ?: 'chu-de';
        $rows = [
            ['title' => '[Mô phỏng] Hướng dẫn về '.$q.' (WHO)', 'url' => 'https://www.who.int/mo-phong/'.$slug, 'snippet' => 'Kết quả mô phỏng, không phải trang thật.', 'score' => 0.9],
            ['title' => '[Mô phỏng] Khuyến cáo về '.$q.' (Bộ Y tế)', 'url' => 'https://moh.gov.vn/mo-phong/'.$slug, 'snippet' => 'Kết quả mô phỏng, không phải trang thật.', 'score' => 0.8],
            ['title' => '[Mô phỏng] Bài blog về '.$q, 'url' => 'https://blog-mo-phong.example.com/'.$slug, 'snippet' => 'Kết quả mô phỏng, không phải trang thật.', 'score' => 0.5],
        ];
        $include = array_map('strtolower', (array) ($options['include_domains'] ?? []));
        if ($include !== []) {
            $rows = array_values(array_filter($rows, fn ($r) => array_filter($include, fn ($d) => str_contains((string) parse_url($r['url'], PHP_URL_HOST), $d)) !== []));
        }
        $rows = array_slice($rows, 0, max(1, min(10, (int) ($options['max_results'] ?? 10))));
        foreach ($rows as &$r) {
            $r['published_at'] = null;
        }

        return ['results' => $rows, 'cost_vnd' => 0.0, 'provider' => 'mock'];
    }
}
