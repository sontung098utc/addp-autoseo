<?php

/** AG-B1: mẫu CẤU TRÚC cho thương hiệu/doanh nghiệp. Đọc cùng Hồ sơ doanh nghiệp của website. Không chứa thông tin doanh nghiệp. */
return [
    'name' => 'Thương hiệu / Doanh nghiệp',
    'description' => 'Giới thiệu, sản phẩm, chính sách, giọng thương hiệu. Dùng cùng Hồ sơ doanh nghiệp của website.',
    'domain_key' => 'thuong_hieu',
    'ai_reliance' => 'numbers',
    'risk_class' => 'low',
    'priority_types' => ['fact', 'messaging', 'policy'],
    'topics' => [
        'Giới thiệu' => ['Câu chuyện thương hiệu', 'Giá trị cốt lõi'],
        'Sản phẩm và dịch vụ' => ['Danh mục', 'Điểm khác biệt'],
        'Chính sách' => ['Bảo hành và đổi trả', 'Thanh toán và giao nhận'],
        'Giọng thương hiệu' => ['Thông điệp', 'Điều không nói'],
    ],
    'profile' => [
        'voice' => ['tone' => '', 'formality' => '', 'pronouns' => '', 'do' => ['Giữ giọng nhất quán với Hồ sơ doanh nghiệp'], 'dont' => ['Nêu claim chưa có trong danh sách được phép']],
        'structure' => ['article_types' => [
            ['key' => 'gioi_thieu', 'name' => 'Bài giới thiệu', 'outline' => ['Bối cảnh', 'Điều làm nên khác biệt', 'Dành cho ai', 'Bắt đầu như thế nào']],
            ['key' => 'huong_dan_su_dung', 'name' => 'Hướng dẫn sử dụng', 'outline' => ['Mục tiêu', 'Chuẩn bị', 'Các bước', 'Mẹo và lưu ý', 'Câu hỏi thường gặp']],
            ['key' => 'tin_tuc', 'name' => 'Tin tức và cập nhật', 'outline' => ['Điều gì mới', 'Ý nghĩa với người dùng', 'Cách bắt đầu']],
        ]],
        'terminology' => ['preferred' => [], 'abbreviations' => []],
        'reasoning' => ['rules' => ['Chỉ nêu số liệu, giá, chính sách có trong kho đã duyệt', 'Khi thiếu thông tin thì nói rõ là chưa có, không suy đoán']],
        'rubric_notes' => '',
        'exemplars' => [],
    ],
    'fill_hints' => ['Từ cấm và claim được phép nằm ở Hồ sơ doanh nghiệp', 'Thêm bài mẫu đã đăng tốt'],
];
