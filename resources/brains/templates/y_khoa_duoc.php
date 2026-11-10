<?php

/** AG-B1: mẫu CẤU TRÚC cho lĩnh vực y khoa/dược. Không chứa sự kiện, số liệu, tên thuốc hay phác đồ: người dùng/chuyên gia tự điền. */
return [
    'name' => 'Y khoa / Dược',
    'description' => 'Lĩnh vực nhạy cảm sức khỏe (YMYL): mặc định bắt buộc nguồn, hạng rủi ro cao, cần người có chuyên môn duyệt.',
    'domain_key' => 'y_khoa',
    'ai_reliance' => 'must_cite',
    'risk_class' => 'high',
    'priority_types' => ['fact', 'faq', 'policy'],
    'topics' => [
        'Bệnh lý' => ['Nguyên nhân và yếu tố nguy cơ', 'Triệu chứng', 'Phòng ngừa', 'Khi nào cần đi khám'],
        'Thuốc' => ['Nhóm thuốc', 'Cách dùng và lưu ý', 'Tương tác và chống chỉ định'],
        'Dinh dưỡng và lối sống' => ['Chế độ ăn', 'Vận động'],
        'Quy định và quảng cáo' => ['Quy định quảng cáo', 'Miễn trừ trách nhiệm'],
    ],
    'profile' => [
        'voice' => ['tone' => '', 'formality' => '', 'pronouns' => '', 'do' => ['Dùng ngôn ngữ rõ ràng, dễ hiểu cho người không chuyên', 'Giải thích thuật ngữ chuyên môn ngay lần đầu xuất hiện'], 'dont' => ['Cam kết kết quả tuyệt đối', 'Chẩn đoán hoặc kê đơn thay thầy thuốc']],
        'structure' => ['article_types' => [
            ['key' => 'giai_thich_benh', 'name' => 'Giải thích bệnh', 'outline' => ['Bệnh là gì', 'Nguyên nhân và yếu tố nguy cơ', 'Dấu hiệu nhận biết', 'Cách phòng ngừa', 'Khi nào cần gặp bác sĩ', 'Câu hỏi thường gặp']],
            ['key' => 'so_sanh_thuoc', 'name' => 'So sánh thuốc', 'outline' => ['Tổng quan các lựa chọn', 'Cơ chế và chỉ định', 'Ưu điểm và hạn chế', 'Lưu ý khi dùng', 'Trao đổi với bác sĩ hoặc dược sĩ']],
            ['key' => 'huong_dan_cham_soc', 'name' => 'Hướng dẫn chăm sóc', 'outline' => ['Mục tiêu', 'Các bước thực hiện', 'Dấu hiệu cần dừng và đi khám', 'Câu hỏi thường gặp']],
        ]],
        'terminology' => ['preferred' => [], 'abbreviations' => []],
        'reasoning' => ['rules' => ['Mọi khẳng định có thể kiểm chứng phải nêu bằng chứng hoặc nguồn', 'Có miễn trừ trách nhiệm: nội dung chỉ để tham khảo, không thay thế tư vấn y tế', 'Khuyến nghị gặp bác sĩ khi có dấu hiệu bất thường hoặc khi cần quyết định điều trị', 'Phân biệt rõ điều đã được chứng minh và điều còn tranh luận']],
        'rubric_notes' => '',
        'exemplars' => [],
    ],
    'fill_hints' => ['Thuật ngữ ưu tiên và viết tắt do chuyên gia điền', 'Tông giọng, cách xưng hô cần thống nhất với đội biên tập', 'Thêm vài bài mẫu đã được chuyên gia duyệt'],
];
