<?php

/** Tự động hóa AI (M09): đề xuất, CHƯA có trong hệ thống. Đừng chuyển sang "active" khi chưa có bằng chứng chạy thật. */
return [
    ['id' => 'ai.router', 'title' => 'Nhiều model AI và cấu hình tích hợp', 'what' => 'Chọn model theo loại việc, đặt ngân sách, khóa API ghi-không-đọc.', 'readiness' => 'idea',
        'caveat' => 'Hiện chỉ một nhà cung cấp chọn cứng trong mã. Cần bộ định tuyến model (AG-B06, Dev1).', 'task' => 'AG-B06'],
    ['id' => 'ai.selfrepair', 'title' => 'Vòng tự sửa (viết → chấm → sửa)', 'what' => 'AI tự sửa bài theo kết quả luật chất lượng, có giới hạn số vòng.', 'readiness' => 'idea',
        'caveat' => 'Chưa làm (AG-A01).', 'task' => 'AG-A01'],
    ['id' => 'pipeline.auto', 'title' => 'Pipeline tự động có điều phối', 'what' => 'Chuỗi audit → từ khóa → kế hoạch → bài chạy tự động, có dừng/chạy lại.', 'readiness' => 'idea',
        'caveat' => 'Chưa có bảng nhật ký chạy, bộ điều phối hay n8n trong hệ thống (AG-B01…B10).', 'task' => 'AG-B10'],
];
