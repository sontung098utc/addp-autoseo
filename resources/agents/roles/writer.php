<?php

/**
 * AG-S09: vai trò "Người viết" là DỮ LIỆU, không phải mã.
 * Mức sẵn sàng của vai trò đã nối KHÔNG khai báo ở đây: RoleRegistry suy từ orchestrator_steps (bước => người thực hiện ghi trong nhật ký).
 * Vai trò chưa nối khai báo readiness: not_connected | idea | demo (không có "active").
 */
return [
    'key' => 'writer',
    'name' => 'Người viết',
    'goal' => 'Viết bản nháp tiếng Việt từ dàn ý, sửa theo phản hồi của luật chất lượng.',
    'tools_allowed' => [
        'Dàn ý đã duyệt',
        'Phản hồi từ bộ kiểm tra chất lượng',
        'Bộ lọc HTML an toàn',
    ],
    'tools_denied' => [
        'Shell / chạy lệnh hệ thống',
        'Ghi tự do vào cơ sở dữ liệu hay tệp',
        'Đăng bài lên CMS (chỉ cổng xuất bản hiện có được đăng)',
        'Đọc IP nội bộ hoặc domain ngoài danh sách được phép',
    ],
    'model_tier' => 'standard',
    'human_gate' => 'Không (bản nháp luôn chờ người duyệt)',
    'rubric_refs' => [
        'LEN01',
        'HEAD01',
        'KW01',
        'META01',
        'SAFE01',
        'FACT01',
    ],
    'guardrails' => [
        'Chỉ tạo bản nháp; không xuất bản.',
        'Không bịa số liệu hay trích dẫn.',
        'HTML đầu ra được lọc trước khi hiển thị và trước khi gửi CMS.',
    ],
    'pass_condition' => 'Không còn lỗi chặn (P0) từ bộ kiểm tra chất lượng.',
    'on_fail' => 'Viết lại theo phản hồi của luật, tối đa số vòng đặt ở Kết nối AI; hết vòng thì giữ nháp cho người xem.',
    'topic_class' => 'Theo hạng rủi ro của chủ đề (do RiskClassifier quyết định)',
    'on_timeout' => 'Giữ bản nháp tốt nhất, không vào hàng chờ duyệt.',
    'skills' => [
        'writer/article-draft',
    ],
    'orchestrator_steps' => [
        'write' => 'writer',
    ],
    'readiness_note' => 'Được AgentRunOrchestrator gọi trong phiên chạy; mới kiểm bằng AI giả lập trong test, chưa thử với model thật, chưa qua staging, chưa có người thật nghiệm thu.',
    'origin' => [
        'claude-seo/seo-content',
    ],
];
