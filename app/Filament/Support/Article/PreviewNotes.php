<?php

namespace App\Filament\Support\Article;

/**
 * Khung xem trước chạy sandbox RỖNG (không script, không điều hướng): bấm vào liên kết neo `#nguon-1` làm cả khung điều hướng đi mất (trắng màn hình).
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
        $html = (string) preg_replace_callback('#<sup\b([^>]*\bclass="[^"]*\bautoseo-(?:cite|note)\b[^"]*"[^>]*)>(.*?)</sup>#is', function (array $m): string {
            $attrs = $m[1];
            $tip = '';
            if (preg_match('#\stitle="([^"]*)"#i', $attrs, $t)) {
                $tip = $t[1];
                $attrs = (string) preg_replace('#\stitle="[^"]*"#i', '', $attrs);
            }
            $inner = (string) preg_replace('#<a\b[^>]*>(.*?)</a>#is', '$1', $m[2]);

            return '<sup'.$attrs.' tabindex="0"'.($tip !== '' ? ' data-tip="'.$tip.'"' : '').'>'.$inner.'</sup>';
        }, $html);

        return (string) preg_replace_callback('#<a\b([^>]*)>(.*?)</a>#is', function (array $m): string {
            if (! preg_match('#\bhref="([^"]*)"#i', $m[1], $h)) {
                return $m[2];
            }
            $href = html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5);
            if ($href === '' || $href[0] === '#') {
                return $m[2]; // neo trong bài: không điều hướng
            }

            return '<span class="pv-link" tabindex="0" data-tip="'.e($href).'">'.$m[2].'</span>';
        }, $html);
    }
}
