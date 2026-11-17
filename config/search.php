<?php

use App\Services\Search\CodexBridgeSearchProvider;
use App\Services\Search\GeminiSearchProvider;
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
        'gemini' => GeminiSearchProvider::class, // SEARCH_PROVIDER=gemini, hoặc tự dùng khi không có khóa Tavily/cầu nối: Gemini tự tìm bằng Google Search (dùng khóa Gemini đã lưu)
        'codex_bridge' => CodexBridgeSearchProvider::class, // SEARCH_PROVIDER=codex_bridge: ChatGPT tự tìm bằng công cụ web_search (cầu nối scripts/codex_bridge.py)
    ],

    'tavily' => [
        'key' => env('TAVILY_API_KEY'),
        'url' => env('TAVILY_URL', 'https://api.tavily.com/search'),
    ],

    'gemini' => [
        'model' => env('SEARCH_GEMINI_MODEL', 'gemini-3.6-flash'),
        'timeout' => (int) env('SEARCH_GEMINI_TIMEOUT', 60),
    ],

    /** Thời gian chờ tối đa (giây) cho tìm web qua cầu nối Claude (tìm + mở trang chậm hơn API tìm kiếm thường). */
    'bridge_timeout' => (int) env('SEARCH_BRIDGE_TIMEOUT', 240),

    'timeout' => (int) env('SEARCH_TIMEOUT', 15),

    'max_results' => 10,

    /** Chi phí ước tính MỖI lượt tìm (VND), theo giá cấu hình, không phải hóa đơn. */
    'cost_per_search_vnd' => (float) env('SEARCH_COST_PER_SEARCH_VND', 200),

    /** Tắt hẳn tìm web dù có khóa (mặc định bật khi có khóa). */
    'enabled' => (bool) env('SEARCH_ENABLED', true),
];
