<?php

namespace App\Services\Planning\Import;

/**
 * "Brief" của một bài trong file kế hoạch: các thông tin ngoài từ khóa mà người viết cần (đối tượng, giọng văn, độ dài, cấu trúc, câu hỏi cần trả lời,
 * nguồn nên dùng, liên kết nội bộ, điều cần tránh...). Hệ thống chỉ lưu có cấu trúc một ít trường của bài (từ khóa phụ, ý định, cụm, ưu tiên...), nên phần còn lại
 * được GHÉP thành các dòng có nhãn trong chỉ dẫn của bài (`articles.guidance`, tối đa 1500 ký tự). Chỉ dẫn này đi tới Người viết và Biên tập, và bộ chấm ĐO được
 * độ dài, số bảng, số ảnh, số mục H2 từ chính các dòng này (GuidanceConstraints), nên các cột số được diễn đạt đúng kiểu câu bộ đo hiểu.
 */
final class PlanBrief
{
    public const GUIDANCE_MAX = 1500;

    /** khóa => [nhãn cột, mô tả cho AI/hướng dẫn, nhãn dòng trong chỉ dẫn]. Thứ tự = thứ tự ghép (điều đo được đứng trước để không bị cắt). */
    public const FIELDS = [
        'length_words' => ['Độ dài (số từ)', 'độ dài mong muốn của bài theo số từ: một số (1500) hoặc khoảng (1200-1500)', 'Độ dài'],
        'table_count' => ['Số bảng', 'số bảng so sánh/tổng hợp bài cần có (số nguyên)', 'Cấu trúc'],
        'image_count' => ['Số ảnh tối đa', 'số ảnh tối đa trong bài (số nguyên)', 'Cấu trúc'],
        'h2_count' => ['Số mục H2 tối thiểu', 'số mục H2 (đề mục lớn) tối thiểu của bài (số nguyên)', 'Cấu trúc'],
        'faq' => ['Có mục hỏi đáp (FAQ)', 'bài có mục câu hỏi thường gặp hay không (Có/Không)', 'FAQ'],
        'post_type' => ['Loại bài', 'dạng bài: trụ cột, hướng dẫn, so sánh, danh sách, hỏi đáp, tin tức, bài sản phẩm...', 'Loại bài'],
        'audience' => ['Đối tượng đọc', 'người đọc mục tiêu (tuổi, nghề, mức hiểu biết, hoàn cảnh)', 'Đối tượng đọc'],
        'angle' => ['Góc tiếp cận / điều cần nhấn', 'góc viết, điều cần nhấn mạnh, thông điệp chính của bài', 'Góc tiếp cận'],
        'tone' => ['Giọng văn', 'giọng văn/phong cách: gần gũi, chuyên môn, nghiêm túc, hài hước...', 'Giọng văn'],
        'questions' => ['Câu hỏi bài cần trả lời', 'các câu hỏi cụ thể người đọc đặt ra mà bài phải trả lời (ngăn cách bằng ; hoặc xuống dòng)', 'Câu hỏi bài cần trả lời'],
        'must_sources' => ['Nguồn nên dùng', 'tên/URL nguồn, hướng dẫn, tổ chức nên dùng làm căn cứ (hệ thống KHÔNG tự tải link)', 'Nguồn nên dùng (hệ thống không tự tải link)'],
        'internal_links' => ['Liên kết nội bộ nên có', 'bài hoặc URL của chính website nên được liên kết tới trong bài', 'Liên kết nội bộ nên có'],
        'avoid' => ['Điều cần tránh', 'điều không được nói/không nên đề cập, từ cấm riêng của bài', 'Điều cần tránh'],
        'competitor_refs' => ['Đối thủ / bài tham khảo', 'URL hoặc tên bài của đối thủ cần tham khảo góc viết (chỉ để tham khảo, hệ thống không tự tải)', 'Bài tham khảo (chỉ để tham khảo góc viết)'],
        'cta' => ['CTA / sản phẩm liên quan', 'lời kêu gọi hành động hoặc sản phẩm/dịch vụ cần nhắc tới trong bài', 'CTA / sản phẩm liên quan'],
        'expert_review' => ['Cần chuyên gia duyệt', 'bài có cần người có chuyên môn duyệt kỹ trước khi dùng không (Có/Không)', 'Chuyên gia'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    /** Có/Không từ nhiều cách viết: có, yes, x, ✓, 1, true, cần → true; không, no, 0, false → false; trống/lạ → null. */
    public static function yesNo(string $v): ?bool
    {
        $t = mb_strtolower(trim($v));
        if ($t === '') {
            return null;
        }
        if (in_array($t, ['có', 'co', 'yes', 'y', 'x', '✓', '✔', '1', 'true', 'cần', 'can', 'đúng', 'ok'], true) || preg_match('/^(có|cần)(?![\p{L}])(?!\s+thể)/u', $t) === 1) {
            return true;
        }
        if (in_array($t, ['không', 'khong', 'no', 'n', '0', 'false', 'ko', 'k', 'không cần', '-'], true) || preg_match('/^không(?![\p{L}])(?!\s+thể)/u', $t) === 1) {
            return false;
        }

        return null;
    }

    /** Số nguyên không âm tối đa $max ("3", "3 ảnh"); không hiểu thì null. */
    public static function count(string $v, int $max = 30): ?int
    {
        if (preg_match('/^\s*(\d{1,3})\b/u', trim($v), $m)) {
            return min($max, (int) $m[1]);
        }

        return null;
    }

    /**
     * Độ dài bài: "1500", "1.500", "khoảng 1500", "1200-1500", "1200–1500 từ", "tối đa 800", "ít nhất 1200". Trả [min, max, approx]; ngoài 50–20000 từ thì null.
     *
     * @return array{0:?int,1:?int,2:bool}|null
     */
    public static function length(string $v): ?array
    {
        $t = mb_strtolower(trim($v));
        $t = preg_replace('/(?<=\d)[.,](?=\d{3}(?!\d))/u', '', $t) ?? $t; // 1.500 → 1500
        if ($t === '') {
            return null;
        }
        $ok = fn (?int $n) => $n !== null && $n >= 50 && $n <= 20000;
        if (preg_match('/(\d{2,6})\s*(?:-|–|—|đến|tới|~)\s*(\d{2,6})/u', $t, $m) && $ok((int) $m[1]) && $ok((int) $m[2]) && (int) $m[1] <= (int) $m[2]) {
            return [(int) $m[1], (int) $m[2], false];
        }
        if (preg_match('/(?:tối đa|dưới|không quá|<=?)\s*(\d{2,6})/u', $t, $m) && $ok((int) $m[1])) {
            return [null, (int) $m[1], false];
        }
        if (preg_match('/(?:ít nhất|tối thiểu|trên|>=?)\s*(\d{2,6})/u', $t, $m) && $ok((int) $m[1])) {
            return [(int) $m[1], null, false];
        }
        if (preg_match('/(\d{2,6})/u', $t, $m) && $ok((int) $m[1])) {
            return [(int) $m[1], (int) $m[1], true];
        }

        return null;
    }

    /**
     * Ghép brief thành các dòng có nhãn, theo thứ tự FIELDS, bằng câu mà bộ đo độ dài/bảng/ảnh/H2 hiểu.
     *
     * @param  array<string,string>  $values  khóa trường => giá trị ô thô
     * @return list<string>
     */
    public static function lines(array $values): array
    {
        $clean = fn (string $v, int $n = 400) => PlanFileFields::text($v, $n);
        $out = [];
        if (($len = self::length((string) ($values['length_words'] ?? ''))) !== null) {
            [$min, $max, $approx] = $len;
            $out[] = 'Độ dài: '.match (true) {
                $approx => 'khoảng '.$min.' từ',
                $min !== null && $max !== null => 'ít nhất '.$min.' từ, tối đa '.$max.' từ',
                $min !== null => 'ít nhất '.$min.' từ',
                default => 'tối đa '.$max.' từ',
            };
        }
        $struct = [];
        if (($n = self::count((string) ($values['table_count'] ?? ''), 10)) !== null) {
            $struct[] = 'đúng '.$n.' bảng';
        }
        if (($n = self::count((string) ($values['image_count'] ?? ''), 10)) !== null) {
            $struct[] = 'tối đa '.$n.' ảnh';
        }
        if (($n = self::count((string) ($values['h2_count'] ?? ''), 20)) !== null) {
            $struct[] = 'ít nhất '.$n.' mục H2';
        }
        if ($struct !== []) {
            $out[] = 'Cấu trúc: '.implode('; ', $struct);
        }
        $faq = self::yesNo((string) ($values['faq'] ?? ''));
        if ($faq !== null) {
            $out[] = $faq ? 'Có mục câu hỏi thường gặp (FAQ).' : 'Không thêm mục câu hỏi thường gặp.';
        }
        foreach (['post_type', 'audience', 'angle', 'tone', 'questions', 'must_sources', 'internal_links', 'avoid', 'competitor_refs', 'cta'] as $k) {
            $v = $clean((string) ($values[$k] ?? ''), $k === 'questions' ? 600 : 400);
            if ($v !== '') {
                $out[] = self::FIELDS[$k][2].': '.$v;
            }
        }
        if (self::yesNo((string) ($values['expert_review'] ?? '')) === true) {
            $out[] = 'Cần người có chuyên môn duyệt kỹ trước khi dùng.';
        }

        return $out;
    }

    /** URL an toàn để GHI vào chỉ dẫn: http/https, không trỏ về máy chủ nội bộ (localhost, IP riêng/loopback/metadata, tên miền .local/.internal). Hệ thống không tải URL, nhưng không lưu địa chỉ nội bộ. */
    public static function safeUrl(string $url): bool
    {
        if (! preg_match('#^https?://#i', trim($url))) {
            return false;
        }
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || preg_match('/\.(local|internal|lan|home|corp)$/', $host)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return true;
    }

    /**
     * Tách danh sách (chấm phẩy, xuống dòng, dấu phẩy ngoài URL) và loại URL nội bộ. @return array{kept: list<string>, dropped: list<string>}
     */
    public static function splitRefs(string $raw): array
    {
        $kept = [];
        $dropped = [];
        foreach (preg_split('/[;\n]+|,(?!\d)/u', $raw) ?: [] as $t) {
            $t = trim($t);
            if ($t === '') {
                continue;
            }
            if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $t) || str_starts_with(strtolower($t), 'www.')) {
                if (! self::safeUrl(str_starts_with(strtolower($t), 'www.') ? 'https://'.$t : $t)) {
                    $dropped[] = $t;

                    continue;
                }
            }
            $kept[] = $t;
        }

        return ['kept' => $kept, 'dropped' => $dropped];
    }

    /** Ghép các dòng (brief trước, ghi chú tự do sau) trong giới hạn; phần vượt bị cắt và báo. @return array{text:string, cut:bool} */
    public static function fit(array $lines): array
    {
        $text = '';
        foreach ($lines as $l) {
            $next = $text === '' ? $l : $text."\n".$l;
            if (mb_strlen($next) > self::GUIDANCE_MAX) {
                return ['text' => $text !== '' ? $text : mb_substr($l, 0, self::GUIDANCE_MAX), 'cut' => true];
            }
            $text = $next;
        }

        return ['text' => $text, 'cut' => false];
    }

    /** Các ràng buộc ĐO ĐƯỢC mà bộ chấm sẽ áp từ chỉ dẫn này (cho bản xem trước). @return list<string> nhãn */
    public static function measurable(string $guidance): array
    {
        return array_values(array_unique(array_column(\App\Services\Content\Quality\GuidanceConstraints::extract($guidance)['constraints'], 'label')));
    }
}
