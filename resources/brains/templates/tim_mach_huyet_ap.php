<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho tim mạch và tăng huyết áp. Không có ngưỡng huyết áp, thuốc hay phác đồ. */
return BrainTemplates::health([
    'name' => 'Tim mạch và tăng huyết áp',
    'description' => 'Giải thích tăng huyết áp, bệnh tim mạch, đo huyết áp tại nhà, yếu tố nguy cơ, dấu hiệu khẩn cấp. Bắt buộc nguồn, hạng rủi ro cao.',
    'domain_key' => 'tim_mach_huyet_ap',
    'reliance_reason' => 'Liên quan đến bệnh có thể đe dọa tính mạng; thông tin sai hoặc chậm đi khám gây hậu quả nghiêm trọng.',
    'topics' => [
        'Huyết áp' => ['Hiểu về huyết áp', 'Đo huyết áp tại nhà', 'Theo dõi lâu dài'],
        'Bệnh tim mạch' => ['Bệnh mạch vành', 'Suy tim', 'Rối loạn nhịp'],
        'Yếu tố nguy cơ' => ['Mỡ máu', 'Hút thuốc và rượu bia', 'Căng thẳng'],
        'Dấu hiệu khẩn cấp' => ['Dấu hiệu cần gọi cấp cứu'],
        'Lối sống tốt cho tim' => ['Ăn uống', 'Vận động'],
    ],
    'types' => [
        'hieu_tang_huyet_ap' => ['Hiểu về tăng huyết áp', ['Huyết áp là gì', 'Vì sao tăng huyết áp cần theo dõi', 'Yếu tố nguy cơ', 'Biến chứng thường được nhắc đến', 'Khi nào cần gặp bác sĩ', 'Câu hỏi thường gặp']],
        'do_huyet_ap_tai_nha' => ['Đo huyết áp tại nhà đúng cách', ['Vì sao nên đo tại nhà', 'Chuẩn bị trước khi đo', 'Các bước đo', 'Cách ghi chép để trao đổi với bác sĩ']],
        'dau_hieu_cap_cuu_tim' => ['Dấu hiệu tim mạch cần cấp cứu', ['Vì sao không nên chờ đợi', 'Nhóm dấu hiệu cần gọi cấp cứu', 'Nên làm gì trong lúc chờ hỗ trợ y tế theo hướng dẫn chính thức']],
        'loi_song_cho_tim' => ['Lối sống bảo vệ tim mạch', ['Các thói quen liên quan đến tim mạch', 'Ăn uống', 'Vận động phù hợp', 'Bỏ thuốc lá và hạn chế rượu bia', 'Tái khám định kỳ']],
    ],
    'terms' => ['Huyết áp tâm thu', 'Huyết áp tâm trương', 'Tăng huyết áp', 'Mỡ máu', 'Cholesterol', 'Xơ vữa động mạch', 'Nhồi máu cơ tim', 'Đột quỵ', 'Suy tim', 'Rối loạn nhịp tim', 'Điện tâm đồ', 'Siêu âm tim'],
    'rules' => ['Không nêu ngưỡng huyết áp hay mục tiêu điều trị nếu chưa có nguồn chuyên gia duyệt', 'Với dấu hiệu khẩn cấp, chỉ hướng người đọc gọi cấp cứu hoặc đến cơ sở y tế gần nhất, không hướng dẫn tự xử trí', 'Không khuyên tự ngừng hay đổi thuốc huyết áp; luôn hỏi bác sĩ điều trị'],
    'do' => ['Nói rõ tính khẩn cấp một cách bình tĩnh'],
    'dont' => ['Dọa người đọc bằng hậu quả', 'Đề cập thực phẩm hay bài thuốc "hạ huyết áp nhanh"'],
    'rubric' => ['Dấu hiệu khẩn cấp luôn kèm hướng dẫn gọi cấp cứu', 'Không có ngưỡng chỉ số khi chưa có nguồn', 'Có khuyến nghị tái khám'],
    'sources' => ['who.int', 'moh.gov.vn', 'heart.org', 'escardio.org', 'cdc.gov', 'nhs.uk', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
    'hints' => ['Chuyên gia tim mạch xác nhận danh sách dấu hiệu khẩn cấp'],
]);
