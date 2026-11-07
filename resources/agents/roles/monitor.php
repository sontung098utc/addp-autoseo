<?php

/**
 * AG-S09: vai trò "Giám sát hiệu suất" là DỮ LIỆU, không phải mã.
 * Mức sẵn sàng của vai trò đã nối KHÔNG khai báo ở đây: RoleRegistry suy từ orchestrator_steps (bước => người thực hiện ghi trong nhật ký).
 * Vai trò chưa nối khai báo readiness: not_connected | idea | demo (không có "active").
 */
return [
    'key' => 'monitor',
    'name' => 'Giám sát hiệu suất',
    'goal' => 'So sánh hiệu suất với mốc nền, báo mức nghiêm trọng khi lệch (CRITICAL / WARNING / INFO).',
    'tools_allowed' => [
        'Dữ liệu Search Console (khi đã nối)',
        'Lưu mốc nền cục bộ',
    ],
    'tools_denied' => [
        'Shell / chạy lệnh hệ thống',
        'Ghi tự do vào cơ sở dữ liệu hay tệp',
        'Đăng bài lên CMS (chỉ cổng xuất bản hiện có được đăng)',
        'Đọc IP nội bộ hoặc domain ngoài danh sách được phép',
    ],
    'model_tier' => 'cheap',
    'human_gate' => 'Có: xem cảnh báo',
    'rubric_refs' => [],
    'guardrails' => [
        'Chưa nối Search Console thì ghi "Chưa kết nối", không điền số.',
        'Không tự sửa bài khi phát hiện tụt hạng; chỉ báo.',
    ],
    'pass_condition' => 'Mỗi cảnh báo có số đo trước/sau và khoảng thời gian.',
    'on_fail' => 'Bỏ cảnh báo thiếu dữ liệu so sánh.',
    'topic_class' => 'An toàn (chỉ đọc)',
    'on_timeout' => 'Bỏ qua lượt này, ghi lý do.',
    'skills' => [
        'monitor/drift-baseline',
    ],
    'orchestrator_steps' => [],
    'readiness' => 'not_connected',
    'readiness_note' => 'Chưa kết nối Google Search Console; màn Search Console chạy dữ liệu minh họa (mục 5.1 chưa làm).',
    'origin' => [
        'claude-seo/seo-drift',
        'claude-seo/seo-google',
    ],
];
