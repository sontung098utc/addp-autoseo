<?php

namespace App\Services\Content;

/**
 * Sửa bài THEO ĐOẠN: tách bài thành các mục H2, xác định mục nào chứa chỗ lỗi, ghép lại các mục đã viết lại. Không gọi AI, không đụng bộ chấm.
 * Mọi hàm trả null khi không chắc (không tìm đúng một mục, tiêu đề bị đổi, số mục lệch...) để bên gọi quay về viết lại cả bài, không đoán.
 */
final class SectionSplice
{
    /**
     * @return array{pre: string, sections: list<string>} phần trước H2 đầu tiên và các mục (mỗi mục BẮT ĐẦU bằng thẻ h2, gồm cả khối nằm sau nó tới H2 kế)
     */
    public static function split(string $html): array
    {
        $parts = preg_split('/(?=<h2[\s>])/iu', $html, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pre = '';
        if ($parts !== [] && ! preg_match('/^\s*<h2[\s>]/iu', $parts[0])) {
            $pre = (string) array_shift($parts);
        }

        return ['pre' => $pre, 'sections' => array_values($parts)];
    }

    /** Tiêu đề (chữ thuần, chuẩn hóa) của một mục. */
    public static function heading(string $section): string
    {
        return preg_match('#<h2[^>]*>(.*?)</h2>#isu', $section, $m) ? self::norm(strip_tags($m[1])) : '';
    }

    /**
     * Mục nào chứa từng chỗ lỗi. Mỗi chỗ lỗi phải khớp ĐÚNG MỘT mục; có chỗ không khớp/khớp nhiều mục thì null.
     *
     * @param  list<string>  $gaps  chuỗi mô tả chỗ lỗi (đoạn mẫu hoặc «câu» trích)
     * @param  list<string>  $sections
     * @return ?list<int> chỉ số mục (tăng dần, duy nhất)
     */
    public static function locate(array $gaps, array $sections): ?array
    {
        if ($gaps === [] || $sections === []) {
            return null;
        }
        $plain = array_map(fn (string $s) => self::norm(strip_tags($s)), $sections);
        $found = [];
        foreach ($gaps as $gap) {
            $key = mb_substr(self::snippet((string) $gap), 0, 60);
            if (mb_strlen($key) < 8) {
                return null; // quá ngắn để khẳng định thuộc mục nào (đoạn mẫu của CITE02 có thể chỉ là «5,7% – 6,4%»; vẫn phải khớp ĐÚNG MỘT mục)
            }
            $hits = [];
            foreach ($plain as $i => $p) {
                if (mb_stripos($p, $key) !== false) {
                    $hits[] = $i;
                }
            }
            if (count($hits) !== 1) {
                return null;
            }
            $found[$hits[0]] = true;
        }
        $idx = array_keys($found);
        sort($idx);

        return $idx;
    }

    /**
     * Ghép các mục đã viết lại vào bài. Chỉ nhận khi: số mục trả về đúng bằng số mục yêu cầu, mỗi mục bắt đầu bằng H2, TIÊU ĐỀ GIỮ NGUYÊN (không đổi mục lục/neo),
     * và không có chữ nào nằm ngoài các mục. Phần HTML mới được làm sạch bằng $clean; phần còn lại của bài giữ nguyên từng ký tự.
     *
     * @param  list<int>  $indices  chỉ số mục được yêu cầu viết lại (tăng dần)
     * @param  callable(string): string  $clean  làm sạch HTML của mục mới
     */
    public static function merge(string $previousHtml, array $indices, string $newHtml, callable $clean): ?string
    {
        $prev = self::split($previousHtml);
        $new = self::split($newHtml);
        if (trim(strip_tags($new['pre'])) !== '' || count($new['sections']) !== count($indices)) {
            return null;
        }
        $sections = $prev['sections'];
        foreach (array_values($indices) as $k => $i) {
            if (! isset($sections[$i]) || self::heading($new['sections'][$k]) !== self::heading($sections[$i]) || self::heading($sections[$i]) === '') {
                return null;
            }
        }
        foreach (array_values($indices) as $k => $i) {
            $sections[$i] = $clean($new['sections'][$k]);
        }

        return $prev['pre'].implode('', $sections);
    }

    /**
     * Mô hình trả lại CẢ BÀI dù chỉ được yêu cầu vài mục: lấy từ bản đó đúng các mục được yêu cầu (khớp theo tiêu đề) và ghép vào bản đang giữ; mọi mục khác giữ nguyên.
     * Null nếu có mục yêu cầu không tìm thấy theo tiêu đề trong bản mới.
     *
     * @param  list<int>  $indices
     * @param  callable(string): string  $clean
     */
    public static function pick(string $previousHtml, array $indices, string $fullNewHtml, callable $clean): ?string
    {
        $prev = self::split($previousHtml);
        $byHeading = [];
        foreach (self::split($fullNewHtml)['sections'] as $s) {
            $h = self::heading($s);
            if ($h !== '' && ! isset($byHeading[$h])) {
                $byHeading[$h] = $s;
            }
        }
        $sections = $prev['sections'];
        foreach ($indices as $i) {
            $h = isset($sections[$i]) ? self::heading($sections[$i]) : '';
            if ($h === '' || ! isset($byHeading[$h])) {
                return null;
            }
        }
        foreach ($indices as $i) {
            $sections[$i] = $clean($byHeading[self::heading($sections[$i])]);
        }

        return $prev['pre'].implode('', $sections);
    }

    /** Đoạn mẫu của chỗ lỗi: nội dung trong «…» nếu có, không thì cả chuỗi (bỏ dấu … cuối). */
    private static function snippet(string $gap): string
    {
        $s = preg_match('/«(.+?)»/su', $gap, $m) ? $m[1] : $gap;

        return self::norm(rtrim($s, '… .'));
    }

    /** Chuẩn hóa để so khớp: bỏ thực thể HTML, mã trích/ghi chú dạng [KB#12], [T3], [a], gộp khoảng trắng, chữ thường. */
    private static function norm(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\[[^\[\]]{1,20}\]/u', ' ', $s) ?? $s;

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s) ?? ''));
    }
}
