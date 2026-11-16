<?php

return [
    // Dữ liệu minh họa và các đường rơi về bản giả (AI giả lập, tìm web mô phỏng, ảnh giả, số liệu giả). Mặc định tắt: thiếu cấu hình thì báo "chưa kết nối", không bịa. Không bật được ở production.
    'demo' => (bool) env('STUDIO_DEMO', false),
    // Ngoài production, màn Từ khóa mặc định dùng dữ liệu minh họa (DemoStore). Đặt true để dùng bảng keywords thật.
    'keywords_real' => (bool) env('STUDIO_KEYWORDS_REAL', false),
    // Ngoài production, màn Đối thủ mặc định dùng dữ liệu minh họa. Đặt true để dùng bảng competitors/keyword_gaps thật.
    'competitors_real' => (bool) env('STUDIO_COMPETITORS_REAL', false),
];
