<?php

namespace App\Services\Brain;

/**
 * AG-B1: sổ mẫu trí tuệ (resources/brains/templates/*.php). Mẫu chỉ mang CẤU TRÚC và QUY TẮC, tuyệt đối không mang sự kiện/số liệu/tên sản phẩm
 * (thuật ngữ để trống để người dùng hoặc chuyên gia điền). Thứ tự hiển thị cố định.
 */
final class BrainTemplates
{
    /** AG-B2: 14 mẫu sức khỏe (cùng y_khoa_duoc cũ tạo thành nhóm "Sức khỏe"). */
    public const HEALTH_KEYS = [
        'y_khoa_tong_quat', 'dai_thao_duong', 'tim_mach_huyet_ap', 'dinh_duong', 'duoc_thuoc', 'thuc_pham_chuc_nang', 'suc_khoe_phu_nu',
        'nhi_khoa', 'tam_than_giac_ngu', 'y_hoc_co_truyen', 'da_lieu', 'xuong_khop', 'tieu_hoa_gan_mat', 'ho_hap_di_ung',
    ];

    public const ORDER = [
        'thuong_hieu', 'y_khoa_duoc',
        'y_khoa_tong_quat', 'dai_thao_duong', 'tim_mach_huyet_ap', 'dinh_duong', 'duoc_thuoc', 'thuc_pham_chuc_nang', 'suc_khoe_phu_nu',
        'nhi_khoa', 'tam_than_giac_ngu', 'y_hoc_co_truyen', 'da_lieu', 'xuong_khop', 'tieu_hoa_gan_mat', 'ho_hap_di_ung',
        'giao_duc', 'ngoai_ngu', 'khoa_hoc', 'san_pham', 'trong',
    ];

    public const GROUPS = ['suc_khoe' => 'Sức khỏe', 'thuong_hieu' => 'Thương hiệu', 'khac' => 'Khác'];

    public const SOURCE_LABEL = 'chưa xác nhận, cần chuyên gia duyệt';

    /** Danh sách tên miền ĐÃ RÀ được phép gợi ý (không thêm tên miền khác). Giá trị = tên tổ chức. */
    public const ALLOWED_SOURCES = [
        'who.int' => 'Tổ chức Y tế Thế giới (WHO)',
        'moh.gov.vn' => 'Bộ Y tế Việt Nam',
        'dav.gov.vn' => 'Cục Quản lý Dược (Bộ Y tế Việt Nam)',
        'nin.gov.vn' => 'Viện Dinh dưỡng Quốc gia (Việt Nam)',
        'pubmed.ncbi.nlm.nih.gov' => 'PubMed (cơ sở dữ liệu tài liệu y sinh)',
        'cochranelibrary.com' => 'Thư viện Cochrane',
        'nih.gov' => 'Viện Y tế Quốc gia Hoa Kỳ (NIH)',
        'niddk.nih.gov' => 'NIDDK (thuộc NIH Hoa Kỳ)',
        'cdc.gov' => 'Trung tâm Kiểm soát và Phòng ngừa Dịch bệnh Hoa Kỳ (CDC)',
        'nhs.uk' => 'Dịch vụ Y tế Quốc gia Anh (NHS)',
        'diabetes.org' => 'Hiệp hội Đái tháo đường Hoa Kỳ',
        'idf.org' => 'Liên đoàn Đái tháo đường Quốc tế',
        'heart.org' => 'Hiệp hội Tim mạch Hoa Kỳ',
        'escardio.org' => 'Hiệp hội Tim mạch Châu Âu',
    ];

    /**
     * Bố cục khối mặc định cho bài sức khỏe (nháp, chờ chuyên gia). Là gợi ý "khối nào nên có" cho người viết, không phải nội dung y khoa.
     * Quy tắc số lượng nằm ở kỹ năng writer/article-layout (tối đa 2 khung lưu ý).
     */
    public const HEALTH_LAYOUT = [
        'Một khung lưu ý ngắn «Khi nào nên gặp bác sĩ» gần cuối bài; dấu hiệu chỉ lấy từ kiến thức đã duyệt',
        'Bảng tham chiếu khi bài nêu nhiều mốc/chỉ số; số liệu chỉ từ kiến thức đã duyệt, kèm chú thích nguồn',
        'Cảnh báo an toàn (biến chứng, thuốc, đối tượng cần thận trọng) trong tối đa một khung lưu ý loại warning',
        'Câu lưu ý thông tin chỉ mang tính tham khảo ở cuối bài',
    ];

    /** Quy tắc lập luận an toàn CHUNG cho mọi lĩnh vực sức khỏe/thuốc. Mẫu cộng thêm quy tắc riêng. */
    public const HEALTH_RULES = [
        'Không chẩn đoán bệnh cho người đọc và không kê đơn, không nêu liều lượng',
        'Nêu rõ giới hạn của thông tin: bài chỉ là thông tin chung, không thay thế tư vấn của nhân viên y tế',
        'Khuyến nghị gặp nhân viên y tế khi có dấu hiệu bất thường, kéo dài hoặc nặng lên',
        'Phân biệt thông tin chung với tư vấn cho từng cá nhân; không đưa lời khuyên áp dụng cho mọi người',
        'Không so sánh thuốc hay sản phẩm theo kiểu quảng cáo, không dùng lời hứa hẹn kết quả',
        'Mọi khẳng định có thể kiểm chứng phải có nguồn do chuyên gia duyệt; chưa có nguồn thì bỏ khẳng định đó',
        'Phân biệt rõ điều đã có bằng chứng với điều còn tranh luận hoặc chưa đủ bằng chứng',
    ];

    /** Giọng văn chung cho truyền thông sức khỏe. */
    public const HEALTH_DO = ['Đồng cảm, tôn trọng người đang lo lắng về sức khỏe', 'Dựa trên bằng chứng, nêu nguồn khi khẳng định', 'Giải thích thuật ngữ ngay lần đầu xuất hiện bằng lời dễ hiểu'];

    public const HEALTH_DONT = ['Gây hoang mang hay dọa người đọc', 'Hứa hẹn kết quả hoặc dùng từ tuyệt đối', 'Chẩn đoán hay kê đơn thay nhân viên y tế'];

    /**
     * Dựng mẫu sức khỏe từ đặc tả gọn. Luôn must_cite + high (lĩnh vực YMYL); chỉ mang cấu trúc, tên thuật ngữ (định nghĩa để trống) và gợi ý nguồn chưa xác nhận.
     *
     * @param array<string,mixed> $o name, description, domain_key, reliance_reason, topics, types (key => [name, outline]), terms, rules, do, dont, rubric, sources (tên miền), hints
     * @return array<string,mixed>
     */
    public static function health(array $o): array
    {
        $sources = [];
        foreach ((array) $o['sources'] as $domain) {
            if (isset(self::ALLOWED_SOURCES[$domain])) {
                $sources[] = ['org' => self::ALLOWED_SOURCES[$domain], 'domain' => $domain, 'status' => self::SOURCE_LABEL];
            }
        }
        $types = [];
        foreach ((array) $o['types'] as $key => [$name, $outline]) {
            $types[] = ['key' => $key, 'name' => $name, 'outline' => $outline, 'layout' => self::HEALTH_LAYOUT];
        }

        return [
            'name' => $o['name'], 'group' => 'suc_khoe', 'description' => $o['description'], 'domain_key' => $o['domain_key'],
            'ai_reliance' => 'must_cite', 'risk_class' => 'high', 'reliance_reason' => $o['reliance_reason'],
            'priority_types' => ['fact', 'faq', 'policy'],
            'topics' => $o['topics'],
            'profile' => [
                'voice' => ['tone' => '', 'formality' => '', 'pronouns' => '', 'do' => array_merge(self::HEALTH_DO, (array) ($o['do'] ?? [])), 'dont' => array_merge(self::HEALTH_DONT, (array) ($o['dont'] ?? []))],
                'structure' => ['article_types' => $types],
                'terminology' => ['preferred' => array_map(fn ($t) => ['term' => $t, 'definition' => '', 'avoid' => []], (array) $o['terms']), 'abbreviations' => []],
                'reasoning' => ['rules' => array_merge(self::HEALTH_RULES, (array) $o['rules'])],
                'rubric_notes' => implode("\n", (array) $o['rubric']),
                'exemplars' => [],
            ],
            'suggested_sources' => $sources,
            'fill_hints' => array_merge(['Chuyên gia điền định nghĩa cho từng thuật ngữ (hiện để trống)', 'Chuyên gia xác nhận nguồn gợi ý trước khi dùng'], (array) ($o['hints'] ?? [])),
        ];
    }

    /** Nhóm hiển thị của một mẫu: suc_khoe | thuong_hieu | khac. */
    public static function groupOf(?string $key): string
    {
        $t = self::get($key);
        if ($t && isset($t['group'])) {
            return (string) $t['group'];
        }

        return in_array($key, ['thuong_hieu', 'san_pham'], true) ? 'thuong_hieu' : 'khac';
    }

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string,array<string,mixed>> khóa mẫu => định nghĩa (đã thêm 'key') */
    public static function all(): array
    {
        if (self::$cache === null) {
            $out = [];
            foreach (self::ORDER as $key) {
                $file = resource_path('brains/templates/'.$key.'.php');
                if (is_file($file)) {
                    $out[$key] = ['key' => $key] + (array) require $file;
                }
            }
            self::$cache = $out;
        }

        return self::$cache;
    }

    /** @return array<string,mixed>|null */
    public static function get(?string $key): ?array
    {
        return $key === null ? null : (self::all()[$key] ?? null);
    }

    /** @return array<string,string> khóa => tên, cho ô chọn */
    public static function options(): array
    {
        return array_map(fn ($t) => (string) $t['name'], self::all());
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
