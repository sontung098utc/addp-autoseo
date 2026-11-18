<?php

namespace App\Services\Content;

/**
 * Thay thế chỉ trong PHẦN VĂN BẢN của HTML, không bao giờ trong thẻ/thuộc tính. Dấu trích dẫn [KB#12], [T4] đổi thành thẻ <sup ... title="...">
 * mà gặp chúng ở giá trị thuộc tính (vd. title="..." của ghi chú) thì dấu " của thẻ mới đóng sớm thuộc tính, mảnh thẻ rò ra thành chữ hiển thị (`">`).
 */
final class MarkupText
{
    /** @param callable(array<int,string>):string $callback */
    public static function replaceInText(string $pattern, callable $callback, string $html): string
    {
        $parts = preg_split('/(<!--.*?-->|<[^>]*>)/su', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }
        foreach ($parts as $i => $p) {
            if ($i % 2 === 0 && $p !== '') {
                $parts[$i] = (string) preg_replace_callback($pattern, $callback, $p);
            }
        }

        return implode('', $parts);
    }
}
