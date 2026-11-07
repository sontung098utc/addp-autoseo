<?php

namespace App\Filament\Support\Agent;

/**
 * AG-S01: "bản thiết kế sống" của luồng đội AI. Mỗi chặng ghi: ai làm, mức sẵn sàng THẬT, điểm nối Dev1 cần xây.
 * Dùng chung cho trang chi tiết phiên chạy và trang "Cách hoạt động", nên sửa ở đây là cả hai cùng đổi.
 * readiness dùng cùng từ vựng với FeatureRegistry (active|demo|not_connected|in_development|idea).
 */
final class AgentFlowBlueprint
{
    /** @return list<array{stage: string, title: string, role: string, readiness: string, what: string, built: string, dev1: string, task: string}> */
    public static function stages(): array
    {
        return [
            ['stage' => 'guard', 'title' => 'An toàn', 'role' => 'Hệ thống', 'readiness' => 'in_development',
                'what' => 'Kiểm dừng khẩn cấp và website hoạt động trước khi tốn chi phí.',
                'built' => 'Đã nối EmergencyStopService và trạng thái website.',
                'dev1' => 'Dừng khẩn cấp đang lưu trong Cache, mất khi xóa cache: cần bền hóa (AG-B08).', 'task' => 'AG-B08'],
            ['stage' => 'risk', 'title' => 'Phân hạng rủi ro', 'role' => 'Biên tập', 'readiness' => 'in_development',
                'what' => 'Chủ đề sức khỏe, tài chính, pháp lý được gắn hạng cao, cần người có chuyên môn.',
                'built' => 'RiskClassifier bằng luật (AG-K12), có test.',
                'dev1' => 'Danh sách nguồn được duyệt theo website cần bảng cấu hình (migration).', 'task' => 'AG-K12'],
            ['stage' => 'brief', 'title' => 'Dàn ý', 'role' => 'Chiến lược', 'readiness' => 'in_development',
                'what' => 'Lập dàn ý từ từ khóa và ý định tìm kiếm.',
                'built' => 'Dùng generateOutline hiện có và nạp playbook brief (AG-K05) cho lượt thủ công; lượt tự động chỉ dùng playbook đã active.',
                'dev1' => 'Playbook còn là bản nháp vì chưa chạy bộ mẫu với model thật; cần AiModelRouter chọn model theo loại việc (AG-B06).', 'task' => 'AG-B06'],
            ['stage' => 'write', 'title' => 'Viết', 'role' => 'Người viết', 'readiness' => 'in_development',
                'what' => 'Viết bài theo dàn ý; HTML luôn qua bộ làm sạch XSS.',
                'built' => 'Dùng generateArticle hiện có; Gemini nay nhận playbook và danh sách lỗi chặn khi sửa; phát hiện khi nhà cung cấp rơi về giả lập.',
                'dev1' => 'Nhà cung cấp vẫn chọn cứng trong mã và vẫn tự rơi về Mock khi thiếu khóa (hệ thống chỉ gắn nhãn, chưa chặn): cần bỏ hẳn (AG-B06).', 'task' => 'AG-B06'],
            ['stage' => 'retry', 'title' => 'Thử lại khi nhà cung cấp lỗi', 'role' => 'Hệ thống', 'readiness' => 'in_development',
                'what' => 'Lỗi tạm thời (timeout, mất kết nối, 5xx, 429) được thử lại tối đa 3 lần có chờ tăng dần; hết lần thì phiên báo lỗi.',
                'built' => 'Có trong bộ điều phối, mỗi lần thử ghi một bước; không bao giờ thay bằng AI giả lập. Mới được kiểm bằng test giả lập, chưa gặp lỗi nhà cung cấp thật.',
                'dev1' => 'Chạy đồng bộ nên đang ngủ trong tiến trình; chuyển sang thử lại của hàng đợi (AG-B10) và model dự phòng thật (AG-B06).', 'task' => 'AG-B10'],
            ['stage' => 'grade', 'title' => 'Chấm bằng luật', 'role' => 'Biên tập', 'readiness' => 'active',
                'what' => 'ContentQualityChecker quyết định đạt hay không, AI không được tự chấm.',
                'built' => '10 luật có sẵn (gồm từ cấm BAN01), đã chạy thật trong hệ thống.',
                'dev1' => 'Không cần thêm; chỉ nối cài đặt từ cấm theo website sau khi có hồ sơ doanh nghiệp (AG-K11).', 'task' => 'AG-K11'],
            ['stage' => 'repair', 'title' => 'Tự sửa (tối đa 3 vòng)', 'role' => 'Người viết', 'readiness' => 'in_development',
                'what' => 'Gửi lỗi chặn cho AI sửa; dừng khi hết vòng, hết ngân sách hoặc không cải thiện.',
                'built' => 'Vòng lặp tất định có giới hạn, có test.',
                'dev1' => 'Ngân sách và ngắt mạch thật theo ngày/tháng (AG-B09); thử với model thật để đo hội tụ (AG-K09).', 'task' => 'AG-B09'],
            ['stage' => 'decision', 'title' => 'Quyết định tự động', 'role' => 'Hệ thống', 'readiness' => 'in_development',
                'what' => 'Ghi lại mỗi lần hệ thống tự quyết (dừng sửa vì không cải thiện, hết ngân sách, hết vòng, giữ cho chuyên gia) kèm dữ kiện trước/sau.',
                'built' => 'Có trong bộ điều phối; điểm 0-100 chỉ để theo dõi, quyết định đạt hay không vẫn do luật P0.',
                'dev1' => 'Ngân sách hiện là giá trị mặc định trong RunPolicy, cần nối cài đặt thật theo ngày/tháng (AG-B09).', 'task' => 'AG-B09'],
            ['stage' => 'handoff', 'title' => 'Bàn giao nháp', 'role' => 'Hệ thống', 'readiness' => 'in_development',
                'what' => 'Bậc T1: chỉ tạo bản nháp chờ người duyệt, không bao giờ tự xuất bản.',
                'built' => 'Tạo ArticleVersion và chuyển hàng chờ duyệt; không đụng bảng xuất bản.',
                'dev1' => 'Nhịp chiến dịch đang chạy bằng lệnh theo lịch, cần thay bằng hàng đợi (AG-B10); chính sách tin cậy T1 → T2 và đẩy nháp lên CMS (AG-A05a).', 'task' => 'AG-B10'],
        ];
    }

    /** @return array<string, array<string, mixed>> theo mã chặng */
    public static function byStage(): array
    {
        $out = [];
        foreach (self::stages() as $s) {
            $out[$s['stage']] = $s;
        }

        return $out;
    }
}
