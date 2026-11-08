<?php

return [
    // Ngoài production, màn Từ khóa mặc định dùng dữ liệu minh họa (DemoStore). Đặt true để dùng bảng keywords thật.
    'keywords_real' => (bool) env('STUDIO_KEYWORDS_REAL', false),
    // Ngoài production, màn Đối thủ mặc định dùng dữ liệu minh họa. Đặt true để dùng bảng competitors/keyword_gaps thật.
    'competitors_real' => (bool) env('STUDIO_COMPETITORS_REAL', false),
];
