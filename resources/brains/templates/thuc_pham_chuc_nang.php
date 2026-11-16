<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho thực phẩm bảo vệ sức khỏe. Không có thành phần, công dụng hay liều cụ thể. */
return BrainTemplates::health([
    'name' => 'Thực phẩm bảo vệ sức khỏe',
    'description' => 'Thực phẩm bảo vệ sức khỏe (thực phẩm chức năng): cách hiểu đúng, đọc nhãn, quy định quảng cáo, lưu ý khi dùng. Bắt buộc nguồn, hạng rủi ro cao.',
    'domain_key' => 'thuc_pham_chuc_nang',
    'reliance_reason' => 'Quảng cáo loại sản phẩm này chịu quy định chặt và dễ bị hiểu nhầm là thuốc; sai sót gây hại cho người đọc và rủi ro pháp lý cho website.',
    'topics' => [
        'Hiểu đúng về sản phẩm' => ['Phân biệt với thuốc', 'Phân biệt với thực phẩm thông thường'],
        'Đọc nhãn và công bố' => ['Thông tin trên nhãn', 'Giấy tờ công bố sản phẩm'],
        'Dùng an toàn' => ['Ai nên hỏi ý kiến bác sĩ trước', 'Dấu hiệu bất thường khi dùng'],
        'Quy định quảng cáo' => ['Điều được và không được nói', 'Cách ghi miễn trừ trách nhiệm'],
    ],
    'types' => [
        'phan_biet_voi_thuoc' => ['Thực phẩm bảo vệ sức khỏe khác thuốc ở điểm nào', ['Hai khái niệm khác nhau', 'Vì sao không thay thế thuốc', 'Cách hiểu đúng về công dụng', 'Hỏi bác sĩ hoặc dược sĩ khi nào']],
        'doc_nhan_thuc_pham_bao_ve' => ['Đọc nhãn thực phẩm bảo vệ sức khỏe', ['Các mục thường có trên nhãn', 'Thông tin công bố sản phẩm', 'Dấu hiệu cần kiểm tra thêm', 'Cách lưu giữ thông tin']],
        'dung_an_toan_tpbvsk' => ['Dùng thực phẩm bảo vệ sức khỏe an toàn', ['Cân nhắc trước khi dùng', 'Nhóm người cần hỏi ý kiến bác sĩ', 'Dấu hiệu cần ngừng và đi khám', 'Không dùng thay thế điều trị']],
        'canh_bao_quang_cao' => ['Nhận biết quảng cáo thổi phồng', ['Những lời quảng cáo cần dè chừng', 'Vì sao lời hứa tuyệt đối là cảnh báo', 'Cách kiểm tra thông tin', 'Nơi phản ánh khi nghi ngờ']],
    ],
    'terms' => ['Thực phẩm bảo vệ sức khỏe', 'Thực phẩm bổ sung', 'Thành phần', 'Phụ gia', 'Công bố sản phẩm', 'Hạn sử dụng', 'Cảnh báo trên nhãn', 'Đối tượng sử dụng', 'Miễn trừ trách nhiệm', 'Quảng cáo thực phẩm'],
    'rules' => ['KHÔNG giới thiệu sản phẩm như thuốc hay phương tiện chữa bệnh; luôn nêu rõ đây không phải thuốc và không có tác dụng thay thế thuốc chữa bệnh', 'Không dùng các từ "điều trị", "chữa khỏi", "đặc trị", "thần dược", "tốt nhất"', 'Chỉ nêu công dụng nếu có trong giấy công bố sản phẩm được chuyên gia hoặc bộ phận pháp lý xác nhận', 'Bắt buộc kèm lưu ý "sản phẩm này không phải là thuốc và không có tác dụng thay thế thuốc chữa bệnh" theo quy định quảng cáo hiện hành, do bộ phận pháp lý xác nhận câu chữ', 'Không so sánh sản phẩm với thuốc hay với sản phẩm khác theo kiểu quảng cáo'],
    'do' => ['Viết trung thực về giới hạn của sản phẩm'],
    'dont' => ['Dùng lời chứng thực hay trải nghiệm cá nhân để thay bằng chứng'],
    'rubric' => ['Không có từ cấm về điều trị hay chữa khỏi', 'Có câu lưu ý không phải thuốc', 'Công dụng nêu ra đều có nguồn'],
    'sources' => ['moh.gov.vn', 'dav.gov.vn', 'who.int', 'nin.gov.vn', 'nih.gov', 'pubmed.ncbi.nlm.nih.gov'],
    'hints' => ['Bộ phận pháp lý rà quy định quảng cáo hiện hành trước khi dùng mẫu'],
]);
