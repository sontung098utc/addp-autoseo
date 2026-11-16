<?php

use App\Services\Agent\Refresh\ArticleRefreshRunner;

/** Làm mới bài: mắt xích 3, 4, 5 cho bài đã có. Hôm nay chạy như article_write. */
return [
    'id' => 'article_refresh',
    'title' => 'Làm mới bài',
    'link' => 'write',
    'also' => ['research', 'review'],
    'order' => 50,
    'status' => 'active',
    'summary' => 'Làm mới bài sửa MỘT PHẦN. Đã có: chấm bản hiện tại, phân loại phạm vi theo luật (tiêu đề/meta, một số mục, viết lại), sinh lại tiêu đề SEO và meta, sửa chỉ các mục có lỗi, cổng "điểm sau không thấp hơn trước và lỗi P0 không tăng" (vi phạm thì lưu nháp, không vào hàng chờ duyệt), so sánh trước/sau ở tab Nội dung. Nhánh viết lại và nhánh AI không hỗ trợ sửa một phần chạy như article_write. Chưa làm: chưa chạy AI thật nên chưa đo mức tiết kiệm token thật; bảng ánh xạ lỗi → phạm vi chưa hiệu chỉnh trên dữ liệu thật; chưa thử lại khi AI lỗi tạm thời ở nhánh sửa một phần. Chưa kiểm được trong mã: việc đánh dấu audit_suggestions.status=da_xong sau khi bàn giao (chưa nơi nào làm).',
    'reads' => ['articles (kind=refresh)', 'audit_suggestions.issue_codes', 'phiên bản hiện tại'],
    'writes' => ['article_versions (phiên bản mới)', 'agent_run_steps', 'audit_suggestions.status=da_xong'],
    'shown_at' => ['Trang bài: tab Nội dung (phiên bản mới, so sánh trước/sau)', 'Trang Audit: đề xuất chuyển "đã xong"'],
    'used_by' => ['human'],
    'steps' => [
        ['id' => 'guard', 'role' => 'system', 'label' => 'An toàn', 'kind' => 'rule'],
        ['id' => 'trigger', 'role' => 'monitor', 'label' => 'Phát hiện bài suy giảm hoặc cơ hội', 'kind' => 'rule', 'planned' => true, 'when' => 'THIẾT KẾ: từ Search Console (chưa kết nối); hiện đầu vào duy nhất là đề xuất từ audit'],
        ['id' => 'grade_old', 'role' => 'editor', 'label' => 'Chấm bản hiện tại', 'kind' => 'rule'],
        ['id' => 'scope', 'role' => 'strategist', 'label' => 'Phân loại phạm vi sửa', 'kind' => 'rule', 'when' => '(a) tiêu đề và meta, (b) một số mục, (c) viết lại'],
        ['id' => 'meta', 'role' => 'writer', 'label' => 'Sinh lại siêu dữ liệu', 'kind' => 'llm', 'task' => 'meta_regen', 'when' => 'Nhánh 4a; bậc rẻ'],
        ['id' => 'section', 'role' => 'writer', 'label' => 'Sửa các mục có lỗi', 'kind' => 'llm', 'task' => 'section_repair', 'when' => 'Nhánh 4b; bậc chuẩn; chỉ gửi mục cần sửa'],
        ['id' => 'rewrite', 'role' => 'writer', 'label' => 'Viết lại', 'kind' => 'llm', 'task' => 'write', 'when' => 'Nhánh 4c: chạy như luồng Viết bài (cũng là đường lùi khi AI không hỗ trợ sửa một phần)'],
        ['id' => 'grade', 'role' => 'editor', 'label' => 'Chấm lại', 'kind' => 'rule'],
        ['id' => 'handoff', 'human' => true, 'role' => 'system', 'label' => 'Bàn giao phiên bản mới', 'kind' => 'rule'],
    ],
    'budget' => 'Token tương đối 0,2–1,0',
    'human' => ['Người duyệt xem so sánh trước/sau; điểm sau sửa không thấp hơn trước'],
    'features' => [],
    'runner' => ArticleRefreshRunner::class,
    'records' => [
        ['label' => 'Audit website', 'class' => 'App\\Filament\\Pages\\AuditPage', 'type' => 'input'],
        ['label' => 'Bài viết', 'class' => 'App\\Filament\\Resources\\ArticleResource', 'type' => 'output'],
        ['label' => 'Phiên chạy', 'class' => 'App\\Filament\\Resources\\AgentRunResource', 'type' => 'report', 'filter_job_type' => true],
    ],
];
