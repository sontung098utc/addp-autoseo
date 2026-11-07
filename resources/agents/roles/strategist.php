<?php

/**
 * AG-S09: vai trò "Chiến lược nội dung" là DỮ LIỆU, không phải mã.
 * Mức sẵn sàng của vai trò đã nối KHÔNG khai báo ở đây: RoleRegistry suy từ orchestrator_steps (bước => người thực hiện ghi trong nhật ký).
 * Vai trò chưa nối khai báo readiness: not_connected | idea | demo (không có "active").
 */
return [
    'key' => 'strategist',
    'name' => 'Chiến lược nội dung',
    'goal' => 'Lập dàn ý và kế hoạch nội dung hợp với ý định tìm kiếm và đúng chủ đề của website.',
    'tools_allowed' => [
        'Hồ sơ doanh nghiệp',
        'Từ khóa chính của bài',
        'Playbook dàn ý (brief/content-brief)',
    ],
    'tools_denied' => [
        'Shell / chạy lệnh hệ thống',
        'Ghi tự do vào cơ sở dữ liệu hay tệp',
        'Đăng bài lên CMS (chỉ cổng xuất bản hiện có được đăng)',
        'Đọc IP nội bộ hoặc domain ngoài danh sách được phép',
    ],
    'model_tier' => 'reasoning',
    'human_gate' => 'Không (bản nháp vẫn qua người duyệt)',
    'rubric_refs' => [
        'HEAD01',
        'KW01',
    ],
    'guardrails' => [
        'Chủ đề phải liên quan website (Website Relevance Rule).',
        'Không đưa số liệu không có nguồn vào dàn ý.',
        'Kết quả chỉ là dữ liệu có cấu trúc (JSON), không điều khiển công cụ.',
    ],
    'pass_condition' => 'Dàn ý có tiêu đề, các mục H2 theo ý định tìm kiếm.',
    'on_fail' => 'Hiện chưa có vòng sửa riêng cho dàn ý; lỗi AI tạm thời được thử lại theo chính sách phiên chạy.',
    'topic_class' => 'Theo hạng rủi ro của chủ đề (do RiskClassifier quyết định)',
    'on_timeout' => 'Phiên chạy dừng và trả bài về trạng thái cũ.',
    'skills' => [
        'brief/content-brief',
        'strategist/content-plan',
    ],
    'orchestrator_steps' => [
        'brief' => 'strategist',
    ],
    'readiness_note' => 'Được AgentRunOrchestrator gọi trong phiên chạy; mới kiểm bằng AI giả lập trong test, chưa thử với model thật, chưa qua staging, chưa có người thật nghiệm thu.',
    'origin' => [
        'claude-seo/seo-plan',
        'claude-seo/seo-content-brief',
    ],
];
