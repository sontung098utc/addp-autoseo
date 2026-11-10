<?php

/** AG-B1: mẫu trống: người dùng tự dựng từ đầu. */
return [
    'name' => 'Tự tạo (trống)',
    'description' => 'Không có gợi ý sẵn. Bạn tự điền Hồ sơ lĩnh vực và thêm chủ đề.',
    'domain_key' => null,
    'ai_reliance' => 'numbers',
    'risk_class' => 'low',
    'priority_types' => ['fact', 'price', 'policy', 'process', 'faq', 'messaging'],
    'topics' => [],
    'profile' => [
        'voice' => ['tone' => '', 'formality' => '', 'pronouns' => '', 'do' => [], 'dont' => []],
        'structure' => ['article_types' => []],
        'terminology' => ['preferred' => [], 'abbreviations' => []],
        'reasoning' => ['rules' => []],
        'rubric_notes' => '',
        'exemplars' => [],
    ],
    'fill_hints' => [],
];
