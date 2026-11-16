<?php

/** AG-B1: mẫu CẤU TRÚC cho sản phẩm/thương mại. Không chứa giá, thông số hay tên sản phẩm. */
return [
    'name' => 'Sản phẩm / Thương mại',
    'description' => 'Danh mục, thông số, so sánh, bảo hành: giá và thông số phải khớp kho; mâu thuẫn giá chặn duyệt.',
    'domain_key' => 'san_pham',
    'ai_reliance' => 'numbers',
    'risk_class' => 'low',
    'priority_types' => ['price', 'fact', 'faq'],
    'topics' => [
        'Danh mục' => ['Nhóm sản phẩm', 'Phân khúc'],
        'Thông số' => ['Thông số kỹ thuật', 'Tính năng'],
        'So sánh' => ['Tiêu chí so sánh', 'Gợi ý lựa chọn'],
        'Bảo hành và hậu mãi' => ['Bảo hành', 'Hỗ trợ'],
    ],
    'profile' => [
        'voice' => ['tone' => '', 'formality' => '', 'pronouns' => '', 'do' => ['Nêu lợi ích gắn với nhu cầu thực tế của người mua'], 'dont' => ['So sánh khập khiễng hoặc nêu giá không có trong kho']],
        'structure' => ['article_types' => [
            ['key' => 'danh_gia_san_pham', 'name' => 'Đánh giá sản phẩm', 'outline' => ['Tổng quan', 'Thiết kế và tính năng', 'Ưu điểm', 'Hạn chế', 'Phù hợp với ai', 'Kết luận']],
            ['key' => 'so_sanh_san_pham', 'name' => 'So sánh sản phẩm', 'outline' => ['Tiêu chí so sánh', 'Bảng so sánh', 'Nên chọn khi nào', 'Câu hỏi thường gặp']],
            ['key' => 'huong_dan_chon_mua', 'name' => 'Hướng dẫn chọn mua', 'outline' => ['Nhu cầu của bạn', 'Tiêu chí cần xem', 'Gợi ý theo nhu cầu', 'Lưu ý khi mua']],
        ]],
        'terminology' => ['preferred' => [], 'abbreviations' => []],
        'reasoning' => ['rules' => ['Giá, thông số, bảo hành phải lấy từ kho đã duyệt', 'Khi so sánh, dùng cùng một bộ tiêu chí cho mọi sản phẩm']],
        'rubric_notes' => '',
        'exemplars' => [],
    ],
    'fill_hints' => ['Thuật ngữ về thông số do bạn điền'],
];
