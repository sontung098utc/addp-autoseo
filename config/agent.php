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
        // Tối ưu ảnh AI tạo trước khi lưu thư viện (WebP): chiều rộng tối đa (px) và chất lượng 1-100. 1200 px / 76 cho ảnh ~46 KB thay vì ~80 KB (1376 px / 82).
        // Tạo ảnh chạy song song với bước viết bằng tiến trình con (agent:run-images). Cần CSDL dùng chung được giữa hai tiến trình; tắt thì tạo ảnh ngay sau khi lập kế hoạch.
        'parallel' => (bool) env('AGENT_IMAGES_PARALLEL', false),
        'parallel_timeout' => (int) env('AGENT_IMAGES_PARALLEL_TIMEOUT', 900),
        'max_width' => (int) env('AGENT_IMAGES_MAX_WIDTH', 1200),
        'quality' => (int) env('AGENT_IMAGES_QUALITY', 76),
    ],
    'usage' => [
        // Trang Chi phí AI: bật để tính cả bài chạy bằng model GIẢ (mock-*, demo-*). Mặc định tắt vì đó không phải chi phí thật.
        'include_fake_models' => (bool) env('AGENT_USAGE_INCLUDE_FAKE_MODELS', false),
    ],
    'autopilot' => [
        // Chiến dịch Toàn quyền: giữ hàng chờ bài "đã lên kế hoạch" tối thiểu từng này; thêm mỗi lần tối đa plan_batch bài.
        'queue_min' => (int) env('AGENT_AUTOPILOT_QUEUE_MIN', 3),
        'plan_batch' => (int) env('AGENT_AUTOPILOT_PLAN_BATCH', 3),
    ],
];
