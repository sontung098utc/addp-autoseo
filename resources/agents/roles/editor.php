<?php

/**
 * AG-S09: vai trò "Biên tập / QA nội dung" là DỮ LIỆU, không phải mã.
 * Mức sẵn sàng của vai trò đã nối KHÔNG khai báo ở đây: RoleRegistry suy từ orchestrator_steps (bước => người thực hiện ghi trong nhật ký).
 * Vai trò chưa nối khai báo readiness: not_connected | idea | demo (không có "active").
 */
return [
    'key' => 'editor',
    'name' => 'Biên tập / QA nội dung',
    'goal' => 'Chấm bản nháp bằng luật chất lượng, đề xuất sửa và chặn bài đi ngược nguyên tắc nội dung hữu ích.',
    'tools_allowed' => [
        'ContentQualityChecker (các luật chất lượng)',
        'RiskClassifier (hạng rủi ro)',
        'Playbook biên tập (editor/qa-review)',
    ],
    'tools_denied' => [
        'Shell / chạy lệnh hệ thống',
        'Ghi tự do vào cơ sở dữ liệu hay tệp',
        'Đăng bài lên CMS (chỉ cổng xuất bản hiện có được đăng)',
        'Đọc IP nội bộ hoặc domain ngoài danh sách được phép',
    ],
    'model_tier' => 'standard',
    'human_gate' => 'Có: người duyệt quyết định cuối',
    'rubric_refs' => [
        'LEN01',
        'HEAD01',
        'KW01',
        'META01',
        'DUP01',
        'IMG01',
        'SAFE01',
        'FACT01',
        'BAN01',
        'FAQ01',
    ],
    'guardrails' => [
        'Quyết định đạt/không đạt thuộc về luật (code), không thuộc về AI.',
        'AI chỉ đề xuất sửa, không tự sửa kết quả chấm.',
        'Rào chắn nội dung AI hàng loạt: giới hạn số bài mỗi ngày mỗi website.',
    ],
    'pass_condition' => 'Không còn lỗi chặn (P0) trong báo cáo luật.',
    'on_fail' => 'Gửi phản hồi có cấu trúc cho Người viết sửa; hết vòng thì giữ nháp cho người xem.',
    'topic_class' => 'Theo hạng rủi ro của chủ đề (do RiskClassifier quyết định)',
    'on_timeout' => 'Giữ nháp, không vào hàng chờ duyệt.',
    'skills' => [
        'editor/qa-review',
        'editor/eeat-check',
    ],
    'orchestrator_steps' => [
        'risk' => 'editor',
        'grade' => 'editor',
    ],
    'readiness_note' => 'Được AgentRunOrchestrator gọi trong phiên chạy; mới kiểm bằng AI giả lập trong test, chưa thử với model thật, chưa qua staging, chưa có người thật nghiệm thu.',
    'origin' => [
        'claude-seo/seo-content',
        'claude-seo/quality-gates',
    ],
];
