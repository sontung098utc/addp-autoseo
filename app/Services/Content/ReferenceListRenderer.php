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
    /**
     * Tiêu đề nguồn người đọc thấy phải đọc được: tên trang kiểu đường dẫn ("bi quyet pha ca phe phin ngon 03383"), mảnh bảng ("Stage | Number Tested |") hoặc trùng tên miền
     * không phải tiêu đề. Trả chuỗi rỗng để nơi gọi dùng tên miền thay thế.
     */
    public static function readableTitle(string $title, string $host): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ($t === '' || strcasecmp($t, $host) === 0 || strcasecmp(preg_replace('#^www\.#i', '', $t) ?? '', $host) === 0) {
            return '';
        }
        if (substr_count($t, '|') >= 2 || str_ends_with($t, '|')) {
            return ''; // mảnh bảng hoặc đoạn trích, không phải tiêu đề
        }
        if (preg_match('/^[a-z0-9 \-_]{20,}$/', $t) === 1 && (preg_match('/\d{3,}/', $t) === 1 ? substr_count($t, ' ') >= 3 : substr_count($t, ' ') >= 5)) {
            return ''; // tên đường dẫn: toàn chữ thường không dấu (kèm mã số hoặc dài)
        }

        return $t;
    }

    /**
     * Công cụ tìm web trả tiêu đề đã bị CẮT giữa chừng (vd "INTERNATIONAL CONFERENCE ON HARMONISATION OF TECHNICAL", "Guidance for I"): nhận diện theo từ cuối là từ nối/chữ cái lẻ hoặc cả tiêu đề viết hoa, dài vừa phải
     * và không có dấu kết câu. Không chắc chắn tuyệt đối nên chỉ dùng để chọn cách hiển thị an toàn (ưu tiên phần giải nghĩa tiếng Việt, hoặc thêm dấu …), không bỏ nguồn.
     */
    public static function looksTruncated(string $title): bool
    {
        $t = trim($title);
        $len = mb_strlen($t);
        if ($len < 40 || $len > 90 || preg_match('/[.?!)\]»”"\'…]$/u', $t) === 1) {
            return false;
        }
        $words = preg_split('/\s+/u', $t) ?: [];
        $last = mb_strtolower((string) end($words));
        $stop = ['of', 'for', 'to', 'the', 'and', 'in', 'on', 'at', 'with', 'by', 'a', 'an', 'or', 'vs', 'from', 'into', 'as', 'than', 'that', 'which'];
        if (in_array($last, $stop, true) || (mb_strlen($last) === 1 && ctype_alpha($last))) {
            return true;
        }

        return mb_strtoupper($t) === $t && substr_count($t, ' ') >= 4; // cả tiêu đề viết hoa dài: thường là đoạn đầu tiêu đề văn bản bị cắt
    }

    public static function needsGloss(string $title): bool
    {
        return $title !== '' && preg_match('/[A-Za-z]{3,}\s+[A-Za-z]{2,}/', $title) === 1 && preg_match('/[àáảãạâầấẩẫậăằắẳẵặèéẻẽẹêềếểễệìíỉĩịòóỏõọôồốổỗộơờớởỡợùúủũụưừứửữựỳýỷỹỵđ]/iu', $title) !== 1;
    }

    /** @param list<array{title?: string, title_vi?: string, domain?: string, url?: string}> $references */
    public static function append(string $html, array $references): string
    {
        $items = [];
        $existingRefs = preg_match('#<div class="autoseo-refs">.*#s', $html, $mm) ? html_entity_decode($mm[0]) : '';
        foreach ($references as $r) {
            $url = (string) ($r['url'] ?? '');
            if (! preg_match('#^https?://#i', $url)) {
                continue;
            }
            if (! isset($r['_n']) && $existingRefs !== '' && str_contains($existingRefs, 'href="'.$url.'"')) {
                continue; // cùng một trang đã có trong khối nguồn (vd. từ kho): không liệt kê hai lần
            }
            $host = (string) (preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST)) ?: ($r['domain'] ?? ''));
            $title = self::readableTitle(trim(strip_tags((string) ($r['title'] ?? ''))), (string) $host);
            $vi = trim(strip_tags((string) ($r['title_vi'] ?? '')));
            if (! isset($r['_n']) && ($title === '' || preg_match('/\p{Lu}/u', $title) !== 1 || preg_match('/\p{L}{3,}/u', $title) !== 1)) {
                continue; // nguồn chưa được trích mà tiêu đề rác (không đọc được): không liệt kê cho đủ số
            }
            if ($title !== '' && self::looksTruncated($title)) { // tiêu đề gốc bị cắt: dùng giải nghĩa tiếng Việt làm tên hiển thị, không có thì thêm dấu … cho trung thực
                $title = $vi !== '' ? $vi : rtrim($title, ' —-').'…';
                $vi = '';
            }
            $items[$url] = (isset($r['_n']) ? '<!--n:'.(int) $r['_n'].'-->' : '').'<a href="'.e($url).'" rel="nofollow noopener">'.e($title !== '' ? $title : 'Trang trên '.$host).'</a>'.($host !== '' && $title !== '' ? ' — '.e($host) : '')
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
