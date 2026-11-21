<?php

namespace App\Services\Planning\Import;

use App\Filament\Support\Schedule\VnTime;
use Carbon\CarbonImmutable;

/**
 * Danh mục các trường của kế hoạch/chiến dịch/bài viết mà một cột trong file người dùng có thể được ánh xạ tới,
 * kèm cách chuẩn hóa giá trị ô. AI chỉ được chọn trong danh mục này (whitelist); mọi giá trị ô đều được chuẩn hóa lại ở đây.
 */
final class PlanFileFields
{
    /** khóa => [nhãn, nhóm, nhận nhiều cột?, mô tả cho AI] */
    public const FIELDS = [
        'primary_keyword' => ['Từ khóa chính', 'Bài viết', false, 'từ khóa/chủ đề chính mà MỘT bài nhắm tới; mỗi dòng dữ liệu thường là một bài'],
        'title' => ['Tiêu đề gợi ý', 'Bài viết', false, 'tiêu đề hoặc tên bài dự kiến'],
        'secondary_keywords' => ['Từ khóa phụ', 'Bài viết', true, 'các từ khóa phụ/liên quan, thường ngăn cách bằng dấu phẩy hoặc chấm phẩy'],
        'search_intent' => ['Ý định tìm kiếm', 'Bài viết', false, 'ý định người tìm: thông tin / thương mại / giao dịch / điều hướng (hoặc viết tắt Info/Comm/Trans, TOFU/MOFU/BOFU)'],
        'topic_cluster' => ['Cụm chủ đề', 'Bài viết', false, 'nhóm/cụm chủ đề, trụ cột nội dung, danh mục mà bài thuộc về'],
        'guidance' => ['Ghi chú / chỉ dẫn cho đội AI', 'Bài viết', true, 'ghi chú, dàn ý, đối tượng, góc nhìn, yêu cầu riêng cho bài (mọi cột ghi chú đều về đây)'],
        'search_volume' => ['Lượng tìm kiếm/tháng', 'Bài viết', false, 'lượng tìm kiếm mỗi tháng của từ khóa chính (một số, ví dụ 60000 hoặc 60.000)'],
        'planned_date' => ['Ngày dự kiến đăng', 'Kế hoạch', false, 'ngày dự kiến xuất bản/đăng bài'],
        'priority' => ['Mức ưu tiên', 'Kế hoạch', false, 'độ ưu tiên (số, cao/trung bình/thấp, hoặc P1/P2/P3)'],
        'campaign' => ['Tên chiến dịch', 'Chiến dịch', false, 'tên chiến dịch/đợt/giai đoạn mà bài thuộc về (dùng để gom các dòng thành chiến dịch)'],
        'campaign_description' => ['Mô tả chiến dịch', 'Chiến dịch', false, 'mục tiêu hoặc mô tả của chiến dịch'],
        'campaign_start' => ['Chiến dịch bắt đầu', 'Chiến dịch', false, 'ngày bắt đầu của chiến dịch/giai đoạn'],
        'campaign_end' => ['Chiến dịch kết thúc', 'Chiến dịch', false, 'ngày kết thúc của chiến dịch/giai đoạn'],
        'cpc' => ['CPC (VNĐ)', 'Bài viết', false, 'giá thầu CPC ước tính của từ khóa chính, tiền Việt Nam đồng (số nguyên)'],
        'competition' => ['Mức cạnh tranh', 'Bài viết', false, 'mức cạnh tranh quảng cáo của từ khóa chính: số 0-1, 0-100 hoặc thấp/trung bình/cao'],
        'difficulty' => ['Độ khó từ khóa', 'Bài viết', false, 'độ khó của từ khóa chính (số 0-100, thường gọi là KD/Keyword Difficulty)'],
        'length_words' => ['Độ dài (số từ)', 'Chỉ dẫn viết', false, 'độ dài mong muốn của bài theo số từ: một số (1500) hoặc khoảng (1200-1500)'],
        'table_count' => ['Số bảng', 'Chỉ dẫn viết', false, 'số bảng so sánh/tổng hợp bài cần có (số nguyên)'],
        'image_count' => ['Số ảnh tối đa', 'Chỉ dẫn viết', false, 'số ảnh tối đa trong bài (số nguyên)'],
        'h2_count' => ['Số mục H2 tối thiểu', 'Chỉ dẫn viết', false, 'số mục H2 (đề mục lớn) tối thiểu của bài (số nguyên)'],
        'faq' => ['Có mục hỏi đáp (FAQ)', 'Chỉ dẫn viết', false, 'bài có mục câu hỏi thường gặp hay không (Có/Không)'],
        'post_type' => ['Loại bài', 'Chỉ dẫn viết', false, 'dạng bài: trụ cột, hướng dẫn, so sánh, danh sách, hỏi đáp, tin tức, bài sản phẩm'],
        'audience' => ['Đối tượng đọc', 'Chỉ dẫn viết', false, 'người đọc mục tiêu (tuổi, nghề, mức hiểu biết, hoàn cảnh)'],
        'angle' => ['Góc tiếp cận / điều cần nhấn', 'Chỉ dẫn viết', false, 'góc viết, điều cần nhấn mạnh, thông điệp chính của bài'],
        'tone' => ['Giọng văn', 'Chỉ dẫn viết', false, 'giọng văn/phong cách: gần gũi, chuyên môn, nghiêm túc'],
        'questions' => ['Câu hỏi bài cần trả lời', 'Chỉ dẫn viết', false, 'các câu hỏi cụ thể bài phải trả lời (ngăn cách bằng chấm phẩy hoặc xuống dòng)'],
        'must_sources' => ['Nguồn nên dùng', 'Chỉ dẫn viết', false, 'tên/URL nguồn, hướng dẫn, tổ chức nên dùng làm căn cứ (hệ thống không tự tải link)'],
        'internal_links' => ['Liên kết nội bộ nên có', 'Chỉ dẫn viết', false, 'bài hoặc URL của chính website nên được liên kết tới trong bài'],
        'avoid' => ['Điều cần tránh', 'Chỉ dẫn viết', false, 'điều không được nói hoặc không nên đề cập, từ cấm riêng của bài'],
        'competitor_refs' => ['Đối thủ / bài tham khảo', 'Chỉ dẫn viết', false, 'URL hoặc tên bài đối thủ để tham khảo góc viết (chỉ tham khảo)'],
        'cta' => ['CTA / sản phẩm liên quan', 'Chỉ dẫn viết', false, 'lời kêu gọi hành động hoặc sản phẩm/dịch vụ cần nhắc trong bài'],
        'expert_review' => ['Cần chuyên gia duyệt', 'Chỉ dẫn viết', false, 'bài có cần người có chuyên môn duyệt kỹ trước khi dùng không (Có/Không)'],
        'cms_category' => ['Chuyên mục (CMS)', 'Xuất bản', false, 'chuyên mục/danh mục sẽ đăng bài lên website (chữ; chưa nối vào bộ đăng bài)'],
        'cms_slug' => ['Slug mong muốn', 'Xuất bản', false, 'đường dẫn (slug) mong muốn của bài, chữ thường không dấu (chưa nối vào bộ đăng bài)'],
        'cms_author' => ['Tác giả hiển thị', 'Xuất bản', false, 'tên tác giả hiển thị khi đăng (chưa nối vào bộ đăng bài)'],
        'reviewer' => ['Người duyệt', 'Xuất bản', false, 'tên hoặc email người/chuyên gia cụ thể sẽ duyệt bài; khớp với tài khoản hệ thống nếu có'],
        'ignore' => ['Bỏ qua (không nhập)', 'Khác', true, 'cột không dùng cho kế hoạch (số thứ tự, kết quả, người phụ trách, trạng thái, lượt tìm, link...)'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::FIELDS);
    }

    public static function label(string $field): string
    {
        return self::FIELDS[$field][0] ?? $field;
    }

    /** @return array<string,array<string,string>> nhóm => [khóa => nhãn], cho ô chọn ở giao diện */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::FIELDS as $k => [$label, $group]) {
            $out[$group][$k] = $label;
        }

        return $out;
    }

    public static function allowsMany(string $field): bool
    {
        return self::FIELDS[$field][2] ?? false;
    }

    /** "A" → 0, "B" → 1, "AA" → 26. null nếu không phải chữ cột. */
    public static function colIndex(mixed $col): ?int
    {
        if (is_int($col)) {
            return $col >= 0 ? $col : null;
        }
        $s = strtoupper(trim((string) $col));
        if ($s === '' || ! preg_match('/^[A-Z]{1,2}$/', $s)) {
            return null;
        }
        $n = 0;
        foreach (str_split($s) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    public static function colLetter(int $index): string
    {
        $s = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    /** CPC (VNĐ): số nguyên không âm ("4.500", "4500", "4,5k"); không phải số thì null. */
    public static function cpc(string $v): ?int
    {
        return self::volume($v);
    }

    /** Mức cạnh tranh về số 0–1: "0,35" → 0.35; "35" → 0.35 (thang 0-100); thấp/trung bình/cao → 0.2/0.5/0.8; không hiểu thì null. */
    public static function competition(string $v): ?float
    {
        $t = mb_strtolower(trim($v));
        if ($t === '') {
            return null;
        }
        foreach (['thấp' => 0.2, 'low' => 0.2, 'trung bình' => 0.5, 'medium' => 0.5, 'cao' => 0.8, 'high' => 0.8] as $w => $n) {
            if (str_contains($t, $w)) {
                return $n;
            }
        }
        $n = str_replace(',', '.', rtrim($t, '% '));
        if (! is_numeric($n) || (float) $n < 0 || (float) $n > 100) {
            return null;
        }

        return round((float) $n > 1 ? (float) $n / 100 : (float) $n, 2);
    }

    /** Độ khó từ khóa: số 0–100 (làm tròn); không phải số thì null. */
    public static function difficulty(string $v): ?int
    {
        $t = str_replace(',', '.', trim($v));

        return is_numeric($t) && (float) $t >= 0 && (float) $t <= 100 ? (int) round((float) $t) : null;
    }

    public static function intent(string $v): string
    {
        $t = mb_strtolower(trim($v));
        // viết tắt thường gặp (và phễu marketing): khớp nguyên giá trị, không khớp chuỗi con
        $exact = ['info' => 'informational', 'i' => 'informational', 'tofu' => 'informational', 'comm' => 'commercial', 'c' => 'commercial', 'mofu' => 'commercial',
            'trans' => 'transactional', 'tran' => 'transactional', 't' => 'transactional', 'bofu' => 'transactional', 'nav' => 'navigational', 'navi' => 'navigational', 'n' => 'navigational'];
        if (isset($exact[$t])) {
            return $exact[$t];
        }
        foreach ([
            'transactional' => ['transaction', 'giao dịch', 'mua', 'đặt hàng', 'bán'],
            'commercial' => ['commercial', 'thương mại', 'so sánh', 'đánh giá', 'review', 'comparison'],
            'navigational' => ['navigation', 'điều hướng', 'thương hiệu', 'brand'],
            'informational' => ['inform', 'thông tin', 'tìm hiểu', 'kiến thức', 'hướng dẫn'],
        ] as $intent => $needles) {
            foreach ($needles as $n) {
                if ($t !== '' && str_contains($t, $n)) {
                    return $intent;
                }
            }
        }

        return 'informational';
    }

    /** Số 0–100, từ cao/trung bình/thấp, hoặc P0–P5 (P1 cao hơn P3); không hiểu thì null. */
    public static function priority(string $v): ?int
    {
        $t = mb_strtolower(trim($v));
        if ($t === '') {
            return null;
        }
        if (preg_match('/^p\s*([0-5])$/', $t, $m)) {
            return [100, 90, 60, 30, 10, 5][(int) $m[1]];
        }
        if (in_array($t, ['tb', 'tbình'], true)) {
            return 50;
        }
        if (is_numeric(str_replace(',', '.', $t))) {
            return max(0, min(100, (int) round((float) str_replace(',', '.', $t))));
        }
        foreach (['cao' => 80, 'high' => 80, 'gấp' => 90, 'urgent' => 90, 'trung bình' => 50, 'medium' => 50, 'thấp' => 20, 'low' => 20] as $w => $n) {
            if (str_contains($t, $w)) {
                return $n;
            }
        }

        return null;
    }

    /**
     * Ngày về dạng Y-m-d (giờ Việt Nam). Chấp nhận: dd/mm/yyyy (thứ tự Việt Nam), yyyy-mm-dd, "15-Dec-2026", số ngày của Excel;
     * chỉ tháng+năm ("T1/2027", "01/2027") lấy ngày 1; thiếu năm ("20/12", "15-Dec") lấy năm hiện tại, nếu đã qua quá 30 ngày thì năm sau.
     */
    public static function date(string $v): ?string
    {
        $t = trim($v);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $t) && (float) $t > 30000 && (float) $t < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $t)->format('Y-m-d');
        }
        $t = preg_replace('/\s+\d{1,2}:\d{2}(:\d{2})?$/', '', $t) ?? $t;
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd/m/y', 'Y/m/d', 'j-M-Y', 'j M Y', 'M j, Y'] as $f) {
            $d = self::strict($f, $t);
            if ($d && (int) $d->format('Y') >= 2000) {
                return $d->format('Y-m-d');
            }
        }
        if (preg_match('#^(?:t|th|tháng)\s*\.?\s*(\d{1,2})\s*[/\-.]\s*(\d{4})$#iu', $t, $m) || preg_match('#^(\d{1,2})[/\-.](\d{4})$#', $t, $m)) {
            return (int) $m[1] >= 1 && (int) $m[1] <= 12 ? sprintf('%04d-%02d-01', (int) $m[2], (int) $m[1]) : null;
        }
        foreach (['j/n', 'j-n', 'j.n', 'j-M', 'j M', 'j/M'] as $f) {
            if ($d = self::strict($f, $t)) {
                $now = VnTime::now();
                $d = $d->setDate((int) $now->format('Y'), (int) $d->format('n'), (int) $d->format('j'));
                if ($d < $now->startOfDay()->subDays(30)->toDateTimeImmutable()) {
                    $d = $d->modify('+1 year');
                }

                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /** Phân tích đúng định dạng, không chấp nhận ngày tràn (31/02) hay thừa ký tự. */
    private static function strict(string $format, string $value): ?\DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!'.$format, $value);
        $err = \DateTimeImmutable::getLastErrors();

        return $d && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0)) ? $d : null;
    }

    /** Lượng tìm kiếm: "60000", "60.000", "60,000", "3.423.500", "12k", "1,5 nghìn" -> số nguyên; không phải số thì null. */
    public static function volume(string $v): ?int
    {
        $t = preg_replace('/\s+/u', '', mb_strtolower(trim($v))) ?? '';
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(\d+(?:[.,]\d+)?)(k|nghìn|ngàn)$/u', $t, $m)) {
            $n = (int) round((float) str_replace(',', '.', $m[1]) * 1000);
        } elseif (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $t)) {
            $n = (int) preg_replace('/[.,]/', '', $t);
        } elseif (preg_match('/^\d+(?:[.,]\d+)?$/', $t)) {
            $n = (int) round((float) str_replace(',', '.', $t));
        } else {
            return null;
        }

        return $n >= 0 && $n <= 2_000_000_000 ? $n : null;
    }

    /** @return list<string> */
    public static function splitList(string $v): array
    {
        $parts = preg_split('/[;,\n|]+/u', $v) ?: [];

        return array_values(array_unique(array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== '' && mb_strlen($p) <= 120)));
    }

    /** Chuỗi một dòng, không thẻ HTML, không ký tự điều khiển. */
    public static function text(string $v, int $max = 255): string
    {
        $t = preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', strip_tags($v)) ?? '';

        return mb_substr(trim($t), 0, $max);
    }
}
