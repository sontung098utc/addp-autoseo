<?php

use App\Services\Knowledge\Build\BrainBuildService;

/**
 * Xây kho trí tuệ: cấp kiến thức cho mắt xích 3.
 * Đã có (G2): lượt xây từ website công ty, chạy tay trong trang "Xây kho từ website" hoặc lệnh `brain:build`.
 * Chưa có: chạy nền/lịch, tìm web, tải tên miền ngoài, đọc PDF, dựng từ khoảng trống kiến thức, chạy với AI thật và website thật.
 */
return [
    'id' => 'brain_build',
    'title' => 'Xây kho trí tuệ',
    'link' => 'research',
    'order' => 60,
    'status' => 'active',
    'summary' => 'Có: lượt xây kho từ chính website công ty (chọn trang từ sitemap hoặc URL dán, tải an toàn, trích chữ, AI rút mục đề xuất có đoạn nguyên văn, luật cố định xếp tầng A/B/C: tầng A tự nhận có nhãn và hoàn tác được; B, C chờ người duyệt), ghi một phiên mỗi lượt, mỗi trang một bước. Chạy tay và đồng bộ. Chưa có: chạy nền hoặc theo lịch, tìm web, tải tên miền ngoài, đọc PDF, dựng từ khoảng trống kiến thức. Chưa chạy với AI thật và chưa tải website thật (mới kiểm bằng AI giả và Http giả).',
    'reads' => ['website (sitemap, trang công khai)', 'URL người dán', 'tài liệu dán'],
    'writes' => ['knowledge_sources (origin=own_site)', 'knowledge_items (risk_tier, tầng A tự nhận review_mode=auto, còn lại proposed)'],
    'shown_at' => ['Trang Xây kho từ website', 'Trang Kho trí tuệ: mục chờ duyệt'],
    'used_by' => ['article_write', 'article_refresh'],
    'steps' => [
        ['id' => 'guard', 'label' => 'Kiểm cổng: quyền, website hoạt động, dừng khẩn cấp, có AI', 'kind' => 'rule'],
        ['id' => 'select', 'label' => 'Chọn trang từ sitemap hoặc URL dán', 'kind' => 'rule'],
        ['id' => 'page', 'label' => 'Mỗi trang: tải an toàn, trích chữ, AI rút mục, xếp tầng', 'kind' => 'llm', 'task' => 'knowledge', 'when' => 'KnowledgeAiIngest'],
        ['id' => 'gaps', 'label' => 'Dựng từ khoảng trống kiến thức', 'kind' => 'rule', 'planned' => true],
    ],
    'budget' => 'Tối đa 5.000 ₫ mỗi trang; mỗi lượt tối đa min(150.000 ₫, ngân sách chính sách)',
    'human' => ['Người duyệt mục chờ duyệt (tầng B, C: giá, chính sách, sản phẩm sức khỏe, nội dung y khoa)', 'Người duyệt hoàn tác mục tự nhận nếu sai'],
    'features' => [],
    'runner' => BrainBuildService::class,
];
