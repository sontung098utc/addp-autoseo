<?php

/** AG-B1: mẫu CẤU TRÚC cho khoa học phổ thông. Không chứa kiến thức khoa học cụ thể. */
return [
    'name' => 'Khoa học phổ thông',
    'description' => 'Giải thích khái niệm, thí nghiệm, lịch sử: bắt buộc nguồn, phân biệt đã kiểm chứng và giả thuyết.',
    'domain_key' => 'khoa_hoc',
    'ai_reliance' => 'must_cite',
    'risk_class' => 'medium',
    'priority_types' => ['fact', 'faq'],
    'topics' => [
        'Khái niệm' => ['Định nghĩa', 'Nguyên lý'],
        'Thí nghiệm' => ['Cách thực hiện', 'An toàn'],
        'Lịch sử' => ['Nhân vật và phát hiện', 'Dòng thời gian'],
    ],
    'profile' => [
        'voice' => ['tone' => '', 'formality' => '', 'pronouns' => '', 'do' => ['Dùng ví dụ đời thường để giải thích khái niệm trừu tượng'], 'dont' => ['Nêu giả thuyết như thể đã được chứng minh']],
        'structure' => ['article_types' => [
            ['key' => 'giai_thich_khai_niem', 'name' => 'Giải thích khái niệm', 'outline' => ['Khái niệm là gì', 'Nguyên lý hoạt động', 'Ví dụ đời thường', 'Hiểu lầm thường gặp', 'Nguồn tham khảo']],
            ['key' => 'huong_dan_thi_nghiem', 'name' => 'Hướng dẫn thí nghiệm', 'outline' => ['Mục tiêu', 'Chuẩn bị', 'Các bước', 'Giải thích kết quả', 'Lưu ý an toàn']],
        ]],
        'terminology' => ['preferred' => [], 'abbreviations' => []],
        'reasoning' => ['rules' => ['Mọi khẳng định kiểm chứng được phải có nguồn', 'Phân biệt điều đã kiểm chứng, điều còn tranh luận và giả thuyết']],
        'rubric_notes' => '',
        'exemplars' => [],
    ],
    'fill_hints' => ['Thuật ngữ khoa học do bạn điền', 'Thêm bài mẫu có giọng thống nhất'],
];
