<?php

namespace App\Services\Content;

/**
 * Khối "Nguồn tham khảo" cuối bài cho các TÀI LIỆU BẬC 1 người viết đã được cung cấp (ReferenceDossier): chỉ gồm tài liệu có thật, tìm được từ web
 * và nằm trong nhóm nguồn tin cậy. Không bịa liên kết: không có tài liệu thì không thêm gì. Nếu bài đã có khối nguồn từ Kho trí tuệ
 * (CitationRenderer) thì nối tiếp vào đúng khối đó, đánh số tiếp.
 */
final class ReferenceListRenderer
{
    /** Tiêu đề không có chữ cái tiếng Việt (dấu) và có chữ Latin: coi là tiếng nước ngoài, cần giải nghĩa. */
    public static function needsGloss(string $title): bool
    {
        return $title !== '' && preg_match('/[A-Za-z]{3,}\s+[A-Za-z]{2,}/', $title) === 1 && preg_match('/[àáảãạâầấẩẫậăằắẳẵặèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ]/iu', $title) !== 1;
    }

    /** @param list<array{title?: string, title_vi?: string, domain?: string, url?: string}> $references */
    public static function append(string $html, array $references): string
    {
        $items = [];
        foreach ($references as $r) {
            $url = (string) ($r['url'] ?? '');
            if (! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $host = (string) (preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)) ?: ($r['domain'] ?? ''));
            $title = trim(strip_tags((string) ($r['title'] ?? '')));
            $vi = trim(strip_tags((string) ($r['title_vi'] ?? '')));
            $items[$url] = (isset($r['_n']) ? '<!--n:'.(int) $r['_n'].'-->' : '').'<a href="'.e($url).'" rel="nofollow noopener">'.e($title !== '' ? $title : $host).'</a>'.($host !== '' ? ' — '.e($host) : '')
                .(self::needsGloss($title) && $vi !== '' ? ' — <em>'.e($vi).'</em>' : ''); // tiêu đề tiếng nước ngoài kèm giải nghĩa tiếng Việt
        }
        if ($html === '' || $items === []) {
            return $html;
        }
        $li = '';
        foreach ($items as $it) {
            if (preg_match('/^<!--n:(\d+)-->/', $it, $nm)) {
                $li .= '<li id="nguon-'.$nm[1].'">'.substr($it, strlen($nm[0])).'</li>';
            } else {
                $li .= '<li>'.$it.'</li>';
            }
        }
        if (preg_match('#(<div class="autoseo-refs">.*?<ol>.*?)(</ol>)#su', $html)) {
            return (string) preg_replace('#(<div class="autoseo-refs">.*?)(</ol>)#su', '$1'.str_replace('$', '\$', $li).'$2', $html, 1);
        }

        return $html."\n".'<div class="autoseo-refs"><p><strong>Nguồn tham khảo</strong></p><ol>'.$li.'</ol></div>';
    }

    private const MARK = '/\s*\[T(\d+(?:\s*[,;]\s*T?\d+)*)\]/u';

    /**
     * Đổi dấu [T1], [T2, T3] người viết đặt sau câu dựa vào tài liệu web thành chú thích SỐ bấm được [n] (neo tới dòng nguồn cuối bài), đánh số tiếp sau các chú thích kho (nếu có),
     * rồi dựng khối "Nguồn tham khảo": nguồn đã được trích (có số) trước, các nguồn còn lại được dùng sau. Dấu trỏ tới tài liệu không tồn tại bị xóa (không bao giờ lộ [T9] ra bài).
     *
     * @param  list<array<string,mixed>>  $all  toàn bộ tài liệu người viết được cung cấp (mã [Tn] là chỉ số 1-based trong danh sách này)
     * @param  list<array<string,mixed>>  $listed  tài liệu đáng liệt kê dù chưa có dấu trích (được hồ sơ nội dung dựa vào)
     */
    public static function apply(string $html, array $all, array $listed): string
    {
        if ($html === '') {
            return $html;
        }
        $offset = preg_match_all('/<li\b[^>]*\bid="nguon-\d+"/i', $html);
        $number = [];
        $html = (string) preg_replace_callback(self::MARK, function (array $m) use ($all, $offset, &$number): string {
            $out = '';
            foreach (preg_split('/[^\d]+/', $m[1], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $d) {
                $i = (int) $d - 1;
                $url = (string) ($all[$i]['url'] ?? '');
                if (! preg_match('#^https?://#i', $url)) {
                    continue;
                }
                $number[$i] ??= $offset + count($number) + 1;
                $title = trim(strip_tags((string) ($all[$i]['title'] ?? '')));
                $host = preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));
                $out .= '<sup class="autoseo-cite" title="'.e('Nguồn: '.($title !== '' ? $title : $host).' ('.$host.')').'"><a href="#nguon-'.$number[$i].'">['.$number[$i].']</a></sup>';
            }

            return $out;
        }, $html);
        asort($number);
        $ordered = [];
        foreach (array_keys($number) as $i) {
            $ordered[] = ['ref' => $all[$i], 'n' => $number[$i]];
        }
        $citedUrls = array_map(fn ($o) => (string) $o['ref']['url'], $ordered);
        $rest = array_values(array_filter($listed, fn ($r) => ! in_array((string) ($r['url'] ?? ''), $citedUrls, true)));

        return self::append($html, array_merge(array_map(fn ($o) => $o['ref'] + ['_n' => $o['n']], $ordered), $rest));
    }
}
