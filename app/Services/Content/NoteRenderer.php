<?php

namespace App\Services\Content;

/**
 * GHI CHÚ thật (footnote) do người viết soạn: khái niệm, định nghĩa thuật ngữ, bối cảnh bổ sung mà thân bài không nhắc lại. Khác với chú thích nguồn [1] (CitationRenderer).
 * Người viết đặt [^a] sau thuật ngữ và khai báo notes:[{key:"a",text:"..."}]; ở đây đổi [^a] thành chữ cái nhỏ có tooltip và dựng khối "Ghi chú" cuối bài.
 * Không để mồ côi: dấu không có ghi chú bị xóa, ghi chú không được dấu nào trỏ tới bị bỏ. Văn bản ghi chú được escape (không nhận HTML).
 */
final class NoteRenderer
{
    public const MAX = 4;

    private const TOKEN = '/\[\^([a-z])\]/u';

    /** @param list<mixed> $notes @return list<array{key:string,text:string}> */
    public static function clean(array $notes): array
    {
        $out = [];
        foreach ($notes as $n) {
            if (! is_array($n)) {
                continue;
            }
            $key = strtolower(trim((string) ($n['key'] ?? '')));
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($n['text'] ?? ''))) ?? '');
            if (preg_match('/^[a-z]$/', $key) === 1 && mb_strlen($text) >= 12 && ! isset($out[$key])) {
                $out[$key] = ['key' => $key, 'text' => mb_substr($text, 0, 420)];
            }
        }

        return array_slice(array_values($out), 0, self::MAX);
    }

    /** @param list<mixed> $notes */
    public static function apply(string $html, array $notes): string
    {
        if ($html === '') {
            return $html;
        }
        $by = [];
        foreach (self::clean($notes) as $n) {
            $by[$n['key']] = $n['text'];
        }
        $used = [];
        $html = (string) preg_replace_callback(self::TOKEN, function (array $m) use ($by, &$used): string {
            $k = $m[1];
            if (! isset($by[$k]) || isset($used[$k])) {
                return ''; // dấu không có ghi chú, hoặc dấu lặp lại: bỏ
            }
            $used[$k] = true;

            return '<sup class="autoseo-note" data-note="'.$k.'"'.TooltipAttrs::attrs(self::plain($by[$k])).'><a href="#ghichu-'.$k.'">['.$k.']</a></sup>';
        }, $html);
        if ($used === []) {
            return $html;
        }
        ksort($used);
        $li = '';
        foreach (array_keys($used) as $k) {
            $li .= '<li id="ghichu-'.$k.'">'.e($by[$k]).'</li>';
        }

        return $html."\n".'<aside class="autoseo-notes"><p class="autoseo-notes__title"><strong>Ghi chú</strong></p><ol type="a">'.$li.'</ol></aside>';
    }

    /** Tooltip không mang dấu trích dẫn thô: [KB#12], [T4], [T1, T2] (và chú thích số [3] đã đổi). Các dấu này sẽ được đổi thành thẻ có dấu " nên không được nằm trong giá trị thuộc tính. */
    private static function plain(string $t): string
    {
        $t = preg_replace('/\s*\[\s*(?:KB#|T)\s*\d[^\]]*\]/u', '', $t) ?? $t;
        $t = preg_replace('/\s*\[\d+(?:\s*[,;]\s*\d+)*\]/u', '', $t) ?? $t;

        return trim($t);
    }
}
