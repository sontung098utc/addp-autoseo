<?php

namespace App\Services\Keywords\Serp;

use App\Contracts\SerpInsightsProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Gộp mọi nguồn SERP đang có cho một từ khóa. Nguồn nào lỗi thì ghi vào `errors` và bỏ qua; không nguồn nào chạy được → SerpUnavailable.
 * Mỗi mục gắn nhãn nguồn. Có cache theo ngày để không tốn chi phí lặp lại.
 */
final class SerpInsights
{
    public const HOWTO = 'Đặt DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD trong .env để lấy câu hỏi liên quan và top 10 thật. Gợi ý tự động của Google không cần khóa (SERP_AUTOCOMPLETE).';

    /** @param list<SerpInsightsProvider> $providers */
    public function __construct(private readonly array $providers) {}

    public static function fromConfig(): self
    {
        $list = [];
        $login = (string) config('keywords.dataforseo.login');
        $pass = (string) config('keywords.dataforseo.password');
        if ($login !== '' && $pass !== '') {
            $list[] = new DataForSeoSerpProvider($login, $pass);
        }
        if (config('keywords.serp.autocomplete', true)) {
            $list[] = new GoogleAutocompleteProvider;
        }

        return new self($list);
    }

    public function available(): bool
    {
        return $this->providers !== [];
    }

    /** @return list<string> tên các nguồn đang bật */
    public function names(): array
    {
        return array_map(fn (SerpInsightsProvider $p) => $p->name(), $this->providers);
    }

    /**
     * @return array{paa: list<string>, related: list<string>, autocomplete: list<string>, organic: list<array{rank:int,url:string,title:string,domain:string}>, providers: list<string>, errors: list<string>, cost_vnd: float, cached: bool, fetched_at: string}
     *
     * @throws SerpUnavailable
     */
    public function fetch(string $keyword, array $options = []): array
    {
        if ($this->providers === []) {
            throw new SerpUnavailable('Chưa có nguồn dữ liệu SERP. '.self::HOWTO);
        }
        $key = 'serp:'.md5(mb_strtolower(trim($keyword)).'|'.implode(',', $this->names()).'|'.($options['language_code'] ?? '').'|'.($options['location_code'] ?? ''));
        if (is_array($hit = Cache::get($key))) {
            return ['cached' => true, 'cost_vnd' => 0.0] + $hit;
        }
        $out = ['paa' => [], 'related' => [], 'autocomplete' => [], 'organic' => [], 'providers' => [], 'errors' => [], 'cost_vnd' => 0.0, 'cached' => false, 'fetched_at' => now()->toIso8601String()];
        foreach ($this->providers as $p) {
            try {
                $r = $p->insights($keyword, $options);
            } catch (SerpFailed $e) {
                $out['errors'][] = $p->name().': '.$e->getMessage();

                continue;
            }
            $out['providers'][] = $p->name();
            $out['cost_vnd'] += (float) $r['cost_vnd'];
            foreach (['paa', 'related', 'autocomplete', 'organic'] as $k) {
                $out[$k] = array_merge($out[$k], $r[$k]);
            }
        }
        if ($out['providers'] === []) {
            throw new SerpUnavailable('Mọi nguồn SERP đều lỗi: '.implode(' | ', $out['errors']));
        }
        foreach (['paa', 'related', 'autocomplete'] as $k) {
            $out[$k] = array_values(array_unique($out[$k]));
        }
        Cache::put($key, array_diff_key($out, ['cached' => 1, 'cost_vnd' => 1]), now()->addDays((int) config('keywords.serp.cache_days', 7)));

        return $out;
    }
}
