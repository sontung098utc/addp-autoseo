<?php

use App\Console\Commands\AuditWebsiteCommand;

/** Audit website: mắt xích 2 (tạo việc làm mới). Chạy hôm nay bằng lệnh autoseo:audit-website. */
return [
    'id' => 'site_audit',
    'title' => 'Audit website',
    'link' => 'plan',
    'order' => 20,
    'status' => 'active',
    'summary' => 'Quét website, nhóm lỗi, chấm điểm, sinh đề xuất làm mới và khớp URL với bài. Không có bước AI. Chạy hôm nay bằng lệnh autoseo:audit-website và AuditSuggestionService. Chưa kiểm được trong mã: bước người chấp nhận đề xuất tạo bài kind=refresh, origin=audit đã đủ chưa.',
    'reads' => ['crawled_urls', 'seo_audits'],
    'writes' => ['audit_suggestions', 'articles (kind=refresh, origin=audit) khi người chấp nhận'],
    'shown_at' => ['Trang Audit: nhóm lỗi, đề xuất làm mới', 'Trang Kế hoạch', 'Trang bài: tab Từ khóa và kế hoạch ("bài sinh ra từ Kiểm tra SEO")'],
    'used_by' => ['article_refresh'],
    'steps' => [
        ['id' => 'guard', 'label' => 'An toàn', 'kind' => 'rule'],
        ['id' => 'crawl', 'label' => 'Quét', 'kind' => 'rule'],
        ['id' => 'score', 'label' => 'Nhóm lỗi, chấm điểm', 'kind' => 'rule'],
        ['id' => 'suggest', 'label' => 'Sinh đề xuất làm mới', 'kind' => 'rule', 'when' => 'Khớp URL với bài'],
    ],
    'budget' => 'Token tương đối 0',
    'human' => ['Bạn chọn đề xuất nào thành bài làm mới'],
    'features' => [],
    'runner' => AuditWebsiteCommand::class,
];
