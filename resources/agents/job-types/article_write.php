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
    'summary' => 'Từ một bài đã có từ khóa: lập dàn ý, chuẩn bị tài liệu, viết, chấm bằng luật, sửa, bàn giao bản nháp. Chiến dịch chỉ là cách hẹn giờ để gọi loại việc này.',
    'reads' => ['articles', 'knowledge_items (đã duyệt)', 'brand_profiles', 'brain_sources', 'media_assets'],
    'writes' => ['agent_runs', 'agent_run_steps', 'article_versions (kèm sources_cited)', 'knowledge_item_usages', 'article_version_media', 'knowledge_gaps'],
    'shown_at' => ['Trang bài: tab Nghiên cứu và nguồn', 'Trang bài: tab Nội dung', 'Trang bài: tab Hoạt động', 'Trang Phiên chạy'],
    'used_by' => ['human', 'brain_build'],
    'steps' => [
        ['id' => 'guard', 'label' => 'Kiểm điều kiện', 'kind' => 'rule'],
        ['id' => 'risk', 'label' => 'Xét rủi ro', 'kind' => 'rule'],
        ['id' => 'brief', 'label' => 'Lập dàn ý', 'kind' => 'llm', 'task' => 'outline', 'when' => 'Bỏ qua nếu vai trò Chiến lược tắt'],
        ['id' => 'research', 'label' => 'Nghiên cứu', 'kind' => 'rule', 'planned' => true, 'when' => 'G4: tách khỏi bước viết; hiện kiến thức nạp trong bước viết'],
        ['id' => 'write', 'label' => 'Viết', 'kind' => 'llm', 'task' => 'write'],
        ['id' => 'grade', 'label' => 'Chấm điểm', 'kind' => 'rule'],
        ['id' => 'repair', 'label' => 'Sửa', 'kind' => 'llm', 'task' => 'repair', 'when' => 'Chỉ khi còn lỗi chặn; tối đa 3 vòng'],
        ['id' => 'handoff', 'label' => 'Bàn giao', 'kind' => 'rule'],
    ],
    'budget' => 'Một con số cho cả phiên (RunPolicy), kiểm trước mỗi vòng sửa; trần mỗi lời gọi',
    'human' => ['Người duyệt bản nháp', 'Chủ đề rủi ro cao hoặc bắt buộc nguồn: người có chuyên môn'],
    'features' => ['run.slice', 'content.generate', 'content.quality', 'ai.selfrepair'],
    'runner' => AgentRunOrchestrator::class,
];
