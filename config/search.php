<?php

use App\Services\Search\TavilySearchProvider;

/**
 * Tìm kiếm web để khám phá nguồn tài liệu bên ngoài (kiểu "Discover sources" của NotebookLM).
 * Nhà cung cấp đã chọn: Tavily. Muốn bật: đặt TAVILY_API_KEY trong .env. Thay nhà cung cấp khác: viết lớp implements App\Contracts\WebSearchProvider
 * rồi đổi 'provider' và 'providers'. Chưa gọi Tavily thật trong bản này (mới kiểm bằng Http giả).
 */
return [
    'provider' => env('SEARCH_PROVIDER', 'tavily'),

    'providers' => [
        'tavily' => TavilySearchProvider::class,
    ],

    'tavily' => [
        'key' => env('TAVILY_API_KEY'),
        'url' => env('TAVILY_URL', 'https://api.tavily.com/search'),
    ],

    'timeout' => (int) env('SEARCH_TIMEOUT', 15),

    'max_results' => 10,

    /** Chi phí ước tính MỖI lượt tìm (VND), theo giá cấu hình, không phải hóa đơn. */
    'cost_per_search_vnd' => (float) env('SEARCH_COST_PER_SEARCH_VND', 200),

    /** Tắt hẳn tìm web dù có khóa (mặc định bật khi có khóa). */
    'enabled' => (bool) env('SEARCH_ENABLED', true),
];
