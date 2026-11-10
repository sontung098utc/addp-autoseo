<?php

return [
    'images' => [
        // Tắt hẳn việc tự tạo ảnh. Mặc định bật; vẫn cần nhà cung cấp ảnh được cấu hình thì mới tạo.
        'enabled' => (bool) env('AGENT_IMAGES_ENABLED', true),
        // Số ảnh minh họa trong thân bài (mỗi ảnh một mục H2). Trần cứng 4 ảnh cho cả bài (kể cả ảnh bìa).
        'max_inline' => (int) env('AGENT_IMAGES_MAX_INLINE', 2),
        'hard_cap' => 4,
        // Lĩnh vực sức khỏe: mặc định KHÔNG tự tạo ảnh (tránh ảnh minh họa sai sự thật).
        'medical' => (bool) env('AGENT_IMAGES_MEDICAL', false),
        // Trần chi phí ảnh = tỉ lệ này của ngân sách phiên.
        'budget_share' => 0.10,
        'default_budget_vnd' => 50000.0,
        // Ước tính giá một ảnh khi provider không khai pricing['image_vnd']. Chưa đo thật.
        'cost_vnd_per_image' => (float) env('AGENT_IMAGES_COST_VND', 1000),
        // Chèn <figure> vào thân bài sau mục H2 tương ứng. Tắt thì chỉ gắn vào phiên bản (ảnh bìa + danh sách ảnh).
        'insert_inline' => true,
    ],
];
