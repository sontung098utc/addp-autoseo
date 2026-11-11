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
            ['id' => 'gemini-2.5-flash-lite', 'name' => 'Gemini 2.5 Flash-Lite', 'tier' => 'cheap', 'context' => null, 'price' => null],
            ['id' => 'gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash', 'tier' => 'standard', 'context' => 1048576, 'price' => null, 'default' => true],
            ['id' => 'gemini-2.5-pro', 'name' => 'Gemini 2.5 Pro', 'tier' => 'reasoning', 'context' => 1048576, 'price' => null, 'default' => true],
            ['id' => 'gemini-1.5-flash', 'name' => 'Gemini 1.5 Flash', 'tier' => 'cheap', 'context' => 1048576, 'price' => null, 'default' => true],
            ['id' => 'gemini-1.5-pro', 'name' => 'Gemini 1.5 Pro', 'tier' => 'reasoning', 'context' => 2097152, 'price' => null],
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

    /** Tên model hợp lệ khi tự thêm (cùng quy tắc RoleAssignments::saveModel). */
    'custom_model_pattern' => '/^[A-Za-z0-9._:\/-]{1,80}$/',
];
