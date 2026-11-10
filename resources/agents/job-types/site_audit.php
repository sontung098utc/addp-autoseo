<?php

use App\Console\Commands\AuditWebsiteCommand;

/** Audit website: mắt xích 2 (tạo việc làm mới). Chạy hôm nay bằng lệnh autoseo:audit-website. */
return [
    'id' => 'site_audit',
    'title' => 'Audit website',
    'link' => 'plan',
    'order' => 20,
    'status' => 'active',
    'summary' => 'Quét website, nhóm lỗi, chấm điểm, sinh đề xuất làm mới và khớp URL với bài. Không có bước AI. Chạy bằng lệnh autoseo:audit-website, và từ trang Audit bằng nút "Quét thật" (G3: giới hạn số trang, cùng luật chống SSRF; chưa quét website thật, mới kiểm bằng mạng giả); nút "Quét lại" cũ vẫn chạy trên bộ trang mẫu. Đề xuất làm mới do AuditSuggestionService. Chưa kiểm được trong mã: bước người chấp nhận đề xuất tạo bài kind=refresh, origin=audit đã đủ chưa.',
    'reads' => ['crawled_urls', 'seo_audits'],
    'writes' => ['audit_suggestions', 'articles (kind=refresh, origin=audit) khi người chấp nhận'],
    'shown_at' => ['Trang Audit: nhóm lỗi, đề xuất làm mới', 'Trang Kế hoạch', 'Trang bài: tab Từ khóa và kế hoạch ("bài sinh ra từ Kiểm tra SEO")'],
    'used_by' => ['article_refresh'],
    'steps' => [
        ['id' => 'guard', 'role' => 'system', 'label' => 'An toàn', 'kind' => 'rule'],
        ['id' => 'crawl', 'role' => 'auditor', 'label' => 'Quét', 'kind' => 'rule'],
        ['id' => 'score', 'role' => 'auditor', 'label' => 'Nhóm lỗi, chấm điểm', 'kind' => 'rule'],
        ['id' => 'suggest', 'human' => true, 'role' => 'auditor', 'label' => 'Sinh đề xuất làm mới', 'kind' => 'rule', 'when' => 'Khớp URL với bài'],
    ],
    'budget' => 'Token tương đối 0',
    'human' => ['Bạn chọn đề xuất nào thành bài làm mới'],
    'features' => [],
    'runner' => AuditWebsiteCommand::class,
    'records' => [
        ['label' => 'Audit website', 'class' => 'App\\Filament\\Pages\\AuditPage', 'type' => 'output'],
        ['label' => 'Kế hoạch nội dung', 'class' => 'App\\Filament\\Pages\\ContentPlanPage', 'type' => 'output'],
    ],
];
