<?php

/** Tự động hóa AI (M09): đề xuất, CHƯA có trong hệ thống. Đừng chuyển sang "active" khi chưa có bằng chứng chạy thật. */
return [
    ['id' => 'ai.router', 'title' => 'Nhiều model AI và cấu hình tích hợp', 'what' => 'Chọn model theo loại việc, đặt ngân sách, khóa API ghi-không-đọc.', 'readiness' => 'idea',
        'caveat' => 'Hiện chỉ một nhà cung cấp chọn cứng trong mã. Cần bộ định tuyến model (AG-B06, Dev1).', 'task' => 'AG-B06'],
    ['id' => 'ai.selfrepair', 'title' => 'Vòng tự sửa (viết → chấm → sửa)', 'what' => 'AI tự sửa bài theo kết quả luật chất lượng, có giới hạn số vòng.', 'readiness' => 'in_development',
        'caveat' => 'Chạy được với AI giả lập trong test và nút "Giao đội AI". Chưa thử với model thật, chưa qua staging, chưa có người thật nghiệm thu (AG-S01).', 'task' => 'AG-S01', 'route' => \App\Filament\Resources\AgentRunResource::class],
    ['id' => 'run.slice', 'title' => 'Phiên chạy của đội AI (lát dọc)', 'what' => 'Một bài đi qua: an toàn → rủi ro → dàn ý → viết → chấm → sửa → bản nháp, có nhật ký lý do.', 'readiness' => 'in_development',
        'caveat' => 'Mới kiểm bằng AI giả lập, chưa thử với model thật. Chạy đồng bộ, chưa qua hàng đợi; chỉ tạo bản nháp (T1). Bảng nhật ký là bản đề xuất cho Dev1 rà (AG-B01/B03).', 'task' => 'AG-S01', 'route' => \App\Filament\Resources\AgentRunResource::class],
    ['id' => 'campaign.auto', 'title' => 'Chiến dịch tự giao việc', 'what' => 'Đặt từ khóa và giới hạn bài/ngày; hệ thống tự giao từng bài cho đội AI theo lịch 15 phút.', 'readiness' => 'in_development',
        'caveat' => 'Mới kiểm bằng AI giả lập trong test. Lượt tự động cần khóa AI thật, thiếu thì không chạy. Chưa có hàng đợi (AG-B10); bảng chiến dịch là bản đề xuất cho Dev1 rà.', 'task' => 'AG-S01', 'route' => \App\Filament\Resources\CampaignResource::class],
    ['id' => 'pipeline.auto', 'title' => 'Pipeline tự động có điều phối', 'what' => 'Chuỗi audit → từ khóa → kế hoạch → bài chạy tự động, có dừng/chạy lại.', 'readiness' => 'idea',
        'caveat' => 'Chưa có bảng nhật ký chạy, bộ điều phối hay n8n trong hệ thống (AG-B01…B10).', 'task' => 'AG-B10'],
];
