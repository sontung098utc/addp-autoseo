<?php

/**
 * Danh mục nhà cung cấp và model TẠO SẴN cho trang Cấu hình AI (hiển thị/chọn; KHÔNG ảnh hưởng logic định tuyến).
 * - presets: loại tài khoản có thể thêm. `kind` là kiểu adapter thật (AiProviderFactory); `base_url` điền sẵn nếu có.
 * - models: id model, tên, bậc (cheap|standard|reasoning), ngữ cảnh (token) và giá tham khảo (USD/1 triệu token: [vào, ra]).
 *   Không chắc thì để null, KHÔNG đoán. Giá chỉ để tham khảo; chi phí thật tính theo bảng giá của provider trong hệ thống.
 * - default: model được bật sẵn khi thêm tài khoản. Người dùng luôn thêm được model tùy chỉnh bằng tên.
 */
return [
    'presets' => [
        'gemini' => ['label' => 'Google Gemini', 'kind' => 'gemini', 'base_url' => null, 'logo' => 'google', 'hint' => 'Khóa lấy ở Google AI Studio.'],
        'anthropic' => ['label' => 'Anthropic Claude', 'kind' => 'anthropic', 'base_url' => null, 'logo' => 'anthropic', 'hint' => 'Khóa lấy ở console.anthropic.com.'],
        'openai' => ['label' => 'OpenAI', 'kind' => 'openai_compat', 'base_url' => 'https://api.openai.com/v1', 'logo' => 'openai', 'hint' => 'Dùng giao thức OpenAI-tương thích.'],
        'openrouter' => ['label' => 'OpenRouter', 'kind' => 'openai_compat', 'base_url' => 'https://openrouter.ai/api/v1', 'logo' => 'openrouter', 'hint' => 'Một khóa dùng được nhiều hãng; tên model dạng hãng/model.'],
        'custom' => ['label' => 'OpenAI-tương thích tùy chỉnh', 'kind' => 'openai_compat', 'base_url' => null, 'logo' => 'custom', 'hint' => 'Máy chủ tự dựng hoặc dịch vụ khác (cần Base URL).'],
    ],

    'models' => [
        'gemini' => [
            // Danh sách theo aistudio.google.com/docs/models (12/11/2026). Dòng 1.5/2.0 đã ngừng, 2.5 flash/pro bị chuyển hướng từ 1/12/2026.
            ['id' => 'gemini-3.5-flash-lite', 'name' => 'Gemini 3.5 Flash-Lite', 'tier' => 'cheap', 'context' => null, 'price' => null, 'default' => true],
            ['id' => 'gemini-3.6-flash', 'name' => 'Gemini 3.6 Flash', 'tier' => 'standard', 'context' => null, 'price' => null, 'default' => true],
            ['id' => 'gemini-3.8-flash', 'name' => 'Gemini 3.8 Flash', 'tier' => 'reasoning', 'context' => null, 'price' => null, 'default' => true],
            ['id' => 'gemini-3.1-pro-preview', 'name' => 'Gemini 3.1 Pro (preview)', 'tier' => 'reasoning', 'context' => null, 'price' => null],
            // Model TẠO ẢNH (kind=image): không bật sẵn và không hiện ở danh sách model của vai trò chữ.
            ['id' => 'gemini-3.1-flash-lite-image', 'name' => 'Gemini 3.1 Flash-Lite Image', 'tier' => 'cheap', 'kind' => 'image', 'context' => null, 'price' => null],
            ['id' => 'gemini-nano-banana-2.1', 'name' => 'Gemini Nano Banana 2.1', 'tier' => 'cheap', 'kind' => 'image', 'context' => null, 'price' => null],
            ['id' => 'gemini-3.1-flash-image', 'name' => 'Gemini 3.1 Flash Image', 'tier' => 'standard', 'kind' => 'image', 'context' => null, 'price' => null],
            ['id' => 'gemini-3-pro-image', 'name' => 'Gemini 3 Pro Image', 'tier' => 'reasoning', 'kind' => 'image', 'context' => null, 'price' => null],
        ],
        'anthropic' => [
            ['id' => 'claude-haiku-5-5', 'name' => 'Claude Haiku 5.5', 'tier' => 'cheap', 'context' => null, 'price' => null, 'default' => true],
            ['id' => 'claude-sonnet-5-5', 'name' => 'Claude Sonnet 5.5', 'tier' => 'standard', 'context' => null, 'price' => null, 'default' => true],
            ['id' => 'claude-opus-5-5', 'name' => 'Claude Opus 5.5', 'tier' => 'reasoning', 'context' => null, 'price' => null, 'default' => true],
            ['id' => 'claude-fable-5-1', 'name' => 'Claude Fable 5.1', 'tier' => 'reasoning', 'context' => null, 'price' => null],
        ],
        'openai' => [
            ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o mini', 'tier' => 'cheap', 'context' => 128000, 'price' => null, 'default' => true],
            ['id' => 'gpt-4.1-mini', 'name' => 'GPT-4.1 mini', 'tier' => 'cheap', 'context' => 1047576, 'price' => null],
            ['id' => 'gpt-4o', 'name' => 'GPT-4o', 'tier' => 'standard', 'context' => 128000, 'price' => null, 'default' => true],
            ['id' => 'gpt-4.1', 'name' => 'GPT-4.1', 'tier' => 'standard', 'context' => 1047576, 'price' => null],
            ['id' => 'gpt-5', 'name' => 'GPT-5', 'tier' => 'reasoning', 'context' => null, 'price' => null],
        ],
        'openrouter' => [
            ['id' => 'openai/gpt-4o-mini', 'name' => 'GPT-4o mini (qua OpenRouter)', 'tier' => 'cheap', 'context' => 128000, 'price' => null, 'default' => true],
            ['id' => 'google/gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash (qua OpenRouter)', 'tier' => 'standard', 'context' => 1048576, 'price' => null, 'default' => true],
            ['id' => 'google/gemini-2.5-pro', 'name' => 'Gemini 2.5 Pro (qua OpenRouter)', 'tier' => 'reasoning', 'context' => 1048576, 'price' => null],
        ],
        'custom' => [],
    ],

    /**
     * Base URL nội bộ được phép cho nhà cung cấp AI (khớp nguyên văn "host:cổng"), mặc định rỗng. Chỉ áp cho Base URL của nhà cung cấp AI,
     * không nới luật chặn nội bộ ở chỗ khác. Ví dụ cầu nối CLI thử nghiệm: AI_PRIVATE_BASE_URL_ALLOWLIST=172.17.0.1:8765
     */
    'private_base_url_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_PRIVATE_BASE_URL_ALLOWLIST', ''))))),

    /** Tên model hợp lệ khi tự thêm (cùng quy tắc RoleAssignments::saveModel). */
    'custom_model_pattern' => '/^[A-Za-z0-9._:\/-]{1,80}$/',
];
