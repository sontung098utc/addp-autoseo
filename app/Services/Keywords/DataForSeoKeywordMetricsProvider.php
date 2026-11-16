<?php

namespace App\Services\Keywords;

use App\Contracts\KeywordMetricsProvider;
use App\Models\Keyword;
use Illuminate\Support\Facades\Http;

/**
 * DataForSEO Keywords Data API (Google Ads, search_volume, live). Xác thực HTTP Basic bằng DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD.
 * Chi phí trả về là ƯỚC TÍNH theo config('keywords.cost_per_100_keywords_vnd'), không phải bảng giá chính thức.
 */
final class DataForSeoKeywordMetricsProvider implements KeywordMetricsProvider
{
    /** Giới hạn của API: từ khóa tối đa 80 ký tự và 10 từ. Từ khóa vượt giới hạn không được gửi (trả về null). */
    private const MAX_CHARS = 80;

    private const MAX_WORDS = 10;

    public function __construct(private readonly string $login, private readonly string $password) {}

    public function name(): string
    {
        return 'dataforseo';
    }

    public function metrics(array $keywords, array $options = []): array
    {
        $rows = [];
        $send = [];
        foreach ($keywords as $kw) {
            $key = Keyword::keyFor((string) $kw);
            if ($key === '' || isset($rows[$key])) {
                continue;
            }
            $rows[$key] = ['volume' => null, 'competition' => null, 'cpc' => null];
            if (mb_strlen($key) <= self::MAX_CHARS && count(preg_split('/\s+/u', $key) ?: []) <= self::MAX_WORDS) {
                $send[] = $key;
            }
        }
        $batch = max(1, min(700, (int) config('keywords.batch', 100)));
        $requests = 0;
        foreach (array_chunk($send, $batch) as $chunk) {
            $requests++;
            foreach ($this->call($chunk, $options) as $item) {
                $key = Keyword::keyFor((string) ($item['keyword'] ?? ''));
                if (! isset($rows[$key])) {
                    continue;
                }
                $rows[$key] = [
                    'volume' => isset($item['search_volume']) && is_numeric($item['search_volume']) ? max(0, (int) $item['search_volume']) : null,
                    'competition' => self::competition($item),
                    'cpc' => isset($item['cpc']) && is_numeric($item['cpc']) ? (float) $item['cpc'] : null,
                ];
            }
        }

        return [
            'rows' => $rows,
            'cost_vnd' => self::estimateCost(count($send), $batch),
            'provider' => $this->name(),
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /** Ước tính theo số lô 100 từ khóa. */
    public static function estimateCost(int $keywords, ?int $batch = null): float
    {
        $per = (float) config('keywords.cost_per_100_keywords_vnd', 0);

        return round(ceil($keywords / 100) * $per, 2);
    }

    /** competition_index (0–100) chia 100; không có thì để null (không đoán từ chữ LOW/MEDIUM/HIGH). */
    private static function competition(array $item): ?float
    {
        if (isset($item['competition_index']) && is_numeric($item['competition_index'])) {
            return round(min(100, max(0, (float) $item['competition_index'])) / 100, 3);
        }

        return isset($item['competition']) && is_numeric($item['competition']) ? round((float) $item['competition'], 3) : null;
    }

    /** @param list<string> $chunk @return list<array<string,mixed>> */
    private function call(array $chunk, array $options): array
    {
        $body = [[
            'keywords' => $chunk,
            'location_code' => (int) ($options['location_code'] ?? config('keywords.location_code', 2704)),
            'language_code' => (string) ($options['language_code'] ?? config('keywords.language_code', 'vi')),
        ]];
        try {
            $res = Http::withBasicAuth($this->login, $this->password)->acceptJson()->timeout(60)
                ->post((string) config('keywords.dataforseo.endpoint'), $body);
        } catch (\Throwable $e) {
            throw new KeywordMetricsFailed('Không kết nối được DataForSEO: '.$this->mask($e->getMessage()));
        }
        if (! $res->successful()) {
            throw new KeywordMetricsFailed('DataForSEO trả lỗi HTTP '.$res->status().($res->status() === 401 ? ' (sai DATAFORSEO_LOGIN hoặc DATAFORSEO_PASSWORD).' : '.'));
        }
        $json = $res->json();
        $code = is_array($json) ? (int) ($json['status_code'] ?? 0) : 0;
        if ($code !== 20000) {
            throw new KeywordMetricsFailed('DataForSEO báo lỗi '.($code ?: '?').': '.$this->mask((string) ($json['status_message'] ?? 'phản hồi không hợp lệ')));
        }
        $task = $json['tasks'][0] ?? null;
        $tcode = (int) ($task['status_code'] ?? 0);
        if ($tcode !== 20000) {
            throw new KeywordMetricsFailed('DataForSEO báo lỗi tác vụ '.($tcode ?: '?').': '.$this->mask((string) ($task['status_message'] ?? 'không rõ')));
        }

        return array_values(array_filter((array) ($task['result'] ?? []), 'is_array'));
    }

    private function mask(string $s): string
    {
        foreach (array_filter([$this->login, $this->password, base64_encode($this->login.':'.$this->password)]) as $secret) {
            $s = str_replace($secret, '***', $s);
        }

        return mb_substr($s, 0, 200);
    }
}
