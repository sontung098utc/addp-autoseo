<?php

namespace App\Services\Search;

use App\Contracts\WebSearchProvider;
use App\Models\AiProvider;
use App\Services\Security\UrlSafetyService;
use Illuminate\Support\Facades\Http;

/**
 * Tìm web qua cầu nối Claude CLI (POST {bridge}/v1/search), không cần khóa dịch vụ tìm kiếm riêng. Địa chỉ và khóa lấy từ nhà cung cấp
 * "Claude CLI (cầu nối)" đã cài bằng agent:claude-setup (khóa đã mã hóa trong DB); địa chỉ phải qua danh sách cho phép. Kết quả chỉ là DỮ LIỆU.
 */
final class ClaudeBridgeSearchProvider implements WebSearchProvider
{
    public const PROVIDER_NAME = 'Claude CLI (cầu nối)';

    /** Có nhà cung cấp cầu nối đang bật không (không gọi mạng). */
    public static function configured(): bool
    {
        try {
            return AiProvider::query()->where('name', self::PROVIDER_NAME)->where('enabled', true)->exists();
        } catch (\Throwable) {
            return false; // chưa có bảng/DB (vd. công cụ chạy ngoài ứng dụng): coi như chưa cài
        }
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        $p = AiProvider::query()->where('name', self::PROVIDER_NAME)->where('enabled', true)->first();
        if ($query === '' || $p === null || (string) $p->api_key === '') {
            throw new WebSearchUnavailable('Chưa cài cầu nối Claude (chạy php artisan agent:claude-setup).');
        }
        $base = preg_replace('#/v1/?$#', '', rtrim((string) $p->base_url, '/'));
        try {
            app(UrlSafetyService::class)->validateAiBaseUrl($base.'/v1');
        } catch (\InvalidArgumentException $e) {
            throw new WebSearchUnavailable('Địa chỉ cầu nối chưa được phép: '.mb_substr($e->getMessage(), 0, 150));
        }
        $body = ['query' => mb_substr($query, 0, 400), 'max_results' => max(1, min(8, (int) ($options['max_results'] ?? 5)))];
        foreach (['include_domains', 'exclude_domains'] as $k) {
            $list = array_values(array_filter(array_map('strval', (array) ($options[$k] ?? []))));
            if ($list !== []) {
                $body[$k] = $list;
            }
        }
        try {
            $resp = Http::acceptJson()->withToken((string) $p->api_key)->timeout((int) config('search.bridge_timeout', 240))->connectTimeout(5)->post($base.'/v1/search', $body);
        } catch (\Throwable $e) {
            throw new WebSearchUnavailable('Không gọi được cầu nối tìm kiếm: '.mb_substr(str_replace((string) $p->api_key, '***', $e->getMessage()), 0, 150));
        }
        if (! $resp->successful()) {
            throw new WebSearchUnavailable('Cầu nối tìm kiếm trả mã '.$resp->status().($resp->status() === 429 ? ' (hết hạn mức Claude)' : '').'.');
        }
        $out = [];
        foreach ((array) $resp->json('results') as $r) {
            $url = trim((string) ($r['url'] ?? ''));
            if (! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $out[] = [
                'title' => mb_substr(trim((string) ($r['title'] ?? '')) ?: $url, 0, 200),
                'url' => mb_substr($url, 0, 2048),
                'snippet' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($r['content'] ?? '')) ?? ''), 0, 1200),
                'score' => 0.5,
                'published_at' => null,
            ];
        }

        return ['results' => $out, 'cost_vnd' => 0.0, 'provider' => 'claude_bridge'];
    }
}
