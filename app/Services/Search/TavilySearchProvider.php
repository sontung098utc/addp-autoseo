<?php

namespace App\Services\Search;

use App\Contracts\WebSearchProvider;
use Illuminate\Support\Facades\Http;

/** Tavily search API (POST /search, search_depth basic). Khóa lấy từ config('search.tavily.key'); không bao giờ ghi khóa vào thông báo lỗi. */
final class TavilySearchProvider implements WebSearchProvider
{
    public function __construct(private ?string $key = null, private ?string $url = null)
    {
        $this->key ??= (string) config('search.tavily.key');
        $this->url ??= (string) config('search.tavily.url', 'https://api.tavily.com/search');
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        if ($query === '' || (string) $this->key === '') {
            throw new WebSearchUnavailable('Chưa có từ khóa tìm hoặc chưa đặt TAVILY_API_KEY.');
        }
        $max = max(1, min(10, (int) ($options['max_results'] ?? config('search.max_results', 10))));
        $body = ['api_key' => $this->key, 'query' => mb_substr($query, 0, 400), 'search_depth' => 'basic', 'max_results' => $max];
        foreach (['include_domains', 'exclude_domains'] as $k) {
            $list = array_values(array_filter(array_map('strval', (array) ($options[$k] ?? []))));
            if ($list !== []) {
                $body[$k] = $list;
            }
        }
        try {
            $resp = Http::acceptJson()->timeout((int) config('search.timeout', 15))->connectTimeout(5)->post((string) $this->url, $body);
        } catch (\Throwable $e) {
            throw new WebSearchUnavailable('Không gọi được dịch vụ tìm kiếm: '.$this->mask($e->getMessage()));
        }
        if (! $resp->successful()) {
            throw new WebSearchUnavailable('Dịch vụ tìm kiếm trả mã '.$resp->status().($resp->status() === 401 ? ' (khóa TAVILY_API_KEY không đúng hoặc hết hạn)' : '').'.');
        }
        $rows = $resp->json('results');
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $url = trim((string) ($r['url'] ?? ''));
            if ($url === '' || ! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $out[] = [
                'title' => mb_substr(trim((string) ($r['title'] ?? '')) ?: $url, 0, 200),
                'url' => mb_substr($url, 0, 2048),
                'snippet' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($r['content'] ?? '')) ?? ''), 0, 400),
                'score' => isset($r['score']) ? (float) $r['score'] : 0.0,
                'published_at' => isset($r['published_date']) ? (string) $r['published_date'] : null,
            ];
        }

        return ['results' => $out, 'cost_vnd' => (float) config('search.cost_per_search_vnd', 200), 'provider' => 'tavily'];
    }

    private function mask(string $m): string
    {
        if ((string) $this->key !== '') {
            $m = str_replace((string) $this->key, '***', $m);
        }

        return mb_substr(preg_replace('/(api_key["\']?\s*[:=]\s*["\']?)[^"\'&\s]+/i', '$1***', $m) ?? '', 0, 200);
    }
}
