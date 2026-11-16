<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho xương khớp và vận động. Không có bài tập định lượng, thuốc hay phác đồ. */
return BrainTemplates::health([
    'name' => 'Xương khớp và vận động',
    'description' => 'Sức khỏe xương khớp, đau lưng và khớp, loãng xương, vận động an toàn, phục hồi chức năng. Bắt buộc nguồn, hạng rủi ro cao.',
    'domain_key' => 'xuong_khop',
    'reliance_reason' => 'Hướng dẫn vận động hoặc giảm đau sai có thể làm tổn thương nặng thêm; nhiều quảng cáo thổi phồng sản phẩm hỗ trợ khớp.',
    'topics' => [
        'Hiểu về xương khớp' => ['Cấu trúc cơ bản', 'Lão hóa và xương khớp'],
        'Vấn đề thường gặp' => ['Đau lưng', 'Đau khớp gối', 'Loãng xương', 'Thoái hóa khớp'],
        'Vận động an toàn' => ['Khởi động và nghỉ ngơi', 'Tư thế làm việc'],
        'Phục hồi chức năng' => ['Vật lý trị liệu', 'Phục hồi sau chấn thương'],
        'Dấu hiệu cần đi khám' => ['Đau kèm dấu hiệu thần kinh', 'Chấn thương'],
    ],
    'types' => [
        'hieu_van_de_xuong_khop' => ['Tìm hiểu một vấn đề xương khớp', ['Vấn đề này là gì', 'Yếu tố có thể liên quan', 'Chăm sóc hỗ trợ ở mức chung', 'Khi nào cần đi khám', 'Câu hỏi thường gặp']],
        'tu_the_va_van_dong' => ['Tư thế và vận động hằng ngày', ['Vì sao tư thế quan trọng', 'Thói quen làm việc và sinh hoạt', 'Vận động phù hợp theo hướng dẫn chuyên môn', 'Dấu hiệu nên dừng và hỏi bác sĩ']],
        'phong_ngua_loang_xuong' => ['Phòng ngừa loãng xương', ['Loãng xương là gì', 'Nhóm người nên chú ý', 'Lối sống liên quan đến sức khỏe xương', 'Trao đổi với bác sĩ về thăm khám']],
        'phuc_hoi_chuc_nang' => ['Phục hồi chức năng sau chấn thương hoặc phẫu thuật', ['Mục tiêu của phục hồi chức năng', 'Vai trò của kỹ thuật viên và bác sĩ', 'Kiên trì và theo dõi tiến triển', 'Dấu hiệu cần báo lại cho bác sĩ']],
    ],
    'terms' => ['Thoái hóa khớp', 'Loãng xương', 'Thoát vị đĩa đệm', 'Viêm khớp', 'Gút', 'Cột sống', 'Dây chằng', 'Sụn khớp', 'Mật độ xương', 'Vật lý trị liệu', 'Phục hồi chức năng', 'Đau thần kinh tọa'],
    'rules' => ['Không đưa bài tập hay số lần, thời lượng cụ thể; chỉ hướng người đọc đến bác sĩ hoặc kỹ thuật viên phục hồi chức năng', 'Không hứa "tái tạo sụn", "khỏi hẳn" bằng thực phẩm hay sản phẩm hỗ trợ', 'Đau kèm tê yếu, sốt, sau chấn thương hoặc kéo dài: luôn hướng đi khám', 'Không nêu thuốc giảm đau hay liều dùng', 'Không khuyên nắn chỉnh, bẻ khớp tại nhà'],
    'do' => ['Khuyến khích vận động an toàn theo hướng dẫn chuyên môn'],
    'dont' => ['Khuyên chịu đau tập tiếp', 'Gắn sản phẩm với việc chữa khớp'],
    'rubric' => ['Không có bài tập định lượng', 'Có dấu hiệu cần đi khám', 'Không có thuốc hay liều'],
    'sources' => ['who.int', 'moh.gov.vn', 'nhs.uk', 'nih.gov', 'cdc.gov', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
    'hints' => ['Bác sĩ cơ xương khớp hoặc phục hồi chức năng duyệt'],
]);
