<?php

return [
    // Ngoài production, màn Từ khóa mặc định dùng dữ liệu minh họa (DemoStore). Đặt true để dùng bảng keywords thật.
    'keywords_real' => (bool) env('STUDIO_KEYWORDS_REAL', false),
];
