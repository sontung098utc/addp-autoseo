<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho đái tháo đường. Không có chỉ số, ngưỡng, thuốc hay phác đồ. */
return BrainTemplates::health([
    'name' => 'Đái tháo đường (tiểu đường)',
    'description' => 'Giải thích đái tháo đường, theo dõi đường huyết, dinh dưỡng và lối sống, biến chứng, sống chung với bệnh. Bắt buộc nguồn, hạng rủi ro cao.',
    'domain_key' => 'dai_thao_duong',
    'reliance_reason' => 'Bệnh mạn tính cần theo dõi và điều trị cá nhân hóa; thông tin sai có thể ảnh hưởng an toàn người bệnh.',
    'topics' => [
        'Hiểu về bệnh' => ['Các thể bệnh', 'Yếu tố nguy cơ', 'Tiền đái tháo đường'],
        'Theo dõi' => ['Đường huyết tại nhà', 'Xét nghiệm định kỳ', 'Sổ theo dõi'],
        'Ăn uống và vận động' => ['Nguyên tắc ăn uống', 'Vận động an toàn'],
        'Biến chứng và phòng ngừa' => ['Biến chứng thường được nhắc đến', 'Chăm sóc bàn chân và mắt'],
        'Sống chung với bệnh' => ['Tâm lý người bệnh', 'Hỗ trợ từ gia đình'],
    ],
    'types' => [
        'hieu_dai_thao_duong' => ['Hiểu về đái tháo đường', ['Đái tháo đường là gì', 'Các thể thường gặp', 'Ai có yếu tố nguy cơ', 'Vì sao cần theo dõi lâu dài', 'Khi nào cần gặp bác sĩ', 'Câu hỏi thường gặp']],
        'theo_doi_duong_huyet' => ['Theo dõi đường huyết tại nhà', ['Vì sao cần theo dõi', 'Cách ghi sổ theo dõi', 'Những điều cần trao đổi với bác sĩ', 'Khi nào cần liên hệ cơ sở y tế']],
        'an_uong_nguoi_benh' => ['Ăn uống cho người đái tháo đường', ['Nguyên tắc chung', 'Nhóm thực phẩm thường được nhắc đến', 'Thói quen ăn uống cần lưu ý', 'Xây dựng thực đơn cùng chuyên gia dinh dưỡng']],
        'bien_chung_tieu_duong' => ['Biến chứng và cách phòng ngừa', ['Biến chứng là gì', 'Cơ quan thường bị ảnh hưởng', 'Dấu hiệu cần đi khám', 'Phòng ngừa bằng theo dõi và tái khám']],
    ],
    'terms' => ['Đường huyết', 'Đường huyết lúc đói', 'HbA1c', 'Insulin', 'Kháng insulin', 'Tiền đái tháo đường', 'Hạ đường huyết', 'Tăng đường huyết', 'Chỉ số khối cơ thể', 'Biến chứng thần kinh', 'Biến chứng thận', 'Bệnh võng mạc'],
    'rules' => ['Không nêu ngưỡng chỉ số hay mục tiêu điều trị nếu chưa có nguồn chuyên gia duyệt', 'Không khuyên tự ý thay đổi hoặc ngừng thuốc; luôn hướng người bệnh hỏi bác sĩ điều trị', 'Không hứa "khỏi hẳn" hay "đảo ngược bệnh" nếu chưa có nguồn xác nhận', 'Nhắc dấu hiệu cần xử trí khẩn cấp theo hướng dẫn của cơ sở y tế, không tự đưa hướng dẫn xử trí'],
    'do' => ['Dùng ngôn ngữ không phán xét về cân nặng và lối sống'],
    'dont' => ['Quy lỗi cho người bệnh', 'Đề cập loại thực phẩm "chữa" bệnh'],
    'rubric' => ['Có khuyến nghị theo dõi cùng bác sĩ', 'Không có ngưỡng chỉ số khi chưa có nguồn', 'Không nêu tên thuốc hay liều'],
    'sources' => ['who.int', 'moh.gov.vn', 'diabetes.org', 'idf.org', 'niddk.nih.gov', 'nih.gov', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
    'hints' => ['Chuyên gia nội tiết xác nhận tên thuật ngữ và nguồn'],
]);
