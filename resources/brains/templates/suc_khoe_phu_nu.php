<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho sức khỏe phụ nữ và thai sản. Không có mốc, liều hay chỉ định cụ thể. */
return BrainTemplates::health([
    'name' => 'Sức khỏe phụ nữ và thai sản',
    'description' => 'Sức khỏe sinh sản, thai kỳ, sau sinh, tiền mãn kinh. Luôn khuyến nghị tham vấn bác sĩ. Bắt buộc nguồn, hạng rủi ro cao.',
    'domain_key' => 'suc_khoe_phu_nu',
    'reliance_reason' => 'Ảnh hưởng đến cả mẹ và thai nhi; mỗi trường hợp khác nhau nên không thể đưa lời khuyên chung như chỉ dẫn.',
    'topics' => [
        'Sức khỏe sinh sản' => ['Chu kỳ kinh nguyệt', 'Kế hoạch hóa gia đình', 'Chăm sóc phụ khoa'],
        'Thai kỳ' => ['Chuẩn bị mang thai', 'Khám thai', 'Ăn uống và sinh hoạt khi mang thai', 'Dấu hiệu cần đi khám ngay'],
        'Sau sinh' => ['Hồi phục sau sinh', 'Cho con bú', 'Sức khỏe tinh thần sau sinh'],
        'Tuổi tiền mãn kinh và mãn kinh' => ['Thay đổi cơ thể', 'Chăm sóc sức khỏe'],
    ],
    'types' => [
        'chuan_bi_mang_thai' => ['Chuẩn bị trước khi mang thai', ['Vì sao nên chuẩn bị', 'Những điều nên trao đổi với bác sĩ', 'Lối sống phù hợp', 'Khám tiền thai']],
        'thai_ky_theo_giai_doan' => ['Đồng hành thai kỳ theo từng giai đoạn', ['Giai đoạn này nói chung có gì thay đổi', 'Lịch khám thai nên theo bác sĩ', 'Điều cần tránh hoặc hỏi bác sĩ', 'Dấu hiệu cần đi khám ngay']],
        'cham_soc_sau_sinh' => ['Chăm sóc mẹ sau sinh', ['Cơ thể hồi phục thế nào nói chung', 'Nghỉ ngơi và dinh dưỡng', 'Cho con bú và các khó khăn thường gặp', 'Sức khỏe tinh thần của mẹ', 'Khi nào cần gặp bác sĩ']],
        'suc_khoe_phu_khoa' => ['Chăm sóc sức khỏe phụ khoa', ['Vì sao cần chăm sóc phụ khoa', 'Thói quen vệ sinh', 'Dấu hiệu nên đi khám', 'Khám phụ khoa định kỳ']],
    ],
    'terms' => ['Chu kỳ kinh nguyệt', 'Rụng trứng', 'Tuổi thai', 'Khám thai định kỳ', 'Siêu âm thai', 'Tiền sản giật', 'Tiểu đường thai kỳ', 'Sinh thường', 'Sinh mổ', 'Hậu sản', 'Trầm cảm sau sinh', 'Tiền mãn kinh', 'Mãn kinh'],
    'rules' => ['Mọi bài về thai kỳ và sau sinh phải khuyến nghị tham vấn bác sĩ sản khoa hoặc nữ hộ sinh', 'Không nêu thuốc, thực phẩm bổ sung hay liều dùng cho phụ nữ mang thai và cho con bú', 'Không nêu mốc phát triển thai nhi hay chỉ số theo dõi nếu chưa có nguồn chuyên gia duyệt', 'Không gây lo sợ cho thai phụ; nêu dấu hiệu cần đi khám ngay một cách bình tĩnh và rõ ràng', 'Không đưa lời khuyên về kế hoạch hóa gia đình theo kiểu quảng cáo sản phẩm'],
    'do' => ['Tôn trọng trải nghiệm riêng của từng người phụ nữ'],
    'dont' => ['Đổ lỗi cho người mẹ', 'Khẳng định chuyện sinh con theo kiểu một chuẩn duy nhất'],
    'rubric' => ['Có khuyến nghị tham vấn bác sĩ ở mỗi bài', 'Không có thuốc hay bổ sung nào cho thai phụ khi chưa có nguồn', 'Giọng không phán xét'],
    'sources' => ['who.int', 'moh.gov.vn', 'nhs.uk', 'cdc.gov', 'nih.gov', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
    'hints' => ['Bác sĩ sản phụ khoa duyệt mọi bài'],
]);
