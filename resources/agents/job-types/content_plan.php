<?php

use App\Services\Planning\ContentPlanner;

/** Lập kế hoạch: mắt xích 2. Luật, không AI: ContentPlanner. */
return [
    'id' => 'content_plan',
    'title' => 'Lập kế hoạch',
    'link' => 'plan',
    'order' => 30,
    'status' => 'active',
    'summary' => 'Chạy được từ trang Kế hoạch nội dung (hộp Đề xuất kế hoạch theo luật): ContentPlanner::propose chỉ tính, create tạo bài planned có keyword_id, cluster_id, origin=plan, priority. Không có bước AI, 0 token. Chưa chạy theo lịch. Chưa ghi phiên agent_runs vì là luật không có lời gọi AI. Bài mới chờ bạn duyệt, không tự giao đội AI. Tiêu đề và góc viết thuộc bước dàn ý của article_write.',
    'reads' => ['keywords', 'keyword_clusters', 'articles đã có', 'brand_profiles'],
    'writes' => ['articles (status=planned, keyword_id, cluster_id, origin=plan, priority)', 'keywords.status=planned'],
    'shown_at' => ['Trang Kế hoạch nội dung: bài chờ duyệt kế hoạch', 'Trang bài: tab Từ khóa và kế hoạch'],
    'used_by' => ['article_write'],
    'steps' => [
        ['id' => 'guard', 'label' => 'An toàn', 'kind' => 'rule'],
        ['id' => 'dedupe', 'label' => 'Bỏ từ khóa đã có bài', 'kind' => 'rule'],
        ['id' => 'offtopic', 'label' => 'Kiểm lệch chủ đề', 'kind' => 'rule', 'when' => 'Dùng BrandProfileData::looksOffTopic; chỉ cảnh báo khi đề xuất, bỏ khi tạo bài'],
        ['id' => 'rank', 'label' => 'Xếp ưu tiên', 'kind' => 'rule', 'when' => 'Theo lượt tìm, khoảng trống, độ phủ cụm'],
        ['id' => 'create', 'label' => 'Tạo bài planned', 'kind' => 'rule', 'when' => 'Qua PlannedArticleCreator, origin=plan, priority theo thứ hạng'],
    ],
    'budget' => 'Token tương đối 0–0,1',
    'human' => ['Bạn duyệt kế hoạch trước khi bài vào hàng chờ viết (sửa kế hoạch 0 token, sửa bài đã viết thì tốn)'],
    'features' => [],
    'runner' => ContentPlanner::class,
];
