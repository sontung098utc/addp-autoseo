<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho y khoa tổng quát và sức khỏe cộng đồng. Không chứa sự kiện y khoa; thuật ngữ chỉ có tên. */
return BrainTemplates::health([
    'name' => 'Y khoa tổng quát và sức khỏe cộng đồng',
    'description' => 'Truyền thông sức khỏe chung: dấu hiệu cần đi khám, phòng bệnh, tầm soát, hiểu kết quả khám. Bắt buộc nguồn, hạng rủi ro cao.',
    'domain_key' => 'y_khoa_tong_quat',
    'reliance_reason' => 'Nội dung sức khỏe ảnh hưởng quyết định của người đọc (YMYL) nên mọi khẳng định cần nguồn và người có chuyên môn duyệt.',
    'topics' => [
        'Hiểu cơ thể và bệnh' => ['Giải thích tình trạng thường gặp', 'Yếu tố nguy cơ'],
        'Đi khám và tầm soát' => ['Dấu hiệu cần đi khám', 'Chuẩn bị trước khi khám', 'Tầm soát định kỳ'],
        'Phòng bệnh cộng đồng' => ['Tiêm chủng và phòng dịch', 'Vệ sinh và môi trường sống'],
        'Lối sống lành mạnh' => ['Vận động', 'Thói quen hằng ngày'],
    ],
    'types' => [
        'giai_thich_tinh_trang' => ['Giải thích tình trạng sức khỏe thường gặp', ['Tình trạng này là gì', 'Ai dễ gặp', 'Dấu hiệu thường được mô tả', 'Khi nào nên đi khám', 'Phòng ngừa và lối sống', 'Câu hỏi thường gặp']],
        'dau_hieu_di_kham' => ['Dấu hiệu nào nên đi khám sớm', ['Vì sao không nên chủ quan', 'Nhóm dấu hiệu cần chú ý', 'Nên đi khám ở đâu và chuẩn bị gì', 'Điều không nên tự làm tại nhà']],
        'tam_soat_dinh_ky' => ['Tầm soát và khám sức khỏe định kỳ', ['Tầm soát để làm gì', 'Nhóm người nên cân nhắc', 'Quy trình thường gặp', 'Trao đổi với bác sĩ về lịch phù hợp']],
    ],
    'terms' => ['Triệu chứng', 'Dấu hiệu', 'Yếu tố nguy cơ', 'Tầm soát', 'Chẩn đoán', 'Biến chứng', 'Bệnh mạn tính', 'Bệnh cấp tính', 'Phòng ngừa', 'Tiền sử bệnh'],
    'rules' => ['Bài giải thích tình trạng luôn có mục "khi nào nên đi khám"', 'Không dùng lời khuyên tự điều trị tại nhà thay cho thăm khám'],
    'do' => ['Dùng lời lẽ trung tính khi nói về dấu hiệu bệnh'],
    'dont' => ['Liệt kê dấu hiệu theo kiểu "nếu có là bị bệnh"'],
    'rubric' => ['Có mục khuyến nghị gặp nhân viên y tế', 'Có câu nêu giới hạn của thông tin', 'Không có lời hứa hẹn kết quả'],
    'sources' => ['who.int', 'moh.gov.vn', 'cdc.gov', 'nhs.uk', 'pubmed.ncbi.nlm.nih.gov'],
]);
