<?php

/**
 * Giãn cách gọi API AI (xem App\Services\Ai\AiRateGate). Mặc định tắt để không đoán hạn mức của từng gói; bật khi gặp 429 theo phút:
 * AI_MIN_INTERVAL_SECONDS=4 (15 yêu cầu/phút) hoặc đặt riêng từng model ở per_model. Hạn mức thay đổi theo gói/thời điểm, kiểm tra ở bảng của nhà cung cấp.
 */
return [
    /** Chờ tối đa bao nhiêu giây cho một lần gọi AI (mặc định 90). Cầu nối CLI (agy) có lần chạy vài trăm giây: đặt AI_HTTP_TIMEOUT=200 khi dùng. */
    'http_timeout' => (int) env('AI_HTTP_TIMEOUT', 90),
    'min_interval_seconds' => (float) env('AI_MIN_INTERVAL_SECONDS', 0),
    'per_model' => [
        // 'gemini-3.5-flash-lite' => 4.0,
        // 'gemini-3.6-flash' => 12.0,
    ],
];
