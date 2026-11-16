<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho dược và sử dụng thuốc an toàn. Không có tên thuốc, liều, tương tác cụ thể. */
return BrainTemplates::health([
    'name' => 'Dược và sử dụng thuốc an toàn',
    'description' => 'Nguyên tắc dùng thuốc an toàn, đọc hướng dẫn sử dụng, bảo quản, thuốc kê đơn và không kê đơn, tương tác và tác dụng không mong muốn. Bắt buộc nguồn, hạng rủi ro cao.',
    'domain_key' => 'duoc_thuoc',
    'reliance_reason' => 'Thông tin về thuốc sai có thể gây hại trực tiếp; liều và chỉ định chỉ do người có chuyên môn quyết định.',
    'topics' => [
        'Dùng thuốc an toàn' => ['Thuốc kê đơn và không kê đơn', 'Đọc hướng dẫn sử dụng', 'Bảo quản thuốc'],
        'Tương tác và tác dụng không mong muốn' => ['Tương tác thuốc', 'Dị ứng thuốc', 'Phản ứng bất thường cần báo'],
        'Nhóm người cần thận trọng' => ['Trẻ em', 'Phụ nữ mang thai và cho con bú', 'Người cao tuổi'],
        'Quy định' => ['Quảng cáo thuốc', 'Thuốc giả và nguồn gốc thuốc'],
    ],
    'types' => [
        'dung_thuoc_an_toan' => ['Nguyên tắc dùng thuốc an toàn', ['Vì sao dùng thuốc đúng cách quan trọng', 'Đọc kỹ hướng dẫn sử dụng', 'Những điều không tự làm', 'Hỏi dược sĩ hoặc bác sĩ khi nào', 'Bảo quản và xử lý thuốc hết hạn']],
        'nhom_thuoc_tong_quan' => ['Tổng quan một nhóm thuốc', ['Nhóm thuốc này nhằm mục đích gì ở mức khái quát', 'Ai quyết định việc sử dụng', 'Lưu ý chung về an toàn', 'Trao đổi với bác sĩ hoặc dược sĩ']],
        'tac_dung_khong_mong_muon' => ['Tác dụng không mong muốn và tương tác', ['Tác dụng không mong muốn là gì', 'Vì sao có tương tác', 'Khi nào cần liên hệ cơ sở y tế', 'Cách thông tin đầy đủ cho bác sĩ và dược sĩ']],
        'nhan_biet_thuoc_gia' => ['Nhận biết nguồn thuốc đáng tin', ['Vì sao nguồn gốc quan trọng', 'Mua thuốc ở đâu', 'Dấu hiệu cần kiểm tra thêm', 'Cách báo cáo khi nghi ngờ']],
    ],
    'terms' => ['Thuốc kê đơn', 'Thuốc không kê đơn', 'Hoạt chất', 'Dạng bào chế', 'Chỉ định', 'Chống chỉ định', 'Tương tác thuốc', 'Tác dụng không mong muốn', 'Dị ứng thuốc', 'Hạn dùng', 'Kháng thuốc', 'Dược sĩ lâm sàng'],
    'rules' => ['Tuyệt đối không nêu liều dùng, khoảng cách giữa các lần dùng hay thời gian dùng', 'Không nêu tên thuốc cụ thể hay khuyên chọn thuốc cụ thể trong nội dung mẫu; mọi nhắc đến thuốc phải qua kiểm tra của dược sĩ', 'Không so sánh thuốc theo kiểu quảng cáo, không dùng từ "tốt nhất", "hiệu quả nhất"', 'Luôn nhắc đọc hướng dẫn sử dụng và hỏi bác sĩ hoặc dược sĩ trước khi dùng, nhất là với trẻ em, phụ nữ mang thai, người cao tuổi', 'Không khuyên tự ngừng thuốc đang được kê'],
    'do' => ['Nhấn mạnh vai trò của dược sĩ và bác sĩ'],
    'dont' => ['Mô tả thuốc như sản phẩm tiêu dùng thông thường'],
    'rubric' => ['Không có liều hay lịch dùng', 'Không có so sánh kiểu quảng cáo', 'Có nhắc hỏi bác sĩ hoặc dược sĩ'],
    'sources' => ['dav.gov.vn', 'moh.gov.vn', 'who.int', 'nih.gov', 'cdc.gov', 'nhs.uk', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
    'hints' => ['Dược sĩ có chứng chỉ hành nghề duyệt mọi bài trước khi dùng'],
]);
