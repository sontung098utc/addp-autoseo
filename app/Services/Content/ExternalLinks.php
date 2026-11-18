<?php

namespace App\Services\Content;

/**
 * Liên kết tới tên miền khác site phải mở tab mới (target="_blank") với rel="nofollow noopener noreferrer". Neo nội bộ (#nguon-1, #id), mailto/tel và liên kết cùng tên miền không đổi.
 * Chỉ sửa trong thẻ <a> (không đụng chữ); chạy lại nhiều lần cho cùng kết quả (không nhân đôi target/rel).
 */
final class ExternalLinks
{
    private const REL = ['nofollow', 'noopener', 'noreferrer'];

    /** @param list<string> $internalHosts tên miền (hoặc URL) coi là cùng site */
    public static function normalize(string $html, array $internalHosts = []): string
    {
        if ($html === '' || stripos($html, '<a') === false) {
            return $html;
        }
        $internal = array_values(array_filter(array_map([self::class, 'host'], $internalHosts)));

        return (string) preg_replace_callback('/<a\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i', function (array $m) use ($internal): string {
            $attrs = $m[1];
            if (! preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attrs, $h)) {
                return $m[0];
            }
            $host = self::host(html_entity_decode($h[1] !== '' ? $h[1] : ($h[2] ?? ''), ENT_QUOTES | ENT_HTML5));
            if ($host === '' || in_array($host, $internal, true)) {
                return $m[0];
            }
            $attrs = preg_match('/\starget\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', $attrs)
                ? (string) preg_replace('/(\starget\s*=\s*)(?:"[^"]*"|\'[^\']*\')/i', '$1"_blank"', $attrs, 1)
                : $attrs.' target="_blank"';
            $tokens = self::REL;
            if (preg_match('/\srel\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $attrs, $r)) {
                foreach (preg_split('/\s+/', strtolower(trim($r[1] !== '' ? $r[1] : ($r[2] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
                    if (! in_array($t, $tokens, true)) {
                        $tokens[] = $t;
                    }
                }
                $attrs = (string) preg_replace('/(\srel\s*=\s*)(?:"[^"]*"|\'[^\']*\')/i', '$1"'.implode(' ', $tokens).'"', $attrs, 1);
            } else {
                $attrs .= ' rel="'.implode(' ', $tokens).'"';
            }

            return '<a'.$attrs.'>';
        }, $html);
    }

    /** Tên miền (bỏ www.) nếu là liên kết http(s) hoặc // tuyệt đối; chuỗi rỗng với neo, tương đối, mailto, tel. Nhận cả tên miền trần. */
    private static function host(string $u): string
    {
        $u = trim($u);
        if (preg_match('#^(?:https?:)?//([^/?\\#:\s]+)#i', $u, $m)) {
            return preg_replace('/^www\./', '', strtolower($m[1])) ?? '';
        }
        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $u)) {
            return preg_replace('/^www\./', '', strtolower($u)) ?? '';
        }

        return '';
    }
}
