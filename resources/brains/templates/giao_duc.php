<?php

/** AG-B1: mẫu CẤU TRÚC cho giáo dục/tuyển sinh. Không chứa học phí, chương trình hay tên trường. */
return [
    'name' => 'Giáo dục / Tuyển sinh',
    'description' => 'Thông tin chương trình, thủ tục, học bổng: số liệu phải khớp kho và có hiệu lực đến ngày.',
    'domain_key' => 'giao_duc',
    'ai_reliance' => 'numbers',
    'risk_class' => 'medium',
    'priority_types' => ['price', 'process', 'faq'],
    'topics' => [
        'Chương trình' => ['Điều kiện đầu vào', 'Nội dung đào tạo'],
        'Học phí và học bổng' => ['Học phí', 'Học bổng và hỗ trợ'],
        'Thủ tục' => ['Hồ sơ', 'Quy trình đăng ký'],
        'Cuộc sống người học' => ['Sinh hoạt', 'Cơ hội sau tốt nghiệp'],
    ],
    'profile' => [
        'voice' => ['tone' => '', 'formality' => '', 'pronouns' => '', 'do' => ['Nói rõ đối tượng phù hợp với chương trình', 'Nêu điều kiện kèm theo khi nhắc đến ưu đãi'], 'dont' => ['Hứa hẹn kết quả học tập hoặc việc làm chắc chắn']],
        'structure' => ['article_types' => [
            ['key' => 'gioi_thieu_chuong_trinh', 'name' => 'Giới thiệu chương trình', 'outline' => ['Chương trình dành cho ai', 'Điều kiện đầu vào', 'Nội dung chính', 'Hồ sơ và thủ tục', 'Câu hỏi thường gặp']],
            ['key' => 'huong_dan_thu_tuc', 'name' => 'Hướng dẫn thủ tục', 'outline' => ['Chuẩn bị', 'Các bước thực hiện', 'Lưu ý và lỗi thường gặp', 'Liên hệ hỗ trợ']],
            ['key' => 'so_sanh_lua_chon', 'name' => 'So sánh lựa chọn', 'outline' => ['Tiêu chí so sánh', 'Từng lựa chọn', 'Nên chọn khi nào']],
        ]],
        'terminology' => ['preferred' => [], 'abbreviations' => []],
        'reasoning' => ['rules' => ['Số liệu về học phí, thời hạn, điều kiện phải lấy từ kho đã duyệt và ghi ngày hiệu lực', 'Khi thông tin có thể thay đổi, hướng dẫn người đọc xác nhận lại với đơn vị']],
        'rubric_notes' => '',
        'exemplars' => [],
    ],
    'fill_hints' => ['Thuật ngữ về hệ thống giáo dục do bạn điền', 'Thêm bài mẫu có giọng nhất quán'],
];
