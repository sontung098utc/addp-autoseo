<?php

return [
    // Nhà cung cấp số liệu lượt tìm. Hiện có: dataforseo. Thêm nhà khác: viết lớp theo App\Contracts\KeywordMetricsProvider và nối trong KeywordMetricsResolver.
    'provider' => env('KEYWORDS_METRICS_PROVIDER', 'dataforseo'),

    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        'endpoint' => 'https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/live',
    ],

    // Việt Nam, tiếng Việt (mã địa điểm của Google Ads).
    'location_code' => 2704,
    'language_code' => 'vi',

    // Số từ khóa mỗi yêu cầu gửi nhà cung cấp (tài liệu của họ cho tối đa 700; dùng lô nhỏ cho dễ kiểm soát chi phí).
    'batch' => 100,

    // Số từ khóa tối đa tra cho mỗi lần chạy.
    'max_per_run' => 300,

    // Số liệu cũ hơn số ngày này mới được tra lại.
    'refresh_days' => 30,

    // ƯỚC TÍNH chi phí cho mỗi 100 từ khóa (VND). Đây là giá trị cấu hình để chặn chi phí, KHÔNG phải bảng giá chính thức:
    // hãy đối chiếu hóa đơn thật của nhà cung cấp rồi chỉnh lại.
    'cost_per_100_keywords_vnd' => (float) env('KEYWORDS_COST_PER_100_VND', 2000),

    // Tỷ giá ước tính để đổi CPC (USD) sang VND.
    'usd_vnd_rate' => (float) env('KEYWORDS_USD_VND_RATE', 25000),
];
