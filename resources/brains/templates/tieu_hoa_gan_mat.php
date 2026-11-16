<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho tiêu hóa và gan mật. Không có chỉ số xét nghiệm, thuốc hay phác đồ. */
return BrainTemplates::health([
    'name' => 'Tiêu hóa và gan mật',
    'description' => 'Sức khỏe đường tiêu hóa, dạ dày, đại tràng, gan, mật, xét nghiệm và nội soi, dấu hiệu cần đi khám. Bắt buộc nguồn, hạng rủi ro cao.',
    'domain_key' => 'tieu_hoa_gan_mat',
    'reliance_reason' => 'Một số dấu hiệu tiêu hóa là cảnh báo bệnh nặng; thông tin sai làm chậm thăm khám. Nhiều sản phẩm "giải độc gan" được quảng cáo thổi phồng.',
    'topics' => [
        'Dạ dày và thực quản' => ['Triệu chứng thường gặp', 'Thói quen ăn uống'],
        'Đại tràng và ruột' => ['Rối loạn tiêu hóa', 'Tầm soát đại trực tràng'],
        'Gan' => ['Bệnh gan thường gặp', 'Rượu bia và gan', 'Viêm gan virus'],
        'Mật và tụy' => ['Sỏi mật', 'Các triệu chứng cần chú ý'],
        'Thăm khám' => ['Nội soi', 'Xét nghiệm chức năng gan'],
    ],
    'types' => [
        'hieu_van_de_tieu_hoa' => ['Tìm hiểu một vấn đề tiêu hóa thường gặp', ['Vấn đề này là gì', 'Yếu tố có thể liên quan', 'Thói quen hỗ trợ ở mức chung', 'Dấu hiệu cần đi khám', 'Câu hỏi thường gặp']],
        'hieu_ve_gan' => ['Hiểu về sức khỏe gan', ['Vai trò của gan', 'Yếu tố ảnh hưởng đến gan', 'Quan niệm về giải độc gan cần cẩn trọng', 'Khi nào nên thăm khám']],
        'noi_soi_va_xet_nghiem_tieu_hoa' => ['Hiểu nội soi và xét nghiệm tiêu hóa', ['Thủ thuật hoặc xét nghiệm để làm gì', 'Chuẩn bị theo hướng dẫn của cơ sở y tế', 'Điều gì xảy ra nói chung', 'Trao đổi kết quả với bác sĩ']],
        'dau_hieu_tieu_hoa_bao_dong' => ['Dấu hiệu tiêu hóa cần đi khám sớm', ['Vì sao không nên chủ quan', 'Nhóm dấu hiệu cần chú ý', 'Nên đi khám ở đâu', 'Điều không nên tự làm']],
    ],
    'terms' => ['Trào ngược dạ dày thực quản', 'Viêm dạ dày', 'Loét dạ dày', 'Vi khuẩn H. pylori', 'Hội chứng ruột kích thích', 'Polyp đại tràng', 'Gan nhiễm mỡ', 'Viêm gan virus', 'Xơ gan', 'Men gan', 'Sỏi mật', 'Nội soi tiêu hóa'],
    'rules' => ['Không đọc hay diễn giải chỉ số xét nghiệm của người đọc, không nêu ngưỡng nếu chưa có nguồn chuyên gia duyệt', 'Không quảng cáo sản phẩm "giải độc gan", "thải độc" hay "bổ gan" như phương thuốc', 'Dấu hiệu như đau kéo dài, nôn ra máu, đi ngoài phân bất thường, vàng da: luôn hướng đến đi khám sớm', 'Không nêu thuốc dạ dày hay thuốc gan cụ thể và liều', 'Với rượu bia: thông tin trung thực, không phán xét'],
    'do' => ['Giảm bớt sự xấu hổ khi nói về triệu chứng tiêu hóa'],
    'dont' => ['Gợi ý tự chẩn đoán qua màu sắc phân hay triệu chứng'],
    'rubric' => ['Có dấu hiệu cần đi khám', 'Không có sản phẩm giải độc', 'Không có ngưỡng xét nghiệm khi chưa có nguồn'],
    'sources' => ['who.int', 'moh.gov.vn', 'nhs.uk', 'nih.gov', 'niddk.nih.gov', 'cdc.gov', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
    'hints' => ['Bác sĩ tiêu hóa gan mật duyệt'],
]);
