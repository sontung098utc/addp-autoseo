<?php

use App\Filament\Support\Planning\PlannedArticleCreator;

/** Lập kế hoạch: mắt xích 2. Hôm nay chỉ có tạo bài planned thủ công qua PlannedArticleCreator. */
return [
    'id' => 'content_plan',
    'title' => 'Lập kế hoạch',
    'link' => 'plan',
    'order' => 30,
    'status' => 'planned',
    'summary' => 'Hôm nay chỉ có phần tạo bài trạng thái planned bằng tay qua PlannedArticleCreator (trang Kế hoạch nội dung), và CampaignRunner::nextPlanned lấy bài planned đi viết. Chưa có: loại việc tự bỏ từ khóa đã có bài, xếp ưu tiên theo lượt tìm, khoảng trống, độ phủ cụm, và cổng duyệt kế hoạch như một phiên. BrandProfileData::looksOffTopic đã có nhưng chưa nối vào loại việc này. Bản đầu không có bước AI: tiêu đề và góc viết thuộc bước dàn ý của article_write.',
    'reads' => ['keywords', 'keyword_clusters', 'articles đã có', 'brand_profiles'],
    'writes' => ['articles (status=planned, keyword_id, cluster_id, origin=plan, priority)', 'keywords.status=planned'],
    'shown_at' => ['Trang Kế hoạch nội dung: bài chờ duyệt kế hoạch', 'Trang bài: tab Từ khóa và kế hoạch'],
    'used_by' => ['article_write'],
    'steps' => [
        ['id' => 'guard', 'label' => 'An toàn', 'kind' => 'rule', 'planned' => true],
        ['id' => 'dedupe', 'label' => 'Bỏ từ khóa đã có bài', 'kind' => 'rule', 'planned' => true],
        ['id' => 'offtopic', 'label' => 'Kiểm lệch chủ đề', 'kind' => 'rule', 'planned' => true, 'when' => 'Dùng BrandProfileData::looksOffTopic (đã có)'],
        ['id' => 'rank', 'label' => 'Xếp ưu tiên', 'kind' => 'rule', 'planned' => true, 'when' => 'Theo lượt tìm, khoảng trống, độ phủ cụm'],
        ['id' => 'create', 'label' => 'Tạo bài planned', 'kind' => 'rule', 'when' => 'Hôm nay làm tay qua PlannedArticleCreator'],
    ],
    'budget' => 'Token tương đối 0–0,1',
    'human' => ['Bạn duyệt kế hoạch trước khi bài vào hàng chờ viết (sửa kế hoạch 0 token, sửa bài đã viết thì tốn)'],
    'features' => [],
    'runner' => PlannedArticleCreator::class,
];
