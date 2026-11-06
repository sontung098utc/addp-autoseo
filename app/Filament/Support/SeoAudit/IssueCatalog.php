<?php

namespace App\Filament\Support\SeoAudit;

/**
 * Bảng loại lỗi on-page và mức P0/P1/P2 (CỐ ĐỊNH, do hệ thống gán, người dùng không chỉnh).
 * Bảng này là luật nghiệp vụ THẬT; chủ dự án/Dev1 có thể điều chỉnh sau.
 */
final class IssueCatalog
{
    public const P0 = 'P0';

    public const P1 = 'P1';

    public const P2 = 'P2';

    public const MIN_TITLE = 30;

    public const MAX_TITLE = 60;

    public const MIN_WORDS = 300;

    /** @var array<string, array{label: string, sub: string, severity: string}> */
    private const TYPES = [
        'noindex_in_sitemap' => ['label' => 'Trang bị noindex nhầm', 'sub' => 'meta robots noindex trên trang có trong sitemap', 'severity' => self::P0],
        'broken_link' => ['label' => 'Liên kết gãy (404)', 'sub' => 'liên kết nội bộ trỏ tới URL trả 404', 'severity' => self::P0],
        'missing_title' => ['label' => 'Thiếu title', 'sub' => 'không có thẻ title hoặc rỗng', 'severity' => self::P0],
        'missing_meta' => ['label' => 'Thiếu meta description', 'sub' => 'không có hoặc rỗng', 'severity' => self::P1],
        'missing_canonical' => ['label' => 'Thiếu canonical', 'sub' => 'không có link rel=canonical', 'severity' => self::P1],
        'duplicate_meta' => ['label' => 'Trùng meta description', 'sub' => 'cùng nội dung với trang khác', 'severity' => self::P1],
        'duplicate_title' => ['label' => 'Trùng title', 'sub' => 'cùng nội dung với trang khác', 'severity' => self::P1],
        'thin_page' => ['label' => 'Trang mỏng (dưới 300 từ)', 'sub' => 'ít nội dung văn bản', 'severity' => self::P1],
        'missing_h1' => ['label' => 'Thiếu H1', 'sub' => 'không có thẻ h1', 'severity' => self::P1],
        'image_no_alt' => ['label' => 'Ảnh thiếu alt', 'sub' => 'thẻ img không có thuộc tính alt', 'severity' => self::P2],
        'title_long' => ['label' => 'Title quá dài (trên 60 ký tự)', 'sub' => 'có thể bị cắt trên kết quả tìm kiếm', 'severity' => self::P2],
        'title_short' => ['label' => 'Title quá ngắn (dưới 30 ký tự)', 'sub' => 'chưa tận dụng được không gian title', 'severity' => self::P2],
        'multiple_h1' => ['label' => 'Nhiều H1', 'sub' => 'hơn một thẻ h1 trên một trang', 'severity' => self::P2],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    public static function exists(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public static function severity(string $type): string
    {
        return self::TYPES[$type]['severity'];
    }

    public static function label(string $type): string
    {
        return self::TYPES[$type]['label'];
    }

    public static function sub(string $type): string
    {
        return self::TYPES[$type]['sub'];
    }

    /** Thứ tự sắp xếp: P0 trước. */
    public static function rank(string $severity): int
    {
        return ['P0' => 0, 'P1' => 1, 'P2' => 2][$severity] ?? 9;
    }

    /**
     * "AI đề xuất" cách sửa: MẪU VĂN BẢN theo loại lỗi, điền bằng URL/bằng chứng của nhóm. KHÔNG gọi AI thật,
     * không mở URL nào. Người dùng phải kiểm lại trước khi sửa.
     *
     * @param  list<array{url: string, measured: string, evidence: string}>  $items
     * @return list<string>
     */
    public static function fixSteps(string $type, array $items): array
    {
        $n = count(array_unique(array_column($items, 'url')));
        $first = $items[0]['url'] ?? '';

        return match ($type) {
            'broken_link' => self::brokenLinkSteps($items),
            'noindex_in_sitemap' => [
                "Mở từng trang trong {$n} trang và kiểm tra thẻ meta robots: nếu trang cần lên Google thì bỏ noindex.",
                'Kiểm tra plugin SEO/theme có đang đặt noindex hàng loạt theo mẫu trang hay không (ví dụ ở '.$first.').',
                'Nếu trang cố ý ẩn khỏi Google, gỡ nó khỏi sitemap để hai bên nhất quán.',
            ],
            'missing_title' => [
                "Viết title riêng 30–60 ký tự cho {$n} trang, có từ khóa chính của trang (bắt đầu từ ".$first.').',
                'Kiểm tra template có in thẻ title hay không; nếu nhiều trang cùng thiếu thì lỗi nằm ở template.',
            ],
            'missing_meta' => [
                "Viết meta description riêng 120–160 ký tự cho {$n} trang, nêu lợi ích và có từ khóa chính.",
                'Không sao chép nội dung giữa các trang; mô tả trùng nhau cũng là lỗi.',
            ],
            'duplicate_meta' => [
                'Mỗi nhóm trang trùng meta cần mô tả riêng; bắt đầu từ trang quan trọng nhất của nhóm.',
                'Nếu meta sinh tự động từ template, thêm tên chủ đề/ngành của trang vào mẫu.',
            ],
            'duplicate_title' => [
                'Sửa title để mỗi trang có một title riêng; thêm chủ đề cụ thể của trang vào trước tên trường.',
                'Nếu hai trang thật sự cùng nội dung, cân nhắc gộp hoặc đặt canonical về trang chính.',
            ],
            'missing_canonical' => [
                "Thêm link rel=canonical trỏ về chính URL sạch của trang cho {$n} trang.",
                'Kiểm tra template/plugin SEO có tùy chọn canonical tự động.',
            ],
            'thin_page' => [
                "Rà {$n} trang: bổ sung nội dung hữu ích (điều kiện, thời hạn, câu hỏi thường gặp) hoặc gộp vào trang liên quan.",
                'Trang không còn giá trị thì cân nhắc 301 về trang gần nghĩa nhất, không để trang mỏng tồn tại.',
            ],
            'missing_h1' => [
                "Thêm một thẻ h1 mô tả đúng chủ đề cho {$n} trang.",
                'Kiểm tra template: tiêu đề bài đang dùng thẻ nào (div/h2?) thay vì h1.',
            ],
            'multiple_h1' => [
                'Giữ một h1 duy nhất cho tiêu đề chính, đổi các h1 còn lại thành h2/h3.',
                'Kiểm tra template/widget có tự chèn thêm h1 hay không.',
            ],
            'image_no_alt' => [
                "Thêm thuộc tính alt mô tả nội dung ảnh cho {$n} trang; ảnh trang trí dùng alt=\"\".",
                'Không nhồi từ khóa vào alt; mô tả ngắn, đúng nội dung ảnh.',
            ],
            'title_long' => [
                'Rút title còn tối đa 60 ký tự, giữ từ khóa chính ở đầu.',
            ],
            'title_short' => [
                'Mở rộng title tới ít nhất 30 ký tự bằng chủ đề cụ thể hoặc lợi ích của trang.',
            ],
            default => [],
        };
    }

    /**
     * @param  list<array{url: string, measured: string, evidence: string}>  $items
     * @return list<string>
     */
    private static function brokenLinkSteps(array $items): array
    {
        $targets = [];
        foreach ($items as $it) {
            $targets[$it['measured']] = ($targets[$it['measured']] ?? 0) + 1;
        }
        $dirs = [];
        foreach (array_keys($targets) as $t) {
            $d = rtrim(dirname((string) parse_url($t, PHP_URL_PATH)), '/');
            $dirs[$d] = ($dirs[$d] ?? 0) + 1;
        }
        arsort($dirs);
        $steps = [];
        $topDir = array_key_first($dirs);
        if ($topDir !== null && $dirs[$topDir] > 1) {
            $steps[] = "{$dirs[$topDir]} đích 404 cùng nằm trong thư mục {$topDir}/: kiểm tra thư mục đã đổi tên hay tệp bị xóa, rồi đặt chuyển hướng 301 hàng loạt nếu tệp còn.";
        }
        $steps[] = 'Với liên kết trỏ vào đường dẫn cũ, sửa liên kết trong bài thành đường dẫn hiện hành.';
        $steps[] = 'Với tệp/trang không còn, gỡ liên kết thay vì để trỏ vào trang 404.';

        return $steps;
    }
}
