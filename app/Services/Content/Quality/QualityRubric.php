<?php

namespace App\Services\Content\Quality;

/**
 * AG-K03: rubric của bộ kiểm tra chất lượng DƯỚI DẠNG DỮ LIỆU có nguồn gốc: mỗi luật ghi ngưỡng đang dùng
 * (đọc TRỰC TIẾP từ QualityThresholds, không chép số), cơ sở, lý do, trạng thái xác minh và ngày rà soát.
 *
 * CHỈ THÊM METADATA: không đổi mã luật, ngưỡng hay kết quả của ContentQualityChecker. Mục đích: mọi ngưỡng đều
 * truy vết được "vì sao số này", và ngưỡng chưa xác minh không giả làm quy tắc của Google.
 * Trạng thái xác minh theo docs/seo-agent/knowledge/RULE_VERIFICATION_2026-11-07.md (AG-K01).
 *
 * verification: verified | partial | unverified | internal (quy tắc nội bộ/bảo mật, không cần nguồn Google).
 */
final class QualityRubric
{
    public const VERIFICATION = ['verified', 'partial', 'unverified', 'internal'];

    public const REVIEWED_AT = '2026-11-07';

    /**
     * @return array<string, array{
     *   code: string, name: string, what: string, thresholds: array<string, int|float|string>,
     *   basis: string, rationale: string, verification: string, source_urls: list<string>,
     *   reviewed_at: string, caveat: string
     * }>
     */
    public static function all(): array
    {
        $g = 'https://developers.google.com/search/docs/';

        return [
            'LEN01' => self::entry('LEN01', 'Độ dài bài', 'Số từ tối thiểu; dưới ngưỡng chặn là lỗi nặng (P0), dưới mức tối thiểu là P1.', [
                'min_words' => QualityThresholds::MIN_WORDS, 'blocking_words' => QualityThresholds::BLOCKING_WORDS,
            ], 'heuristic', 'Kinh nghiệm: bài quá ngắn thường chưa bao phủ đủ chủ đề. Tiếng Việt đếm theo âm tiết.', 'unverified', [],
                'Chưa tra phát biểu của Google về số từ; chưa hiệu chỉnh trên dữ liệu thật của công ty. Số từ là sàn bao phủ, không phải mục tiêu.'),
            'HEAD01' => self::entry('HEAD01', 'Cấu trúc heading', 'Tối đa một H1, tối thiểu số H2, không nhảy cấp.', [
                'max_h1' => QualityThresholds::MAX_H1, 'min_h2' => QualityThresholds::MIN_H2,
            ], 'heuristic', 'Heading rõ ràng giúp người đọc và công cụ hiểu cấu trúc bài.', 'unverified', [],
                'Quy ước biên tập phổ biến; chưa đối chiếu nguồn chính thức.'),
            'KW01' => self::entry('KW01', 'Từ khóa trong bài', 'Từ khóa chính có trong tiêu đề SEO, heading và đoạn đầu; cảnh báo khi lặp quá nhiều.', [
                'lead_words' => QualityThresholds::KEYWORD_LEAD_WORDS, 'max_density_percent' => QualityThresholds::MAX_KEYWORD_DENSITY_PERCENT,
            ], 'heuristic', 'Phát hiện nhồi từ khóa bằng ngưỡng mật độ.', 'partial', [$g.'essentials/spam-policies'],
                'Google mô tả keyword stuffing ĐỊNH TÍNH (lặp không tự nhiên), không công bố tỉ lệ %. Ngưỡng mật độ chỉ là heuristic nội bộ, không phải quy tắc của Google; không nên dùng làm điều kiện chặn cứng.'),
            'META01' => self::entry('META01', 'Tiêu đề SEO, meta description, slug', 'Độ dài tiêu đề SEO/meta và định dạng slug.', [
                'max_seo_title_chars' => QualityThresholds::MAX_SEO_TITLE_CHARS, 'min_meta_chars' => QualityThresholds::MIN_META_CHARS,
                'max_meta_chars' => QualityThresholds::MAX_META_CHARS, 'slug_pattern' => QualityThresholds::SLUG_PATTERN,
            ], 'heuristic', 'Giữ tiêu đề/mô tả vừa đủ để không bị cắt khi hiển thị.', 'verified', [$g.'appearance/snippet', $g.'advanced/appearance/good-titles-snippets'],
                'Google nêu KHÔNG có giới hạn cứng độ dài; kết quả chỉ bị cắt theo chiều rộng thiết bị. Các con số là khuyến nghị hiển thị, không phải luật.'),
            'DUP01' => self::entry('DUP01', 'Trùng lặp nội dung nội bộ', 'So sánh cụm từ với các bài khác của cùng website.', [
                'shingle_size' => QualityThresholds::SHINGLE_SIZE, 'warning_ratio' => QualityThresholds::DUP_WARNING,
                'blocking_ratio' => QualityThresholds::DUP_BLOCKING, 'max_compared' => QualityThresholds::DUP_MAX_COMPARED,
            ], 'internal', 'Tránh đăng nội dung gần giống bài đã có trên cùng website.', 'internal', [],
                'Ngưỡng 30%/50% là lựa chọn nội bộ; chưa hiệu chỉnh trên dữ liệu thật. Chưa kiểm trùng lặp với internet (task 6.1).'),
            'IMG01' => self::entry('IMG01', 'Alt của ảnh', 'Ảnh trong bài cần có mô tả thay thế.', [], 'internal',
                'Khả năng tiếp cận và SEO hình ảnh.', 'internal', [], 'Quy tắc nội bộ.'),
            'LINK01' => self::entry('LINK01', 'Liên kết thuộc website', 'Liên kết trong bài phải trỏ tới trang đã quét của chính website; liên kết ra domain lạ hoặc URL nội bộ không có trong danh sách bị cờ (P1); có trang liên quan mà bài không có liên kết nội bộ thì gợi ý (P2).', [], 'internal',
                'Tránh URL bịa/chết và dẫn người đọc ra ngoài ngoài ý muốn; liên kết nội bộ giúp người đọc đi tiếp.', 'internal', [],
                'Chưa xác minh với Google; đây là thực hành tốt, không có ngưỡng chính thức. Không đặt số liên kết tối thiểu/tối đa cứng; mức "tối đa 3 liên kết" chỉ là chỉ dẫn cho người viết AI.'),
            'SAFE01' => self::entry('SAFE01', 'Nội dung nguy hiểm', 'Chặn mã/nội dung có thể gây XSS hoặc thực thi.', [], 'internal',
                'Bảo mật: nội dung AI/khách không được mang mã độc.', 'internal', [], 'Quy tắc bảo mật nội bộ (AGENTS.md §1).'),
            'FACT01' => self::entry('FACT01', 'Số liệu và nguồn', 'Số liệu cần có nguồn; thay đổi số liệu so với bản đã duyệt bị chặn.', [], 'internal',
                'Tránh số liệu bịa hoặc bị sửa lặng lẽ giữa các phiên bản.', 'internal', [], 'Quy tắc nội bộ; chưa kiểm chứng sự kiện với nguồn ngoài (task 6.1).'),
            'BAN01' => self::entry('BAN01', 'Từ bị cấm', 'Bài chứa từ/cụm bị cấm theo cài đặt website hoặc chiến dịch thì bị chặn (P0).', [], 'internal',
                'Quy định thương hiệu và pháp lý của chủ website phải được kiểm bằng luật, không chỉ dặn trong prompt.', 'internal', [],
                'Quy tắc nội bộ của chủ website, không phải quy tắc của Google. Danh sách mặc định để trống; nối cài đặt theo website ở AG-K11.'),
            'KB01' => self::entry('KB01', 'Số liệu có nguồn trong kho', 'So số liệu (tiền, %, năm, thời gian) trong bài với mục kiến thức ĐÃ DUYỆT cùng thực thể + trường của website; khác giá trị thì cờ P1 kèm [KB#id] và giá trị đúng; số không có mục nào để đối chiếu chỉ ghi nhận mức thông tin.', [], 'internal',
                'Số liệu sai về giá/chính sách làm hại uy tín và pháp lý; kho đã được người duyệt là nguồn đối chiếu của công ty.', 'internal', [],
                'Quy tắc nội bộ, tất định, không dùng AI. Chỉ là P1 (không chặn): đối chiếu theo câu có nhắc cả thực thể lẫn trường nên có thể bỏ sót hoặc báo nhầm khi văn phong khác; không kiểm chứng sự kiện ngoài kho.'),
            'FAQ01' => self::entry('FAQ01', 'FAQ', 'Gợi ý (P2) khi bài có/không có phần hỏi–đáp; không bắt buộc.', [], 'heuristic',
                'Phần hỏi–đáp có thể giúp người đọc.', 'partial', ['https://developers.google.com/search/blog/2023/08/howto-faq-changes', 'https://developers.google.com/search/updates'],
                'FAQ rich result đã bị giới hạn từ 08/2023; changelog ghi đã ngừng hiển thị năm 2026 (mốc ngày cần mở trang gốc để chốt). Không nên kỳ vọng rich result từ FAQ schema và không ép bài phải có FAQ.'),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function for(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    /** @return list<string> mã luật chưa được xác minh (để giao diện/tài liệu cảnh báo, không giả là chuẩn Google). */
    public static function notVerified(): array
    {
        return array_keys(array_filter(self::all(), fn (array $e) => in_array($e['verification'], ['unverified', 'partial'], true)));
    }

    private static function entry(string $code, string $name, string $what, array $thresholds, string $basis, string $rationale, string $verification, array $sources, string $caveat): array
    {
        return [
            'code' => $code, 'name' => $name, 'what' => $what, 'thresholds' => $thresholds, 'basis' => $basis,
            'rationale' => $rationale, 'verification' => $verification, 'source_urls' => $sources,
            'reviewed_at' => self::REVIEWED_AT, 'caveat' => $caveat,
        ];
    }
}
