<?php

namespace App\Services\Keywords\Serp;

use App\Contracts\SerpInsightsProvider;
use Illuminate\Support\Facades\Http;

/**
 * DataForSEO SERP API (Google organic, live, advanced): top 10, "Mọi người cũng hỏi", "Tìm kiếm liên quan".
 * Xác thực HTTP Basic bằng DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD. Chi phí là ƯỚC TÍNH theo config.
 */
final class DataForSeoSerpProvider implements SerpInsightsProvider
{
    public function __construct(private readonly string $login, private readonly string $password) {}

    public function name(): string
    {
        return 'dataforseo';
    }

    public function insights(string $keyword, array $options = []): array
    {
        $keyword = trim(preg_replace('/\s+/u', ' ', $keyword) ?? '');
        $out = ['paa' => [], 'related' => [], 'autocomplete' => [], 'organic' => [], 'provider' => $this->name(), 'cost_vnd' => 0.0, 'fetched_at' => now()->toIso8601String()];
        if ($keyword === '') {
            return $out;
        }
        $body = [[
            'keyword' => mb_substr($keyword, 0, 700),
            'location_code' => (int) ($options['location_code'] ?? config('keywords.location_code', 2704)),
            'language_code' => (string) ($options['language_code'] ?? config('keywords.language_code', 'vi')),
            'depth' => (int) config('keywords.serp.depth', 10),
        ]];
        try {
            $res = Http::withBasicAuth($this->login, $this->password)->acceptJson()->timeout(60)->post((string) config('keywords.serp.endpoint'), $body);
        } catch (\Throwable $e) {
            throw new SerpFailed('Không kết nối được DataForSEO SERP: '.$this->mask($e->getMessage()));
        }
        if (! $res->successful()) {
            throw new SerpFailed('DataForSEO SERP trả lỗi HTTP '.$res->status().($res->status() === 401 ? ' (sai DATAFORSEO_LOGIN hoặc DATAFORSEO_PASSWORD).' : '.'));
        }
        $json = $res->json();
        $task = is_array($json) ? ($json['tasks'][0] ?? null) : null;
        if ((int) ($json['status_code'] ?? 0) !== 20000 || (int) ($task['status_code'] ?? 0) !== 20000) {
            throw new SerpFailed('DataForSEO SERP báo lỗi '.((int) ($task['status_code'] ?? $json['status_code'] ?? 0) ?: '?').': '.$this->mask((string) ($task['status_message'] ?? $json['status_message'] ?? 'phản hồi không hợp lệ')));
        }
        $out['cost_vnd'] = (float) config('keywords.serp.cost_per_query_vnd', 0);
        foreach ((array) ($task['result'][0]['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            switch ($item['type'] ?? '') {
                case 'organic':
                    $url = (string) ($item['url'] ?? '');
                    if (preg_match('#^https?://#i', $url)) {
                        $out['organic'][] = ['rank' => (int) ($item['rank_group'] ?? count($out['organic']) + 1), 'url' => $url, 'title' => trim((string) ($item['title'] ?? '')), 'domain' => strtolower(preg_replace('/^www\./i', '', (string) ($item['domain'] ?? parse_url($url, PHP_URL_HOST)))) ];
                    }
                    break;
                case 'people_also_ask':
                    foreach ((array) ($item['items'] ?? []) as $q) {
                        $t = trim((string) ($q['title'] ?? ''));
                        if ($t !== '') {
                            $out['paa'][] = $t;
                        }
                    }
                    break;
                case 'related_searches':
                    foreach ((array) ($item['items'] ?? []) as $q) {
                        $t = trim(is_array($q) ? (string) ($q['title'] ?? '') : (string) $q);
                        if ($t !== '') {
                            $out['related'][] = $t;
                        }
                    }
                    break;
            }
        }
        $out['paa'] = array_values(array_unique($out['paa']));
        $out['related'] = array_values(array_unique($out['related']));

        return $out;
    }

    private function mask(string $s): string
    {
        foreach (array_filter([$this->login, $this->password, base64_encode($this->login.':'.$this->password)]) as $secret) {
            $s = str_replace($secret, '***', $s);
        }

        return mb_substr($s, 0, 200);
    }
}
