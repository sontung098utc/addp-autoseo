<?php

namespace App\Services\Content;

use Illuminate\Support\Str;

/**
 * Gắn LIÊN KẾT cho các nguồn mà AI nêu tên trong bài (sources_cited): so khớp tên với các nguồn đã biết có địa chỉ thật
 * (tài liệu web đã đọc, nguồn trong kho, mục Nguồn tham khảo của bài). Không bịa liên kết: tên nào không khớp thì để url = null
 * để người duyệt thấy rõ "AI nêu tên nhưng chưa có liên kết xác thực".
 */
final class CitedSourceLinks
{
    /**
     * @param  list<string>  $cited
     * @param  list<array{title?: string, url: string, domain?: string}>  $candidates
     * @return list<array{name: string, url: ?string, matched: ?string}>
     */
    public static function resolve(array $cited, array $candidates): array
    {
        $out = [];
        foreach ($cited as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $best = null;
            $bestScore = 0.0;
            foreach ($candidates as $c) {
                $url = (string) ($c['url'] ?? '');
                if (! preg_match('#^https?://#i', $url)) {
                    continue;
                }
                $score = self::score($name, trim((string) ($c['title'] ?? '').' '.(string) ($c['domain'] ?? parse_url($url, PHP_URL_HOST) ?? '')));
                if ($score > $bestScore) {
                    [$best, $bestScore] = [$c, $score];
                }
            }
            $out[] = ['name' => $name, 'url' => $bestScore >= 0.6 ? (string) $best['url'] : null, 'matched' => $bestScore >= 0.6 ? (string) ($best['title'] ?? $best['url']) : null];
        }

        return $out;
    }

    /** Từ chung chung không dùng để so khớp tên nguồn. */
    private const STOP = ['huong', 'dan', 'cua', 'cac', 'nhung', 'mot', 'cho', 'theo', 'tai', 'lieu', 'nguon', 'trang', 'bai', 'viet', 'the', 'and', 'for', 'guide', 'page', 'www', 'com', 'org', 'vn', 'gov'];

    /** Tỷ lệ từ đặc trưng của tên nguồn xuất hiện trong chuỗi ứng viên (tập từ; từ dài từ 5 ký tự còn khớp theo chuỗi con để bắt tên miền dính liền), cộng điểm nếu tên chứa hẳn tên miền. */
    private static function score(string $name, string $candidate): float
    {
        $norm = fn (string $s) => Str::ascii(mb_strtolower($s));
        $words = fn (string $s) => array_values(array_filter(preg_split('/[^a-z0-9]+/', $norm($s)) ?: [], fn ($w) => strlen($w) >= 2 && ! in_array($w, self::STOP, true)));
        $w = $words($name);
        if ($w === []) {
            return 0.0;
        }
        $hay = $norm($candidate);
        $set = array_flip($words($candidate));
        $hits = count(array_filter($w, fn ($t) => isset($set[$t]) || (strlen($t) >= 5 && str_contains($hay, $t))));
        $host = '';
        if (preg_match('#([a-z0-9.-]+\.[a-z]{2,})\s*$#i', trim($candidate), $m)) {
            $host = preg_replace('/^www\./', '', strtolower($m[1]));
        }
        $bonus = $host !== '' && str_contains($norm($name), $host) ? 1.0 : 0.0;

        return max($hits / count($w), $bonus);
    }
}
