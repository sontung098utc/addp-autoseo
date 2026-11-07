<?php

/**
 * AG-S09: vai trò "Nghiên cứu từ khóa" là DỮ LIỆU, không phải mã.
 * Mức sẵn sàng của vai trò đã nối KHÔNG khai báo ở đây: RoleRegistry suy từ orchestrator_steps (bước => người thực hiện ghi trong nhật ký).
 * Vai trò chưa nối khai báo readiness: not_connected | idea | demo (không có "active").
 */
return [
    'key' => 'keyword',
    'name' => 'Nghiên cứu từ khóa',
    'goal' => 'Gom từ khóa thành cụm theo ý định tìm kiếm, ưu tiên cụm hợp với website.',
    'tools_allowed' => [
        'Dữ liệu từ khóa đã nhập (CSV)',
        'Bộ gom cụm cục bộ',
    ],
    'tools_denied' => [
        'Shell / chạy lệnh hệ thống',
        'Ghi tự do vào cơ sở dữ liệu hay tệp',
        'Đăng bài lên CMS (chỉ cổng xuất bản hiện có được đăng)',
        'Đọc IP nội bộ hoặc domain ngoài danh sách được phép',
    ],
    'model_tier' => 'cheap',
    'human_gate' => 'Có: xem danh sách cụm',
    'rubric_refs' => [],
    'guardrails' => [
        'Chưa có dữ liệu SERP thì chạy bản giảm cấp và ghi rõ, không giả vờ có.',
        'Không bịa lượng tìm kiếm hay độ khó.',
    ],
    'pass_condition' => 'Mỗi cụm có từ khóa chính và ý định rõ.',
    'on_fail' => 'Gộp/tách lại cụm một lần, rồi đánh dấu cụm chưa chắc.',
    'topic_class' => 'An toàn (chỉ đọc)',
    'on_timeout' => 'Giữ cụm đã có, ghi phần chưa xử lý.',
    'skills' => [
        'researcher/keyword-cluster',
    ],
    'orchestrator_steps' => [],
    'readiness' => 'not_connected',
    'readiness_note' => 'Trang Từ khóa dùng gom cụm giả lập; đội AI chưa gọi vai trò này.',
    'origin' => [
        'claude-seo/seo-cluster',
    ],
];
