<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho dinh dưỡng và chế độ ăn. Không có khẩu phần, calo hay liều bổ sung. */
return BrainTemplates::health([
    'name' => 'Dinh dưỡng và chế độ ăn',
    'description' => 'Dinh dưỡng cho từng nhóm người, thói quen ăn uống, đọc nhãn thực phẩm, chế độ ăn theo tình trạng sức khỏe. Bắt buộc nguồn (nhiều quan niệm sai lan truyền), hạng rủi ro cao.',
    'domain_key' => 'dinh_duong',
    'reliance_reason' => 'Dinh dưỡng có nhiều thông tin sai lan truyền và chế độ ăn cho người có bệnh nền ảnh hưởng đến sức khỏe, nên giữ mức bắt buộc nguồn.',
    'topics' => [
        'Nguyên tắc dinh dưỡng' => ['Nhóm chất dinh dưỡng', 'Bữa ăn cân bằng'],
        'Theo nhóm người' => ['Người trưởng thành', 'Người cao tuổi', 'Người vận động thể thao'],
        'Theo tình trạng sức khỏe' => ['Kiểm soát cân nặng', 'Người có bệnh mạn tính'],
        'Thực phẩm và nhãn' => ['Đọc nhãn dinh dưỡng', 'An toàn thực phẩm'],
        'Quan niệm sai thường gặp' => ['Chế độ ăn kiêng cực đoan', 'Thực phẩm "thần kỳ"'],
    ],
    'types' => [
        'nguyen_tac_an_uong' => ['Nguyên tắc ăn uống lành mạnh', ['Vì sao ăn uống quan trọng', 'Các nhóm chất dinh dưỡng', 'Xây dựng bữa ăn cân bằng', 'Thói quen cần tránh', 'Hỏi chuyên gia dinh dưỡng khi nào']],
        'che_do_an_theo_benh' => ['Chế độ ăn khi có bệnh nền', ['Vì sao cần điều chỉnh ăn uống', 'Nguyên tắc chung', 'Điều cần trao đổi với bác sĩ hoặc chuyên gia dinh dưỡng', 'Không tự áp dụng chế độ ăn kiêng cực đoan']],
        'doc_nhan_dinh_duong' => ['Đọc nhãn dinh dưỡng thực phẩm', ['Nhãn dinh dưỡng cho biết gì', 'Các mục thường có trên nhãn', 'Cách so sánh khách quan', 'Hạn chế của nhãn']],
        'giai_ma_quan_niem' => ['Giải mã quan niệm ăn uống phổ biến', ['Quan niệm đang lan truyền', 'Điều các nguồn chính thức nói', 'Vì sao dễ gây hiểu lầm', 'Lời khuyên an toàn']],
    ],
    'terms' => ['Chất đạm', 'Chất béo', 'Carbohydrate', 'Chất xơ', 'Vitamin', 'Khoáng chất', 'Năng lượng nạp vào', 'Chỉ số khối cơ thể', 'Chỉ số đường huyết của thực phẩm', 'Bữa ăn cân bằng', 'Nhãn dinh dưỡng', 'Dị ứng thực phẩm'],
    'rules' => ['Không nêu khẩu phần, số calo hay lượng chất cụ thể nếu chưa có nguồn chuyên gia duyệt', 'Không giới thiệu thực phẩm như phương thuốc chữa bệnh', 'Không khuyến khích giảm cân nhanh hay ăn kiêng cực đoan', 'Với người có bệnh nền, phụ nữ mang thai, trẻ em: luôn khuyến nghị hỏi bác sĩ hoặc chuyên gia dinh dưỡng'],
    'do' => ['Dùng lời lẽ không gây áp lực về ngoại hình'],
    'dont' => ['Gắn nhãn thực phẩm "tốt" hay "xấu" tuyệt đối', 'Hứa giảm cân trong thời gian ngắn'],
    'rubric' => ['Không có khẩu phần hay số calo khi chưa có nguồn', 'Không gọi thực phẩm là thuốc', 'Có khuyến nghị hỏi chuyên gia khi có bệnh nền'],
    'sources' => ['who.int', 'nin.gov.vn', 'moh.gov.vn', 'nih.gov', 'cdc.gov', 'nhs.uk', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
]);
