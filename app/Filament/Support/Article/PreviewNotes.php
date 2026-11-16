<?php

namespace App\Filament\Support\Article;

/**
 * Khung xem trước chạy sandbox chỉ cho MỞ CỬA SỔ MỚI (allow-popups; không script, không điều hướng khung): bấm vào liên kết neo `#nguon-1` làm cả khung điều hướng đi mất (trắng màn hình).
 * Ở đây chú thích [1] và ghi chú [a] trở thành phần tử bấm/rê chuột được hiện HỘP CHÚ THÍCH bằng CSS thuần (:hover và :focus, xem PreviewCss), liên kết khác thành chữ có hộp hiện địa chỉ.
 * Chỉ dùng để XEM TRƯỚC: HTML lưu trong CSDL và HTML gửi sang CMS không đổi (ở CMS vẫn là liên kết neo và thuộc tính title).
 */
final class PreviewNotes
{
    public static function prepare(string $html): string
    {
        if ($html === '') {
            return $html;
        }
        // Địa chỉ nguồn theo số chú thích: dòng <li id="nguon-N"> của khối Nguồn tham khảo → bấm [N] mở đúng trang nguồn (tab mới).
        $urls = [];
        if (preg_match_all('#<li\b[^>]*\bid="nguon-(\d+)"[^>]*>(.*?)</li>#is', $html, $lis, PREG_SET_ORDER)) {
            foreach ($lis as $li) {
                if (preg_match('#<a\b[^>]*\bhref="([^"]*)"#i', $li[2], $h) && self::isWebUrl(html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5))) {
                    $urls[(int) $li[1]] = $h[1];
                }
            }
        }
        $html = (string) preg_replace_callback('#<sup\b([^>]*\bclass="[^"]*\bautoseo-(?:cite|note)\b[^"]*"[^>]*)>(.*?)</sup>#is', function (array $m) use ($urls): string {
            $attrs = $m[1];
            $tip = '';
            if (preg_match('#\stitle="([^"]*)"#i', $attrs, $t)) {
                $tip = $t[1];
                $attrs = (string) preg_replace('#\stitle="[^"]*"#i', '', $attrs);
            }
            $n = preg_match('#<a\b[^>]*\bhref="\#nguon-(\d+)"#i', $m[2], $nm) ? (int) $nm[1] : null;
            $inner = (string) preg_replace('#<a\b[^>]*>(.*?)</a>#is', '$1', $m[2]);
            if ($n !== null && isset($urls[$n])) { // chú thích số bấm được: mở trang nguồn ở tab mới (khung vẫn không chạy script)
                $inner = '<a class="pv-cite-link" href="'.$urls[$n].'" target="_blank" rel="noopener noreferrer nofollow">'.$inner.'</a>';
            }

            return '<sup'.$attrs.' tabindex="0"'.($tip !== '' ? ' data-tip="'.$tip.'"' : '').'>'.$inner.'</sup>';
        }, $html);

        return (string) preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#is', function (array $m): string {
            if (str_contains($m[1], 'pv-cite-link')) {
                return $m[0]; // chú thích số đã được dựng ở trên
            }
            if (! preg_match('#\bhref="([^"]*)"#i', $m[1], $h)) {
                return $m[2];
            }
            $href = html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5);
            if ($href === '' || $href[0] === '#') {
                return $m[2]; // neo trong bài: không điều hướng
            }

            if (self::isWebUrl($href)) { // liên kết ngoài bấm được, mở ở tab mới (sandbox chỉ cho mở cửa sổ, không cho script)
                return '<a class="pv-link" href="'.e($href).'" target="_blank" rel="noopener noreferrer nofollow" data-tip="'.e($href).'">'.$m[2].'</a>';
            }

            return '<span class="pv-link" tabindex="0" data-tip="'.e($href).'">'.$m[2].'</span>';
        }, $html);
    }

    /** Chỉ http/https mới được mở từ khung xem trước. */
    private static function isWebUrl(string $u): bool
    {
        return preg_match('#^https?://[^\s"<>]+$#i', $u) === 1;
    }
}
