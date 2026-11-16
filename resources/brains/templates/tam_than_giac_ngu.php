<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho sức khỏe tinh thần và giấc ngủ. Không chẩn đoán, không thuốc, không kỹ thuật điều trị cụ thể. */
return BrainTemplates::health([
    'name' => 'Sức khỏe tinh thần và giấc ngủ',
    'description' => 'Căng thẳng, lo âu, tâm trạng, giấc ngủ, thói quen tinh thần lành mạnh, khi nào tìm hỗ trợ chuyên môn. Bắt buộc nguồn (chọn mức an toàn cao vì người đọc có thể đang khủng hoảng), hạng rủi ro cao.',
    'domain_key' => 'tam_than_giac_ngu',
    'reliance_reason' => 'Người đọc có thể đang dễ tổn thương; nội dung sai hoặc thiếu nhạy cảm có thể gây hại. Giữ mức bắt buộc nguồn thay vì tự do.',
    'topics' => [
        'Căng thẳng và lo âu' => ['Nhận biết căng thẳng', 'Thói quen giảm căng thẳng'],
        'Tâm trạng' => ['Tâm trạng thấp kéo dài', 'Hỗ trợ người thân'],
        'Giấc ngủ' => ['Vệ sinh giấc ngủ', 'Khó ngủ', 'Giấc ngủ theo độ tuổi'],
        'Tìm hỗ trợ' => ['Khi nào gặp chuyên gia', 'Đường dây và cơ sở hỗ trợ chính thức'],
        'Trường học và công sở' => ['Áp lực học tập', 'Áp lực công việc'],
    ],
    'types' => [
        'hieu_cang_thang_lo_au' => ['Hiểu về căng thẳng và lo âu', ['Cảm giác này là gì', 'Vì sao nhiều người gặp', 'Thói quen hỗ trợ mang tính chung', 'Khi nào nên gặp chuyên gia', 'Câu hỏi thường gặp']],
        've_sinh_giac_ngu' => ['Thói quen giấc ngủ lành mạnh', ['Vì sao giấc ngủ quan trọng', 'Thói quen thường được khuyến nghị', 'Những điều có thể ảnh hưởng đến giấc ngủ', 'Khi nào nên đi khám']],
        'ho_tro_nguoi_than' => ['Đồng hành cùng người thân đang gặp khó khăn tinh thần', ['Dấu hiệu bạn có thể quan sát', 'Cách lắng nghe', 'Điều nên và không nên nói', 'Cách kết nối với hỗ trợ chuyên môn']],
        'khi_nao_tim_chuyen_gia' => ['Khi nào nên tìm chuyên gia sức khỏe tinh thần', ['Nhận ra khi mình cần hỗ trợ', 'Các loại chuyên gia', 'Chuẩn bị cho buổi gặp đầu tiên', 'Nơi hỗ trợ chính thức tại địa phương']],
    ],
    'terms' => ['Căng thẳng', 'Lo âu', 'Rối loạn lo âu', 'Trầm cảm', 'Kiệt sức nghề nghiệp', 'Vệ sinh giấc ngủ', 'Mất ngủ', 'Ngưng thở khi ngủ', 'Liệu pháp tâm lý', 'Chánh niệm', 'Sức khỏe tinh thần'],
    'rules' => ['Không chẩn đoán, không gán nhãn bệnh cho người đọc, không dùng bài tự kiểm tra như kết luận', 'Nội dung nhắc đến ý nghĩ tự hại: phải hướng đến đường dây và cơ sở hỗ trợ chính thức do bên chịu trách nhiệm xác nhận, không mô tả phương pháp', 'Không nêu thuốc ngủ hay thuốc tâm thần, không nêu liều', 'Không khẳng định kỹ thuật thư giãn hay thực phẩm "chữa" lo âu hay mất ngủ', 'Giọng điệu không phán xét, không nói "chỉ cần cố gắng là được"'],
    'do' => ['Dùng ngôn ngữ nhẹ nhàng, tôn trọng', 'Nhắc có thể tìm sự hỗ trợ'],
    'dont' => ['Làm nhẹ đi nỗi đau của người đọc', 'Mô tả chi tiết các hành vi tự hại'],
    'rubric' => ['Có hướng đến hỗ trợ chuyên môn', 'Không gán nhãn bệnh', 'Không nêu thuốc hay liều'],
    'sources' => ['who.int', 'moh.gov.vn', 'nhs.uk', 'cdc.gov', 'nih.gov', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
    'hints' => ['Chuyên gia tâm lý hoặc bác sĩ tâm thần duyệt mọi bài', 'Cần danh sách đường dây hỗ trợ chính thức do bên chịu trách nhiệm cung cấp'],
]);
