<?php

return [

    'name' => env('APP_NAME', 'Auto SEO'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    // Proxy/tunnel được tin tiêu đề X-Forwarded-* (IP/CIDR cách nhau dấu phẩy, hoặc *). Để trống = không tin ai (mặc định). Xem TrustedProxiesServiceProvider.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'timezone' => env('APP_TIMEZONE', 'Asia/Ho_Chi_Minh'),

    'locale' => env('APP_LOCALE', 'vi'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'vi_VN'),

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    'maintenance' => [
        'driver' => 'file',
    ],

];
