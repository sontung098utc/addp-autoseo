<?php

/**
 * AG-S09: vai trò "Phân tích đối thủ" là DỮ LIỆU, không phải mã.
 * Mức sẵn sàng của vai trò đã nối KHÔNG khai báo ở đây: RoleRegistry suy từ orchestrator_steps (bước => người thực hiện ghi trong nhật ký).
 * Vai trò chưa nối khai báo readiness: not_connected | idea | demo (không có "active").
 */
return [
    'key' => 'competitor',
    'name' => 'Phân tích đối thủ',
    'goal' => 'Tìm khoảng trống nội dung so với đối thủ thật trên kết quả tìm kiếm.',
    'tools_allowed' => [
        'Dữ liệu SERP (khi đã nối)',
        'Tính khoảng trống cục bộ',
    ],
    'tools_denied' => [
        'Shell / chạy lệnh hệ thống',
        'Ghi tự do vào cơ sở dữ liệu hay tệp',
        'Đăng bài lên CMS (chỉ cổng xuất bản hiện có được đăng)',
        'Đọc IP nội bộ hoặc domain ngoài danh sách được phép',
    ],
    'model_tier' => 'standard',
    'human_gate' => 'Có: xem danh sách đối thủ và khoảng trống',
    'rubric_refs' => [],
    'guardrails' => [
        'Loại tên miền không phải đối thủ (diễn đàn, báo, mạng xã hội).',
        'Chưa nối SERP thì ghi "Chưa kết nối", không điền số.',
    ],
    'pass_condition' => 'Mỗi khoảng trống có URL đối thủ làm bằng chứng.',
    'on_fail' => 'Bỏ khoảng trống thiếu bằng chứng.',
    'topic_class' => 'An toàn (chỉ đọc)',
    'on_timeout' => 'Trả phần đã có, ghi giới hạn.',
    'skills' => [
        'analyst/serp-gap',
    ],
    'orchestrator_steps' => [],
    'readiness' => 'not_connected',
    'readiness_note' => 'Chưa kết nối nguồn dữ liệu SERP; trang Đối thủ chạy trên dữ liệu minh họa.',
    'origin' => [
        'claude-seo/seo-sxo',
        'claude-seo/seo-content-brief (phần SERP)',
    ],
];
