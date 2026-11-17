<?php

namespace App\Services\Search;

use App\Contracts\WebSearchProvider;
use App\Models\AiProvider;
use App\Services\Security\UrlSafetyService;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Tìm web qua cầu nối Claude CLI (POST {bridge}/v1/search), không cần khóa dịch vụ tìm kiếm riêng. Địa chỉ và khóa lấy từ nhà cung cấp
 * "Claude CLI (cầu nối)" đã cài bằng agent:claude-setup (khóa đã mã hóa trong DB); địa chỉ phải qua danh sách cho phép. Kết quả chỉ là DỮ LIỆU.
 *
 * Mỗi lượt tìm là một phiên Claude riêng (mất 1–4 phút), nên có hai cách giảm thời gian chờ, không đổi kết quả:
 * (1) ghi nhớ kết quả trong phạm vi MỘT phiên chạy bài: cùng truy vấn + cùng bộ lọc tên miền thì không tìm lại (kể cả kết quả rỗng, trừ khi yêu cầu `fresh`);
 * (2) prefetch(): gửi nhiều truy vấn độc lập CÙNG LÚC (Http::pool) rồi để luồng tuần tự cũ đọc từ bộ nhớ. Lỗi ở prefetch bị bỏ qua: lần tìm tuần tự sau đó tự gọi lại và báo lỗi đúng chỗ.
 */
class ClaudeBridgeSearchProvider implements WebSearchProvider
{
    public const PROVIDER_NAME = 'Claude CLI (cầu nối)';

    /** Tên nhà cung cấp AI chứa địa chỉ và khóa của cầu nối tìm kiếm (lớp con đổi sang cầu nối khác, vd. Codex). */
    protected function providerName(): string
    {
        return self::PROVIDER_NAME;
    }

    /** Kết quả đã nhớ giữ tối đa bao nhiêu giây (phạm vi một phiên chạy bài; quá hạn thì tìm lại). */
    private const MEMO_TTL = 900;

    /** @var array<string, array{at: int, results: list<array<string,mixed>>}> */
    private static array $memo = [];

    /** Xóa bộ nhớ (đầu mỗi phiên chạy bài, và trong test). */
    public static function forget(): void
    {
        self::$memo = [];
    }

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
        [$base, $token] = $this->endpoint($query);
        $body = $this->body($query, $options);
        $key = $this->key($body);
        if (empty($options['fresh']) && ($hit = $this->recall($key)) !== null) {
            return ['results' => $hit, 'cost_vnd' => 0.0, 'provider' => 'claude_bridge'];
        }
        try {
            $resp = Http::acceptJson()->withToken($token)->timeout((int) config('search.bridge_timeout', 240))->connectTimeout(5)->post($base.'/v1/search', $body);
        } catch (\Throwable $e) {
            throw new WebSearchUnavailable('Không gọi được cầu nối tìm kiếm: '.mb_substr(str_replace($token, '***', $e->getMessage()), 0, 150));
        }
        if (! $resp->successful()) {
            if ($resp->status() === 429) { // hết hạn mức của Claude: mã 429 để bộ điều phối DỪNG phiên thay vì lặng lẽ viết thiếu nguồn
                throw new WebSearchUnavailable('Claude đã hết hạn mức: '.mb_substr(trim((string) $resp->json('error.message')), 0, 160), 429);
            }
            throw new WebSearchUnavailable('Cầu nối tìm kiếm trả mã '.$resp->status().'.');
        }
        $out = self::parse((array) $resp->json('results'));
        self::$memo[$key] = ['at' => time(), 'results' => $out];

        return ['results' => $out, 'cost_vnd' => 0.0, 'provider' => 'claude_bridge'];
    }

    /**
     * Gửi nhiều truy vấn độc lập cùng lúc và nhớ kết quả thành công. Không ném lỗi (truy vấn lỗi sẽ được search() tuần tự gọi lại sau).
     *
     * @param  list<array{0: string, 1: array<string,mixed>}>  $calls  mỗi phần tử: [truy vấn, tùy chọn như search()]
     */
    public function prefetch(array $calls): void
    {
        try {
            $todo = [];
            foreach ($calls as [$q, $opts]) {
                $q = trim((string) $q);
                if ($q === '') {
                    continue;
                }
                $body = $this->body($q, (array) $opts);
                $key = $this->key($body);
                if ($this->recall($key) === null && ! isset($todo[$key])) {
                    $todo[$key] = $body;
                }
            }
            if (count($todo) < 2) {
                return; // một truy vấn thì không có gì để song song hóa
            }
            [$base, $token] = $this->endpoint('x');
            $timeout = (int) config('search.bridge_timeout', 240);
            $keys = array_keys($todo);
            $responses = Http::pool(function (Pool $pool) use ($todo, $keys, $base, $token, $timeout) {
                $reqs = [];
                foreach ($keys as $i => $key) {
                    $reqs[] = $pool->as((string) $i)->acceptJson()->withToken($token)->timeout($timeout)->connectTimeout(5)->post($base.'/v1/search', $todo[$key]);
                }

                return $reqs;
            });
            foreach ($keys as $i => $key) {
                $r = $responses[(string) $i] ?? null;
                if ($r instanceof \Illuminate\Http\Client\Response && $r->successful()) {
                    self::$memo[$key] = ['at' => time(), 'results' => self::parse((array) $r->json('results'))];
                }
            }
        } catch (\Throwable) {
            // bỏ qua: luồng tuần tự sẽ tự tìm và báo lỗi
        }
    }

    /** @return array{0: string, 1: string} [địa chỉ gốc cầu nối, khóa] @throws WebSearchUnavailable */
    private function endpoint(string $query): array
    {
        $p = AiProvider::query()->where('name', $this->providerName())->where('enabled', true)->first();
        if ($query === '' || $p === null || (string) $p->api_key === '') {
            throw new WebSearchUnavailable('Chưa cài cầu nối tìm kiếm "'.$this->providerName().'" (Claude: chạy php artisan agent:claude-setup).');
        }
        $base = preg_replace('#/v1/?$#', '', rtrim((string) $p->base_url, '/'));
        try {
            app(UrlSafetyService::class)->validateAiBaseUrl($base.'/v1');
        } catch (\InvalidArgumentException $e) {
            throw new WebSearchUnavailable('Địa chỉ cầu nối chưa được phép: '.mb_substr($e->getMessage(), 0, 150));
        }

        return [$base, (string) $p->api_key];
    }

    /** @return array<string,mixed> */
    private function body(string $query, array $options): array
    {
        $body = ['query' => mb_substr($query, 0, 400), 'max_results' => max(1, min(8, (int) ($options['max_results'] ?? 5)))];
        foreach (['include_domains', 'exclude_domains'] as $k) {
            $list = array_values(array_filter(array_map('strval', (array) ($options[$k] ?? []))));
            if ($list !== []) {
                $body[$k] = $list;
            }
        }

        return $body;
    }

    /** Khóa nhớ: truy vấn + bộ lọc tên miền (không tính max_results vì cầu nối đã chặn trên ở 8). */
    private function key(array $body): string
    {
        unset($body['max_results']);

        return sha1(json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    /** @return ?list<array<string,mixed>> */
    private function recall(string $key): ?array
    {
        $m = self::$memo[$key] ?? null;
        if ($m === null || time() - $m['at'] > self::MEMO_TTL) {
            return null;
        }

        return $m['results'];
    }

    /** @return list<array<string,mixed>> */
    private static function parse(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
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

        return $out;
    }
}
