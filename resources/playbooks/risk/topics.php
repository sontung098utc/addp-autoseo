<?php

/**
 * AG-K12: hạng rủi ro chủ đề. Dữ liệu, không phải mã: sửa danh sách ở đây thì đổi hành vi, có test bao phủ.
 * Hạng cao = YMYL (sức khỏe, tài chính, pháp lý, an toàn). Hạng vừa = nội dung có số liệu/giá/so sánh dễ sai.
 * Từ khóa viết có dấu; bộ phân loại tự so cả dạng không dấu.
 * Nguồn tham chiếu: chính sách "nội dung chất lượng/YMYL" của Google Search (xem knowledge/RULE_VERIFICATION_2026-11-07.md).
 */
return [
    'version' => 1,
    'reviewed_at' => '2026-11-07',
    'topics' => [
        'high' => [
            'suc_khoe' => ['chữa bệnh', 'điều trị', 'triệu chứng', 'thuốc', 'liều dùng', 'ung thư', 'tiểu đường', 'đái tháo đường', 'đường huyết', 'hba1c', 'insulin', 'cholesterol', 'mỡ máu', 'huyết áp', 'mang thai', 'phẫu thuật'],
            'tai_chinh' => ['đầu tư', 'cổ phiếu', 'chứng khoán', 'tiền ảo', 'vay vốn', 'lãi suất', 'bảo hiểm', 'thuế', 'lợi nhuận đảm bảo'],
            'phap_ly' => ['luật', 'nghị định', 'khởi kiện', 'tranh chấp', 'hợp đồng lao động', 'bản quyền', 'xử phạt'],
            'an_toan' => ['an toàn điện', 'chất cháy nổ', 'hóa chất độc', 'trẻ sơ sinh'],
        ],
        'medium' => [
            'gia_ca' => ['giá bán', 'báo giá', 'khuyến mãi', 'giảm giá', 'bảng giá'],
            'so_sanh' => ['so sánh', 'tốt nhất', 'xếp hạng', 'top 10', 'đánh giá sản phẩm'],
            'so_lieu' => ['thống kê', 'nghiên cứu cho thấy', 'theo khảo sát', 'tỷ lệ'],
        ],
    ],
    // Cách nói cam kết/tuyệt đối: claim nhạy cảm, cần nguồn hoặc phải bỏ.
    'sensitive_claims' => [
        'cam_ket_ket_qua' => ['cam kết', 'đảm bảo 100%', 'chắc chắn khỏi', 'hiệu quả tuyệt đối', 'không tác dụng phụ'],
        'tuyet_doi' => ['số 1 việt nam', 'duy nhất trên thị trường', 'tốt nhất thế giới'],
        'so_lieu_tran' => ['100%'],
    ],
];
