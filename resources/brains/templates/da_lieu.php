<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho da liễu và chăm sóc da. Không có hoạt chất, nồng độ hay phác đồ. */
return BrainTemplates::health([
    'name' => 'Da liễu và chăm sóc da',
    'description' => 'Chăm sóc da cơ bản, vấn đề da thường gặp, bảo vệ da, khi nào đi khám da liễu. Bắt buộc nguồn (lĩnh vực nhiều thông tin quảng cáo), hạng rủi ro cao.',
    'domain_key' => 'da_lieu',
    'reliance_reason' => 'Chăm sóc da thông thường rủi ro thấp hơn nhưng dễ trượt sang quảng cáo mỹ phẩm hoặc chẩn đoán bệnh da; giữ mức bắt buộc nguồn.',
    'topics' => [
        'Chăm sóc da cơ bản' => ['Làm sạch', 'Dưỡng ẩm', 'Chống nắng'],
        'Vấn đề da thường gặp' => ['Mụn', 'Da khô và da nhạy cảm', 'Viêm da và dị ứng da'],
        'Dấu hiệu cần đi khám' => ['Thay đổi bất thường trên da'],
        'Mỹ phẩm và quảng cáo' => ['Đọc thành phần', 'Quy định quảng cáo mỹ phẩm'],
    ],
    'types' => [
        'quy_trinh_cham_soc_da' => ['Quy trình chăm sóc da cơ bản', ['Vì sao cần chăm sóc da', 'Các bước nền tảng', 'Điều chỉnh theo loại da một cách khái quát', 'Khi nào hỏi bác sĩ da liễu']],
        'van_de_da_thuong_gap' => ['Tìm hiểu một vấn đề da thường gặp', ['Vấn đề này là gì', 'Yếu tố có thể liên quan', 'Chăm sóc hỗ trợ ở mức chung', 'Dấu hiệu cần đi khám', 'Câu hỏi thường gặp']],
        'chong_nang_bao_ve_da' => ['Bảo vệ da khỏi ánh nắng', ['Vì sao cần bảo vệ da', 'Các biện pháp bảo vệ', 'Lưu ý với trẻ em và da nhạy cảm', 'Dấu hiệu bất thường cần đi khám']],
        'doc_thanh_phan_my_pham' => ['Đọc thành phần mỹ phẩm chăm sóc da', ['Nhãn có những thông tin nào', 'Cách đọc thông tin chung', 'Giới hạn của việc đọc thành phần', 'Hỏi bác sĩ da liễu khi nào']],
    ],
    'terms' => ['Hàng rào bảo vệ da', 'Dưỡng ẩm', 'Chống nắng', 'Da nhạy cảm', 'Mụn trứng cá', 'Viêm da cơ địa', 'Dị ứng da', 'Nốt ruồi', 'Sẹo', 'Sắc tố da', 'Thành phần mỹ phẩm', 'Kích ứng da'],
    'rules' => ['Không chẩn đoán bệnh da qua mô tả hay hình ảnh; thay đổi bất thường trên da (nốt ruồi, vết loét lâu lành) luôn hướng đi khám da liễu', 'Không nêu hoạt chất, nồng độ hay thuốc bôi cụ thể nếu chưa có nguồn chuyên gia duyệt', 'Không hứa hẹn "trắng da", "hết mụn", "trẻ hóa" nhanh chóng; không dùng ảnh trước và sau như bằng chứng', 'Không giới thiệu mỹ phẩm như thuốc điều trị', 'Với trẻ em, phụ nữ mang thai, da đang tổn thương: khuyến nghị hỏi bác sĩ'],
    'do' => ['Khuyến khích thói quen đơn giản, bền vững'],
    'dont' => ['Tạo áp lực về ngoại hình', 'Dùng lời hứa làm đẹp nhanh'],
    'rubric' => ['Không có chẩn đoán bệnh da', 'Không có hoạt chất hay nồng độ khi chưa có nguồn', 'Không hứa hẹn kết quả'],
    'sources' => ['who.int', 'nhs.uk', 'nih.gov', 'cdc.gov', 'moh.gov.vn', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
    'hints' => ['Bác sĩ da liễu duyệt'],
]);
