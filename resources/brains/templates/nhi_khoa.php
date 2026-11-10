<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho sức khỏe trẻ em. Không có liều, lịch tiêm hay mốc phát triển cụ thể. */
return BrainTemplates::health([
    'name' => 'Sức khỏe trẻ em',
    'description' => 'Chăm sóc sức khỏe trẻ sơ sinh đến thanh thiếu niên, phát triển, dinh dưỡng, tiêm chủng, bệnh thường gặp ở trẻ. Luôn khuyến nghị tham vấn bác sĩ nhi. Bắt buộc nguồn, hạng rủi ro cao.',
    'domain_key' => 'nhi_khoa',
    'reliance_reason' => 'Trẻ nhỏ dễ diễn tiến nhanh và không tự mô tả được triệu chứng; hướng dẫn sai cho phụ huynh gây rủi ro cao.',
    'topics' => [
        'Trẻ sơ sinh và nhũ nhi' => ['Chăm sóc hằng ngày', 'Bú mẹ và ăn dặm'],
        'Phát triển' => ['Phát triển thể chất', 'Phát triển ngôn ngữ và vận động', 'Giấc ngủ của trẻ'],
        'Phòng bệnh' => ['Tiêm chủng', 'Vệ sinh và an toàn trong nhà'],
        'Bệnh thường gặp ở trẻ' => ['Sốt', 'Ho và các bệnh hô hấp', 'Rối loạn tiêu hóa'],
        'Dấu hiệu nguy hiểm' => ['Khi nào cần đưa trẻ đi khám ngay'],
    ],
    'types' => [
        'cham_soc_tre_theo_tuoi' => ['Chăm sóc trẻ theo độ tuổi', ['Giai đoạn này trẻ nói chung có gì đặc trưng', 'Chăm sóc hằng ngày', 'Ăn uống và giấc ngủ', 'Mỗi trẻ phát triển nhịp riêng', 'Khi nào hỏi bác sĩ nhi']],
        'dau_hieu_nguy_hiem_o_tre' => ['Dấu hiệu nguy hiểm ở trẻ cần đi khám ngay', ['Vì sao không nên chờ đợi ở trẻ nhỏ', 'Nhóm dấu hiệu cần đưa trẻ đến cơ sở y tế', 'Chuẩn bị thông tin cho bác sĩ', 'Điều không tự làm tại nhà']],
        'benh_thuong_gap_o_tre' => ['Bệnh thường gặp ở trẻ nhỏ', ['Bệnh này nói chung là gì', 'Cha mẹ có thể quan sát điều gì', 'Chăm sóc hỗ trợ theo hướng dẫn của bác sĩ', 'Khi nào cần đi khám', 'Câu hỏi thường gặp']],
        'tiem_chung_cho_tre' => ['Tiêm chủng cho trẻ', ['Tiêm chủng để làm gì', 'Lịch tiêm do cơ sở y tế và chương trình chính thức hướng dẫn', 'Chuẩn bị trước và theo dõi sau tiêm', 'Phản ứng cần liên hệ cơ sở y tế']],
    ],
    'terms' => ['Trẻ sơ sinh', 'Nhũ nhi', 'Ăn dặm', 'Tiêm chủng', 'Phát triển thể chất', 'Phát triển vận động', 'Suy dinh dưỡng', 'Còi xương', 'Mất nước', 'Co giật do sốt', 'Viêm đường hô hấp', 'Tiêu chảy', 'Khám sức khỏe định kỳ cho trẻ'],
    'rules' => ['Mọi bài về trẻ em phải khuyến nghị tham vấn bác sĩ nhi', 'Tuyệt đối không nêu liều thuốc, cách dùng thuốc cho trẻ, kể cả thuốc không kê đơn', 'Không nêu lịch tiêm hay mốc phát triển cụ thể nếu chưa có nguồn chính thức được chuyên gia duyệt', 'Nhấn mạnh trẻ nhỏ có thể diễn tiến nhanh; dấu hiệu nguy hiểm thì hướng đưa trẻ đến cơ sở y tế, không tự xử trí', 'Không so sánh sự phát triển của các trẻ với nhau; không gây áp lực cho cha mẹ'],
    'do' => ['Trấn an phụ huynh nhưng không giảm nhẹ dấu hiệu nguy hiểm'],
    'dont' => ['Khuyên mẹo dân gian chưa có nguồn', 'Làm cha mẹ cảm thấy có lỗi'],
    'rubric' => ['Có khuyến nghị gặp bác sĩ nhi ở mỗi bài', 'Không có liều thuốc cho trẻ', 'Dấu hiệu nguy hiểm đi kèm hướng dẫn đến cơ sở y tế'],
    'sources' => ['who.int', 'moh.gov.vn', 'nhs.uk', 'cdc.gov', 'nin.gov.vn', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
    'hints' => ['Bác sĩ nhi khoa duyệt mọi bài'],
]);
