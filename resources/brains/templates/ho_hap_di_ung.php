<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho hô hấp và dị ứng. Không có chỉ số, thuốc hay phác đồ. */
return BrainTemplates::health([
    'name' => 'Hô hấp và dị ứng',
    'description' => 'Sức khỏe đường thở, bệnh hô hấp thường gặp, hen, dị ứng, môi trường và ô nhiễm không khí, dấu hiệu khó thở. Bắt buộc nguồn, hạng rủi ro cao.',
    'domain_key' => 'ho_hap_di_ung',
    'reliance_reason' => 'Khó thở và dị ứng nặng có thể nguy hiểm đến tính mạng; hướng dẫn chậm trễ hoặc sai gây hậu quả nghiêm trọng.',
    'topics' => [
        'Bệnh hô hấp thường gặp' => ['Cảm lạnh và cúm', 'Viêm đường hô hấp', 'Hen', 'Bệnh phổi mạn tính'],
        'Dị ứng' => ['Dị ứng theo mùa', 'Dị ứng thức ăn', 'Dị ứng thuốc'],
        'Môi trường' => ['Ô nhiễm không khí', 'Bụi và nấm mốc trong nhà'],
        'Phòng bệnh' => ['Vệ sinh hô hấp', 'Tiêm phòng', 'Bỏ thuốc lá'],
        'Dấu hiệu cấp cứu' => ['Khó thở', 'Phản ứng dị ứng nặng'],
    ],
    'types' => [
        'hieu_benh_ho_hap' => ['Tìm hiểu một bệnh hô hấp thường gặp', ['Bệnh này là gì', 'Yếu tố có thể liên quan', 'Chăm sóc hỗ trợ ở mức chung', 'Dấu hiệu cần đi khám', 'Câu hỏi thường gặp']],
        'song_chung_voi_di_ung' => ['Sống chung với dị ứng', ['Dị ứng là gì', 'Nhận biết tác nhân khởi phát ở mức chung', 'Xây dựng kế hoạch cùng bác sĩ', 'Phản ứng nặng cần cấp cứu']],
        'moi_truong_va_ho_hap' => ['Môi trường sống và sức khỏe hô hấp', ['Vì sao không khí ảnh hưởng đến hô hấp', 'Nguồn ô nhiễm trong nhà và ngoài trời', 'Biện pháp giảm tiếp xúc', 'Nhóm người cần đặc biệt lưu ý']],
        'dau_hieu_kho_tho_cap_cuu' => ['Dấu hiệu khó thở và dị ứng nặng cần cấp cứu', ['Vì sao cần hành động nhanh', 'Nhóm dấu hiệu cần gọi cấp cứu', 'Nên làm gì theo hướng dẫn chính thức', 'Không tự điều trị thay cho cấp cứu']],
    ],
    'terms' => ['Hen phế quản', 'Viêm phế quản', 'Viêm phổi', 'Bệnh phổi tắc nghẽn mạn tính', 'Viêm mũi dị ứng', 'Dị nguyên', 'Sốc phản vệ', 'Khó thở', 'Đo chức năng hô hấp', 'Bụi mịn', 'Dị ứng thức ăn'],
    'rules' => ['Dấu hiệu khó thở nặng hoặc phản ứng dị ứng nặng: chỉ hướng gọi cấp cứu hoặc đến cơ sở y tế, không hướng dẫn tự xử trí thay cấp cứu', 'Không nêu thuốc giãn phế quản, thuốc dị ứng hay liều; việc dùng thuốc theo chỉ định của bác sĩ', 'Không khẳng định sản phẩm lọc không khí hay thực phẩm "chữa" hen hay dị ứng', 'Không nêu ngưỡng chất lượng không khí nếu chưa có nguồn chính thức được chuyên gia duyệt', 'Với trẻ em và người có bệnh phổi nền: luôn khuyến nghị hỏi bác sĩ'],
    'do' => ['Giữ giọng bình tĩnh khi nói về tình huống khẩn cấp'],
    'dont' => ['Làm giảm mức nghiêm trọng của khó thở', 'Gắn sản phẩm với chữa dị ứng'],
    'rubric' => ['Dấu hiệu cấp cứu kèm hướng dẫn gọi cấp cứu', 'Không có thuốc hay liều', 'Có khuyến nghị gặp bác sĩ'],
    'sources' => ['who.int', 'moh.gov.vn', 'cdc.gov', 'nhs.uk', 'nih.gov', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
    'hints' => ['Bác sĩ hô hấp hoặc dị ứng miễn dịch duyệt'],
]);
