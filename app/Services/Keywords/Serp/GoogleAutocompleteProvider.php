<?php

namespace App\Services\Keywords\Serp;

use App\Contracts\SerpInsightsProvider;
use Illuminate\Support\Facades\Http;

/**
 * Gợi ý tự động của Google (cụm người ta thật sự gõ). Không cần khóa, không có số lượt tìm. Chỉ trả autocomplete; mọi trường khác rỗng.
 * Máy chủ cố định (config), không lấy URL từ nội dung ngoài nên không mở đường cho SSRF.
 */
final class GoogleAutocompleteProvider implements SerpInsightsProvider
{
    public function name(): string
    {
        return 'autocomplete';
    }

    public function insights(string $keyword, array $options = []): array
    {
        $keyword = trim(preg_replace('/\s+/u', ' ', $keyword) ?? '');
        $out = ['paa' => [], 'related' => [], 'autocomplete' => [], 'organic' => [], 'provider' => $this->name(), 'cost_vnd' => 0.0, 'fetched_at' => now()->toIso8601String()];
        if ($keyword === '') {
            return $out;
        }
        try {
            $res = Http::acceptJson()->timeout(15)->get((string) config('keywords.serp.autocomplete_endpoint'), [
                'client' => 'firefox', 'q' => mb_substr($keyword, 0, 200), 'hl' => (string) ($options['language_code'] ?? config('keywords.language_code', 'vi')),
            ]);
        } catch (\Throwable $e) {
            throw new SerpFailed('Không kết nối được gợi ý tự động: '.mb_substr($e->getMessage(), 0, 160));
        }
        if (! $res->successful()) {
            throw new SerpFailed('Gợi ý tự động trả lỗi HTTP '.$res->status().'.');
        }
        $json = json_decode((string) $res->body(), true);
        $list = is_array($json) && is_array($json[1] ?? null) ? $json[1] : [];
        foreach ($list as $s) {
            $t = trim((string) $s);
            if ($t !== '' && mb_strtolower($t) !== mb_strtolower($keyword)) {
                $out['autocomplete'][] = mb_substr($t, 0, 160);
            }
        }
        $out['autocomplete'] = array_values(array_unique($out['autocomplete']));

        return $out;
    }
}
