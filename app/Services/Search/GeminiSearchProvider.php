<?php

namespace App\Services\Search;

use App\Contracts\WebSearchProvider;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Http;

/**
 * Tìm web bằng chính Gemini (công cụ Google Search grounding), không cần khóa dịch vụ tìm kiếm riêng: dùng lại khóa Gemini đã lưu (mã hóa) ở
 * nhà cung cấp AI loại gemini đang bật. Chỉ lấy DANH SÁCH NGUỒN do Google trả về (groundingChunks), không lấy lời văn của model làm tài liệu.
 *
 * Gemini trả đường dẫn chuyển hướng của Google (vertexaisearch…/grounding-api-redirect/…): ta chỉ đọc tiêu đề Location của nó (không tải trang đích)
 * để lấy địa chỉ thật. Bộ lọc tên miền áp SAU khi nhận kết quả vì công cụ này không lọc theo tên miền được; chỉ nhận http(s).
 */
final class GeminiSearchProvider implements WebSearchProvider
{
    public const NAME = 'gemini';

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/';

    /** Có nhà cung cấp Gemini đang bật và có khóa không (không gọi mạng). */
    public static function configured(): bool
    {
        return self::credentials() !== null;
    }

    /** @return array{0: string, 1: ?array<string,mixed>}|null [khóa, bảng giá] */
    private static function credentials(): ?array
    {
        try {
            foreach (AiProvider::query()->where('kind', 'gemini')->where('enabled', true)->orderBy('id')->get() as $p) {
                if ($p->hasKey()) {
                    return [(string) $p->api_key, $p->pricing];
                }
            }
        } catch (\Throwable) {
            // chưa có bảng/DB: coi như chưa cài
        }

        return null;
    }

    public function search(string $query, array $options = []): array
    {
        $query = trim($query);
        $cred = self::credentials();
        if ($query === '' || $cred === null) {
            throw new WebSearchUnavailable('Chưa có từ khóa tìm hoặc chưa có nhà cung cấp Gemini đang bật kèm khóa.');
        }
        $max = max(1, min(10, (int) ($options['max_results'] ?? config('search.max_results', 10))));
        $include = $this->domains($options['include_domains'] ?? []);
        $exclude = $this->domains($options['exclude_domains'] ?? []);
        $hint = $include !== [] ? ' Chỉ ưu tiên các nguồn thuộc các trang: '.implode(', ', array_slice($include, 0, 12)).'.' : '';
        $prompt = 'Tìm trên web các tài liệu uy tín nhất về: '.mb_substr($query, 0, 400).'.'.$hint.' Nêu ngắn gọn từng ý chính kèm nguồn.';
        try {
            $resp = Http::baseUrl(self::BASE_URL)->acceptJson()->timeout((int) config('search.gemini.timeout', 60))->connectTimeout(10)
                ->withHeaders(['x-goog-api-key' => $cred[0]])
                ->post('models/'.(string) config('search.gemini.model', 'gemini-3.6-flash').':generateContent', [
                    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                    'tools' => [['google_search' => (object) []]],
                    'generationConfig' => ['temperature' => 0.1],
                ]);
        } catch (\Throwable $e) {
            throw new WebSearchUnavailable('Không gọi được Gemini để tìm web: '.$this->mask($e->getMessage(), $cred[0]), (int) $e->getCode());
        }
        if (! $resp->successful()) {
            throw new WebSearchUnavailable('Gemini trả mã '.$resp->status().' khi tìm web'.($resp->status() === 429 ? ' (hết hạn mức)' : '').'.', $resp->status());
        }
        $cand = $resp->json('candidates.0') ?? [];
        $chunks = $cand['groundingMetadata']['groundingChunks'] ?? [];
        $snips = $this->snippets($cand['groundingMetadata']['groundingSupports'] ?? []);

        $out = [];
        $seen = [];
        foreach (is_array($chunks) ? $chunks : [] as $i => $c) {
            $uri = trim((string) ($c['web']['uri'] ?? ''));
            if ($uri === '' || ! preg_match('#^https?://#i', $uri)) {
                continue;
            }
            $url = $this->resolve($uri);
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '' || isset($seen[$url]) || ! $this->allowed($host, $include, $exclude)) {
                continue;
            }
            $seen[$url] = true;
            $title = trim((string) ($c['web']['title'] ?? ''));
            $out[] = [
                'title' => mb_substr($title !== '' ? $title : $host, 0, 200),
                'url' => mb_substr($url, 0, 2048),
                'snippet' => mb_substr($snips[$i] ?? '', 0, 400),
                'score' => 0.0,
                'published_at' => null,
            ];
            if (count($out) >= $max) {
                break;
            }
        }

        return ['results' => $out, 'cost_vnd' => (float) config('search.cost_per_search_vnd', 200), 'provider' => self::NAME];
    }

    /** Đoạn văn Gemini viết dựa trên từng nguồn (chỉ để người đọc thấy nguồn nói gì; không phải nội dung trang). @return array<int,string> */
    private function snippets(mixed $supports): array
    {
        $by = [];
        foreach (is_array($supports) ? $supports : [] as $s) {
            $text = trim((string) ($s['segment']['text'] ?? ''));
            foreach ((array) ($s['groundingChunkIndices'] ?? []) as $idx) {
                if ($text !== '' && mb_strlen($by[(int) $idx] ?? '') < 360) {
                    $by[(int) $idx] = trim(($by[(int) $idx] ?? '').' '.$text);
                }
            }
        }

        return array_map(fn ($t) => trim(preg_replace('/\s+/u', ' ', $t) ?? ''), $by);
    }

    /** Địa chỉ thật từ đường dẫn chuyển hướng của Google (một bước, không theo liên kết, không tải trang đích). */
    private function resolve(string $uri): string
    {
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));
        if ($host !== 'vertexaisearch.cloud.google.com') {
            return $uri;
        }
        try {
            $r = Http::withoutRedirecting()->timeout(8)->connectTimeout(5)->get($uri);
            $loc = trim((string) $r->header('Location'));
            if ($loc !== '' && preg_match('#^https?://#i', $loc)) {
                return $loc;
            }
        } catch (\Throwable) {
            // giữ đường dẫn chuyển hướng: vẫn mở được, chỉ không lọc theo tên miền chính xác
        }

        return $uri;
    }

    /** @param  list<string>  $include @param  list<string>  $exclude */
    private function allowed(string $host, array $include, array $exclude): bool
    {
        $match = fn (string $d) => $host === $d || str_ends_with($host, '.'.$d);
        foreach ($exclude as $d) {
            if ($match($d)) {
                return false;
            }
        }
        if ($include === []) {
            return true;
        }
        foreach ($include as $d) {
            if ($match($d)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function domains(mixed $list): array
    {
        return array_values(array_filter(array_map(fn ($d) => preg_replace('#^www\.#', '', strtolower(trim((string) $d))), (array) $list)));
    }

    private function mask(string $m, string $key): string
    {
        return mb_substr(str_replace($key, '***', $m), 0, 200);
    }
}
