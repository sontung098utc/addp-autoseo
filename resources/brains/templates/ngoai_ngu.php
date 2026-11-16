<?php

/** AG-B1: mẫu CẤU TRÚC cho ngoại ngữ. Không chứa từ vựng, ngữ pháp hay tên chứng chỉ cụ thể. */
return [
    'name' => 'Ngoại ngữ',
    'description' => 'Dạy và giới thiệu ngoại ngữ: giữ ngôn ngữ gốc kèm giải thích tiếng Việt, thuật ngữ song ngữ.',
    'domain_key' => 'ngoai_ngu',
    'ai_reliance' => 'numbers',
    'risk_class' => 'low',
    'priority_types' => ['fact', 'faq'],
    'topics' => [
        'Từ vựng' => ['Chủ đề từ vựng', 'Cách ghi nhớ'],
        'Ngữ pháp' => ['Điểm ngữ pháp', 'Lỗi thường gặp'],
        'Chứng chỉ và thi cử' => ['Cấu trúc bài thi', 'Chiến lược ôn luyện'],
        'Văn hóa' => ['Giao tiếp', 'Phong tục'],
    ],
    'profile' => [
        'voice' => ['tone' => '', 'formality' => '', 'pronouns' => '', 'do' => ['Giữ nguyên từ gốc kèm giải thích tiếng Việt', 'Cho ví dụ ngắn khi giải thích'], 'dont' => ['Dịch máy móc từng chữ khi nghĩa thay đổi theo ngữ cảnh']],
        'structure' => ['article_types' => [
            ['key' => 'bai_hoc_tu_vung', 'name' => 'Bài học từ vựng', 'outline' => ['Chủ đề', 'Danh sách từ kèm giải thích', 'Ví dụ trong câu', 'Mẹo ghi nhớ', 'Bài tập ngắn']],
            ['key' => 'bai_hoc_ngu_phap', 'name' => 'Bài học ngữ pháp', 'outline' => ['Khi nào dùng', 'Cấu trúc', 'Ví dụ', 'Lỗi thường gặp', 'Luyện tập']],
            ['key' => 'huong_dan_on_thi', 'name' => 'Hướng dẫn ôn thi', 'outline' => ['Cấu trúc bài thi', 'Chiến lược từng phần', 'Lộ trình ôn tập', 'Câu hỏi thường gặp']],
        ]],
        'terminology' => ['preferred' => [], 'abbreviations' => []],
        'reasoning' => ['rules' => ['Mỗi quy tắc ngữ pháp kèm ít nhất một ví dụ', 'Phân biệt rõ cách dùng trang trọng và thân mật']],
        'rubric_notes' => '',
        'exemplars' => [],
    ],
    'fill_hints' => ['Từ điển thuật ngữ song ngữ do bạn điền', 'Chọn ngôn ngữ nguồn ở mục Cài đặt nếu cần'],
];
