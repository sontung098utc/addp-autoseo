<?php

namespace App\Services\Agent\Context;

use App\Models\Article;
use App\Models\CrawledUrl;
use App\Models\Website;

/**
 * AG-S25: gợi ý tối đa 5 liên kết nội bộ từ các trang ĐÃ QUÉT của chính website. Tất định, không AI, không bịa URL.
 * Nội dung trang đã quét (title/H1) là DỮ LIỆU: chỉ dùng để chấm điểm và làm anchor gợi ý (đã làm phẳng, cắt ngắn), không là chỉ dẫn.
 * Chỉ nhận trang 200, cùng host với base_url (chống SSRF/open redirect: không bao giờ gợi ý domain khác), có tiêu đề, không dính lỗi nặng.
 * Trọng số là heuristic nội bộ, chưa có ngưỡng chính thức của Google.
 */
final class InternalLinkSuggester
{
    public const LIMIT = 5;

    public const MIN_RELEVANCE = 1.0;

    /** Mã vấn đề của bộ quét báo trang không nên được dẫn tới. */
    private const SEVERE = '/^(http_error_|noindex|canonical_(mismatch|other)|redirect|blocked|robots)/i';

    /**
     * @param  list<mixed>  $outline
     * @return list<array{url:string,anchor_goi_y:string,title:string,diem:float,ly_do:string}>
     */
    public function suggest(Article $article, array $outline = []): array
    {
        $site = $article->website;
        $kw = TextMatch::tokens((string) $article->primary_keyword);
        if (! $site || $kw === []) {
            return [];
        }
        $host = self::host((string) $site->base_url);
        if ($host === null) {
            return [];
        }
        $ol = array_values(array_diff(TextMatch::outlineTokens($outline), $kw));
        $selfSlugs = $article->versions()->pluck('slug')->filter()->map(fn ($s) => strtolower((string) $s))->all();

        $rows = CrawledUrl::query()->where('website_id', $site->id)->where('http_status', 200)->whereNotNull('title')->where('title', '!=', '')->get();
        $out = [];
        foreach ($rows as $r) {
            $url = self::normalizeOwn((string) $r->url, $host);
            if ($url === null || self::severe($r) || $this->isSelf($url, $selfSlugs)) {
                continue;
            }
            $title = $this->flat((string) $r->title, 120);
            $h1 = $this->flat((string) $r->h1, 120);
            $titleTok = TextMatch::tokens($title);
            $h1Tok = TextMatch::tokens($h1);
            $headTok = TextMatch::tokens(implode(' ', array_map(fn ($h) => is_scalar($h) ? (string) $h : (is_array($h) ? (string) ($h['text'] ?? '') : ''), array_slice((array) $r->headings_summary, 0, 12))));
            $urlTok = TextMatch::tokens(str_replace(['-', '_', '/'], ' ', (string) parse_url($url, PHP_URL_PATH)));

            $rel = 3.0 * TextMatch::coverage($kw, $titleTok) + 3.0 * TextMatch::coverage($kw, $h1Tok) + 1.0 * TextMatch::coverage($kw, $headTok) + 1.0 * TextMatch::coverage($kw, $urlTok);
            $rel += 0.3 * min(1.0, count(array_intersect($ol, array_merge($titleTok, $h1Tok, $headTok))) / 5);
            if ($rel < self::MIN_RELEVANCE) {
                continue;
            }
            $score = $rel;
            $why = ['khớp từ khóa với tiêu đề/H1/heading'];
            $in = (int) $r->internal_links_count;
            if ($in <= 2) {
                $score += 1.0;
                $why[] = 'còn ít liên kết nội bộ trỏ tới';
            }
            $out[] = ['url' => $url, 'anchor_goi_y' => $h1 !== '' ? $h1 : $title, 'title' => $title, 'diem' => round($score, 1), 'ly_do' => implode('; ', $why)];
        }
        usort($out, fn ($a, $b) => [$b['diem'], $a['url']] <=> [$a['diem'], $b['url']]);

        return array_slice($out, 0, self::LIMIT);
    }

    /**
     * Tập URL hợp lệ của website (trang 200 đã quét, cùng host) dùng cho LINK01. Khóa đã chuẩn hóa.
     *
     * @return array<string,true>
     */
    public static function allowedSet(Website $site): array
    {
        $host = self::host((string) $site->base_url);
        if ($host === null) {
            return [];
        }
        $set = [];
        foreach (CrawledUrl::query()->where('website_id', $site->id)->where('http_status', 200)->pluck('url') as $u) {
            if (($n = self::normalizeOwn((string) $u, $host)) !== null) {
                $set[self::key($n)] = true;
            }
        }
        $home = self::normalizeOwn((string) $site->base_url, $host);
        if ($home !== null) {
            $set[self::key($home)] = true;
        }

        return $set;
    }

    public static function host(string $url): ?string
    {
        $h = parse_url(trim($url), PHP_URL_HOST);

        return is_string($h) && $h !== '' ? preg_replace('/^www\./', '', strtolower($h)) : null;
    }

    /** URL http(s) cùng host (bỏ www) → dạng chuẩn (bỏ fragment); khác host hoặc sai scheme → null. */
    public static function normalizeOwn(string $url, string $host): ?string
    {
        $p = parse_url(trim($url));
        if (! $p || ! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || self::host($url) !== $host || isset($p['user']) || isset($p['pass'])) {
            return null;
        }
        $path = $p['path'] ?? '/';

        return strtolower($p['scheme']).'://'.strtolower($p['host']).(isset($p['port']) ? ':'.$p['port'] : '').$path.(isset($p['query']) ? '?'.$p['query'] : '');
    }

    /** Khóa so sánh: bỏ scheme/www/dấu / cuối, chữ thường phần host. */
    public static function key(string $normalized): string
    {
        $p = parse_url($normalized);

        return preg_replace('/^www\./', '', strtolower($p['host'] ?? '')).rtrim($p['path'] ?? '/', '/').(isset($p['query']) ? '?'.$p['query'] : '');
    }

    private static function severe(CrawledUrl $r): bool
    {
        foreach ((array) $r->issues as $i) {
            if (is_string($i) && preg_match(self::SEVERE, $i)) {
                return true;
            }
        }

        return false;
    }

    private function isSelf(string $url, array $slugs): bool
    {
        if ($slugs === []) {
            return false;
        }
        $last = strtolower(basename(rtrim((string) parse_url($url, PHP_URL_PATH), '/')));

        return $last !== '' && in_array($last, $slugs, true);
    }

    private function flat(string $s, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($s)) ?? ''), 0, $max);
    }
}
