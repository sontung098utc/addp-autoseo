<?php

use App\Services\Agent\Run\AgentRunOrchestrator;

/** Làm mới bài: mắt xích 3, 4, 5 cho bài đã có. Hôm nay chạy như article_write. */
return [
    'id' => 'article_refresh',
    'title' => 'Làm mới bài',
    'link' => 'write',
    'also' => ['research', 'review'],
    'order' => 50,
    'status' => 'active',
    'summary' => 'Hôm nay chạy như article_write (nhánh viết lại, 4c) trên bài kind=refresh. Chưa có: phân loại phạm vi sửa theo mã lỗi, nhánh 4a (chỉ sinh lại tiêu đề SEO và meta) và 4b (chỉ sửa mục có lỗi) vì cần phương thức AI mới ở tầng nhà cung cấp (lõi của Dev1); chưa có so sánh trước/sau và cổng "điểm sau sửa không thấp hơn trước". Chưa kiểm được trong mã: việc đánh dấu audit_suggestions.status=da_xong sau khi bàn giao.',
    'reads' => ['articles (kind=refresh)', 'audit_suggestions.issue_codes', 'phiên bản hiện tại'],
    'writes' => ['article_versions (phiên bản mới)', 'agent_run_steps', 'audit_suggestions.status=da_xong'],
    'shown_at' => ['Trang bài: tab Nội dung (phiên bản mới, so sánh trước/sau)', 'Trang Audit: đề xuất chuyển "đã xong"'],
    'used_by' => ['human'],
    'steps' => [
        ['id' => 'guard', 'label' => 'An toàn', 'kind' => 'rule'],
        ['id' => 'grade_old', 'label' => 'Chấm bản hiện tại', 'kind' => 'rule', 'planned' => true],
        ['id' => 'scope', 'label' => 'Phân loại phạm vi sửa', 'kind' => 'rule', 'planned' => true, 'when' => '(a) tiêu đề và meta, (b) một số mục, (c) viết lại'],
        ['id' => 'meta', 'label' => 'Sinh lại siêu dữ liệu', 'kind' => 'llm', 'task' => 'meta_regen', 'planned' => true, 'when' => 'Nhánh 4a; bậc rẻ; cần phương thức AI mới'],
        ['id' => 'section', 'label' => 'Sửa các mục có lỗi', 'kind' => 'llm', 'task' => 'section_repair', 'planned' => true, 'when' => 'Nhánh 4b; bậc chuẩn; chỉ gửi mục cần sửa'],
        ['id' => 'rewrite', 'label' => 'Viết lại', 'kind' => 'llm', 'task' => 'write', 'when' => 'Nhánh 4c: chạy như article_write'],
        ['id' => 'grade', 'label' => 'Chấm lại', 'kind' => 'rule'],
        ['id' => 'handoff', 'label' => 'Bàn giao phiên bản mới', 'kind' => 'rule'],
    ],
    'budget' => 'Token tương đối 0,2–1,0',
    'human' => ['Người duyệt xem so sánh trước/sau; điểm sau sửa không thấp hơn trước'],
    'features' => [],
    'runner' => AgentRunOrchestrator::class,
];
