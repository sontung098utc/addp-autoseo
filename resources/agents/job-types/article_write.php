<?php

use App\Services\Agent\Run\AgentRunOrchestrator;

/** Viết bài: phục vụ mắt xích 3 (Nghiên cứu và nguồn), 4 (Viết), 5 (Kiểm và duyệt). Lớp chạy hiện có: AgentRunOrchestrator. */
return [
    'id' => 'article_write',
    'title' => 'Viết bài',
    'link' => 'write',
    'also' => ['research', 'review'],
    'order' => 40,
    'status' => 'active',
    'summary' => 'Từ một bài đã có từ khóa: lập dàn ý, chuẩn bị tài liệu, viết, chấm bằng luật, sửa, bàn giao bản nháp. Không có cổng dừng: tự gắn Trí tuệ khớp chủ đề; bài rủi ro cao mà kho chưa có kiến thức đã duyệt thì AI tự đọc tối đa 5 trang trên website công ty để bổ sung (trần chi phí ≤ 10% ngân sách phiên), rồi vẫn viết; còn trống thì bài được gắn nhãn cần chuyên gia xem lại sau khi viết. Sau khi lưu bản nháp, bước Tạo ảnh (luật, trần 10% ngân sách) chỉ tạo ảnh khi website chưa có ảnh bìa từ thư viện. Chiến dịch chỉ là cách hẹn giờ để gọi loại việc này.',
    'reads' => ['articles', 'knowledge_items (đã duyệt)', 'brand_profiles', 'brain_sources', 'media_assets'],
    'writes' => ['agent_runs', 'agent_run_steps', 'article_versions (kèm sources_cited)', 'knowledge_item_usages', 'article_version_media', 'knowledge_gaps'],
    'shown_at' => ['Trang bài: tab Nghiên cứu và nguồn', 'Trang bài: tab Nội dung', 'Trang bài: tab Hoạt động', 'Trang Phiên chạy'],
    'used_by' => ['human', 'brain_build'],
    'steps' => [
        ['id' => 'guard', 'role' => 'system', 'label' => 'Kiểm điều kiện', 'kind' => 'rule'],
        ['id' => 'risk', 'role' => 'editor', 'label' => 'Xét rủi ro', 'kind' => 'rule'],
        ['id' => 'brief', 'role' => 'strategist', 'label' => 'Lập dàn ý', 'kind' => 'llm', 'task' => 'outline', 'when' => 'Bỏ qua nếu vai trò Chiến lược tắt. Dàn ý kèm KẾ HOẠCH ẢNH (cần ảnh nào, đặt sau mục nào, mô tả ảnh) do vai trò Họa viên soạn bằng luật, không phải bước riêng'],
        ['id' => 'research', 'role' => 'writer', 'label' => 'Nghiên cứu', 'kind' => 'rule', 'when' => 'Chạy sau dàn ý, trước bước viết; nạp kiến thức, ảnh, liên kết nội bộ, nguồn được trích. Rủi ro cao mà kho trống: trước đó tự đọc tối đa 5 trang website'],
        ['id' => 'write', 'role' => 'writer', 'label' => 'Viết', 'kind' => 'llm', 'task' => 'write'],
        ['id' => 'images', 'role' => 'designer', 'label' => 'Tạo ảnh', 'kind' => 'rule', 'when' => 'Chạy song song với bước viết theo kế hoạch ảnh (trần chi phí ảnh theo ngân sách phiên); ô lỗi được thử lại một lần'],
        ['id' => 'grade', 'role' => 'editor', 'label' => 'Chấm điểm', 'kind' => 'rule'],
        ['id' => 'repair', 'role' => 'writer', 'label' => 'Sửa', 'kind' => 'llm', 'task' => 'repair', 'when' => 'Chỉ khi còn lỗi chặn; tối đa 3 vòng'],
        ['id' => 'assemble', 'role' => 'system', 'label' => 'Lắp ráp', 'kind' => 'rule', 'when' => 'Sau khi bài đạt: ghép ảnh vào bài theo kế hoạch (ảnh bìa gắn làm ảnh đại diện, ảnh trong bài chèn sau mục tương ứng)'],
        ['id' => 'handoff', 'human' => true, 'role' => 'system', 'label' => 'Bàn giao', 'kind' => 'rule'],
    ],
    'budget' => 'Một con số cho cả phiên (RunPolicy), kiểm trước mỗi vòng sửa; trần mỗi lời gọi',
    'human' => ['Người duyệt bản nháp', 'Chủ đề rủi ro cao hoặc bắt buộc nguồn: người có chuyên môn', 'Bài rủi ro cao mà kho trống: AI vẫn viết sau khi tự đọc website; chuyên gia xem lại bản nháp, duyệt các mục kiến thức chờ duyệt'],
    'features' => ['run.slice', 'content.generate', 'content.quality', 'ai.selfrepair'],
    'runner' => AgentRunOrchestrator::class,
    'records' => [
        ['label' => 'Kế hoạch nội dung', 'class' => 'App\\Filament\\Pages\\ContentPlanPage', 'type' => 'input'],
        ['label' => 'Bài viết', 'class' => 'App\\Filament\\Resources\\ArticleResource', 'type' => 'output'],
        ['label' => 'Chi phí AI', 'class' => 'App\\Filament\\Pages\\AiUsagePage', 'type' => 'report'],
        ['label' => 'Phiên chạy', 'class' => 'App\\Filament\\Resources\\AgentRunResource', 'type' => 'report', 'filter_job_type' => true],
    ],
];
