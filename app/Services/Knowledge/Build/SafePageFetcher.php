<?php

namespace App\Services\Knowledge\Build;

use App\Models\Website;
use App\Services\Security\UrlSafetyService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * G2: tải MỘT trang của chính website, an toàn. fetch() không bao giờ tải tên miền ngoài.
 * Chế độ NGOÀI (fetchExternal, dùng khi người dùng chọn nguồn tìm được trên web): cùng mọi chốt an toàn dưới đây, chỉ bỏ ràng buộc tên miền;
 * mỗi bước chuyển hướng vẫn qua UrlSafetyService::validateUrl (chặn IP nội bộ) và robots.txt của chính tên miền đó; không gửi cookie hay khóa.
 * - Mọi URL (kể cả mỗi bước chuyển hướng) qua UrlSafetyService::validateUrl; tự chuyển hướng của HTTP client bị TẮT,
 *   tự theo tối đa 3 lần và kiểm lại từng đích; đích ngoài tên miền cho phép thì dừng.
 * - Tối đa 2 MB, chờ 10 giây, chỉ nhận text/html và text/plain, User-Agent rõ ràng, tôn trọng robots.txt của chính site,
 *   giãn cách giữa các yêu cầu (mặc định 1 giây).
 * Chưa làm: ghim địa chỉ IP đã kiểm khi gửi yêu cầu (còn khe hở DNS đổi giữa lúc kiểm và lúc tải).
 * Trả {ok:true, url, final_url, http_status, html, fetched_at} hoặc {ok:false, url, error, message, http_status?}.
 * Mã lỗi: blocked, off_domain, too_large, not_html, robots, http_error, timeout.
 */
final class SafePageFetcher
{
    public const USER_AGENT = 'ADDP-AutoSEO-KnowledgeBot/1.0 (+đọc trang công khai của chính website để dựng kho kiến thức)';

    public const MAX_BYTES = 2 * 1024 * 1024;

    public const TIMEOUT = 10;

    public const MAX_REDIRECTS = 3;

    private float $lastRequestAt = 0.0;

    /** @var array<string,?list<array{allow:bool,path:string}>> host => luật (null = không có robots.txt) */
    private array $robots = [];

    public function __construct(private ?UrlSafetyService $safety = null, private ?int $delayMs = null)
    {
        $this->safety ??= new UrlSafetyService;
        $this->delayMs ??= max(0, (int) config('knowledge.build_delay_ms', 1000));
    }

    /** @return list<string> tên miền cho phép: host của base_url bỏ "www." (kèm mọi tên miền con) */
    public static function allowedHostsFor(Website|string $site): array
    {
        $base = $site instanceof Website ? (string) $site->base_url : $site;
        $host = strtolower((string) (parse_url(str_contains($base, '://') ? $base : 'https://'.$base, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return $host === '' ? [] : [$host];
    }

    public static function hostAllowed(string $host, array $allowed): bool
    {
        $host = strtolower($host);
        foreach ($allowed as $a) {
            $a = strtolower(trim($a));
            if ($a !== '' && ($host === $a || $host === 'www.'.$a || str_ends_with($host, '.'.$a))) {
                return true;
            }
        }

        return false;
    }

    /** Tải một trang ở tên miền BẤT KỲ (đã chọn là nguồn ngoài), vẫn mọi chốt an toàn trừ ràng buộc tên miền. @return array<string,mixed> */
    public function fetchExternal(string $url): array
    {
        return $this->fetch($url, null);
    }

    /** @param ?list<string> $allowedHosts null = chế độ ngoài (không ràng buộc tên miền) @return array<string,mixed> */
    public function fetch(string $url, ?array $allowedHosts): array
    {
        $current = trim($url);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if ($e = $this->gate($current, $allowedHosts, $url)) {
                return $e;
            }
            if ($e = $this->robotsGate($current, $allowedHosts, $url)) {
                return $e;
            }
            $r = $this->get($current);
            if (isset($r['error'])) {
                return $r + ['url' => $url];
            }
            /** @var Response $resp */
            $resp = $r['response'];
            $status = $resp->status();
            if ($status >= 300 && $status < 400) {
                $loc = trim((string) $resp->header('Location'));
                if ($loc === '') {
                    return $this->err($url, 'http_error', 'Chuyển hướng không có đích.', $status);
                }
                $current = $this->resolve($current, $loc);

                continue;
            }
            if ($status < 200 || $status >= 300) {
                return $this->err($url, 'http_error', "Máy chủ trả mã {$status}.", $status);
            }
            $type = strtolower(trim(explode(';', (string) $resp->header('Content-Type'))[0]));
            if (! in_array($type, ['text/html', 'text/plain'], true)) {
                return $this->err($url, 'not_html', 'Trang không phải HTML hoặc văn bản ('.($type !== '' ? $type : 'không rõ loại').').', $status);
            }
            $body = (string) $resp->body();
            if (strlen($body) > self::MAX_BYTES) {
                return $this->err($url, 'too_large', 'Trang lớn hơn 2 MB.', $status);
            }

            return ['ok' => true, 'url' => $url, 'final_url' => $current, 'http_status' => $status, 'html' => $body, 'fetched_at' => now()->toIso8601String()];
        }

        return $this->err($url, 'http_error', 'Quá '.self::MAX_REDIRECTS.' lần chuyển hướng.');
    }

    /** Kiểm một URL: giao thức/IP nội bộ (blocked) rồi tên miền (off_domain). @return ?array<string,mixed> */
    private function gate(string $u, ?array $allowed, string $origin): ?array
    {
        try {
            $this->safety->validateUrl($u);
        } catch (\InvalidArgumentException $e) {
            return $this->err($origin, 'blocked', $e->getMessage());
        }
        $host = (string) parse_url($u, PHP_URL_HOST);
        if ($allowed !== null && ! self::hostAllowed($host, $allowed)) {
            return $this->err($origin, 'off_domain', "Tên miền '{$host}' không thuộc website nên không tải.");
        }

        return null;
    }

    /** @return array{response: Response}|array<string,mixed> */
    private function get(string $url, bool $throttle = true): array
    {
        if ($throttle) {
            $this->throttle();
        }
        try {
            $resp = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html,text/plain;q=0.9'])
                ->withoutRedirecting()
                ->timeout(self::TIMEOUT)->connectTimeout(5)
                ->withOptions([
                    'progress' => function ($total, $now) {
                        if ($now > self::MAX_BYTES) {
                            throw new \RuntimeException('too_large');
                        }
                    },
                ])
                ->get($url);
        } catch (\Throwable $e) {
            $m = strtolower($e->getMessage());
            if (str_contains($m, 'too_large')) {
                return $this->err($url, 'too_large', 'Trang lớn hơn 2 MB.');
            }
            if ($e instanceof ConnectionException && (str_contains($m, 'timed out') || str_contains($m, 'error 28') || str_contains($m, 'timeout'))) {
                return $this->err($url, 'timeout', 'Hết thời gian chờ ('.self::TIMEOUT.' giây).');
            }

            return $this->err($url, 'http_error', 'Không tải được trang.');
        }
        $len = (int) $resp->header('Content-Length');
        if ($len > self::MAX_BYTES) {
            return $this->err($url, 'too_large', 'Trang lớn hơn 2 MB.', $resp->status());
        }

        return ['response' => $resp];
    }

    private function throttle(): void
    {
        if ($this->delayMs > 0 && $this->lastRequestAt > 0) {
            $wait = $this->delayMs / 1000 - (microtime(true) - $this->lastRequestAt);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }
        $this->lastRequestAt = microtime(true);
    }

    /** @return ?array<string,mixed> */
    private function robotsGate(string $url, ?array $allowed, string $origin): ?array
    {
        $p = parse_url($url);
        $host = strtolower((string) ($p['host'] ?? ''));
        if (! array_key_exists($host, $this->robots)) {
            $this->robots[$host] = null;
            $robotsUrl = ($p['scheme'] ?? 'https').'://'.$host.(isset($p['port']) ? ':'.$p['port'] : '').'/robots.txt';
            if ($this->gate($robotsUrl, $allowed, $robotsUrl) === null) {
                $r = $this->get($robotsUrl);
                if (isset($r['response']) && $r['response']->status() >= 200 && $r['response']->status() < 300) {
                    $this->robots[$host] = self::parseRobots(substr((string) $r['response']->body(), 0, 200000));
                }
            }
        }
        $rules = $this->robots[$host];
        if ($rules !== null && ! self::robotsAllows($rules, ($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : ''))) {
            return $this->err($origin, 'robots', 'robots.txt của website không cho đọc trang này.');
        }

        return null;
    }

    /** Luật của nhóm riêng cho bot này nếu có, nếu không thì nhóm "*". @return list<array{allow:bool,path:string}> */
    public static function parseRobots(string $txt): array
    {
        $groups = [];
        $agents = [];
        $inRules = false;
        foreach (preg_split('/\R/u', $txt) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $k = strtolower($k);
            if ($k === 'user-agent') {
                if ($inRules) {
                    $agents = [];
                    $inRules = false;
                }
                $agents[] = strtolower($v);
                $groups += array_fill_keys([strtolower($v)], []);
            } elseif (in_array($k, ['allow', 'disallow'], true)) {
                $inRules = true;
                foreach ($agents as $a) {
                    if ($v !== '') {
                        $groups[$a][] = ['allow' => $k === 'allow', 'path' => $v];
                    }
                }
            }
        }
        foreach ($groups as $agent => $rules) {
            if ($agent !== '*' && $agent !== '' && str_contains(strtolower(self::USER_AGENT), $agent)) {
                return $rules;
            }
        }

        return $groups['*'] ?? [];
    }

    /** @param list<array{allow:bool,path:string}> $rules luật dài nhất thắng; ngang nhau thì Allow thắng */
    public static function robotsAllows(array $rules, string $path): bool
    {
        $best = null;
        foreach ($rules as $r) {
            $re = '#^'.str_replace(['\*', '\$'], ['.*', '$'], preg_quote($r['path'], '#')).'#';
            if (preg_match($re, $path)) {
                $len = strlen($r['path']);
                if ($best === null || $len > $best['len'] || ($len === $best['len'] && $r['allow'])) {
                    $best = ['len' => $len, 'allow' => $r['allow']];
                }
            }
        }

        return $best === null || $best['allow'];
    }

    private function resolve(string $base, string $loc): string
    {
        if (preg_match('#^https?://#i', $loc)) {
            return $loc;
        }
        $p = parse_url($base);
        $root = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($loc, '//')) {
            return ($p['scheme'] ?? 'https').':'.$loc;
        }
        if (str_starts_with($loc, '/')) {
            return $root.$loc;
        }
        $dir = preg_replace('#[^/]*$#', '', $p['path'] ?? '/') ?: '/';

        return $root.$dir.$loc;
    }

    /** @return array<string,mixed> */
    private function err(string $url, string $code, string $message, ?int $status = null): array
    {
        return ['ok' => false, 'url' => $url, 'error' => $code, 'message' => $message] + ($status !== null ? ['http_status' => $status] : []);
    }
}
