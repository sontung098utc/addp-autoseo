<?php

use App\Services\Knowledge\Build\BrainBuildService;

/**
 * Xây kho trí tuệ: cấp kiến thức cho mắt xích 3.
 * Đã có (G2): lượt xây từ website công ty, chạy tay trong trang "Xây kho từ website" hoặc lệnh `brain:build`.
 * Có thêm: cuối lượt tự điền nháp Hồ sơ doanh nghiệp (chỉ trường trống, có đoạn nguyên văn từ trang, hoàn tác được) và mục "Gợi ý cho kho" từ bài gần đây (luật 0 token, chỉ gợi ý).
 * Chưa có: chạy nền/lịch, tìm web, tải tên miền ngoài, đọc PDF, dựng từ khoảng trống kiến thức, chạy với AI thật và website thật.
 */
return [
    'id' => 'brain_build',
    'title' => 'Xây kho trí tuệ',
    'link' => 'research',
    'order' => 60,
    'status' => 'active',
    'summary' => 'Có: lượt xây kho từ chính website công ty (chọn trang từ sitemap hoặc URL dán, tải an toàn, trích chữ, AI rút mục đề xuất có đoạn nguyên văn, luật cố định xếp tầng A/B/C: tầng A tự nhận có nhãn và hoàn tác được; B, C chờ người duyệt), ghi một phiên mỗi lượt, mỗi trang một bước. Cuối lượt AI thử tự điền nháp Hồ sơ doanh nghiệp từ 3 trang đầu (chỉ khi hồ sơ trống từ 3 trường; chỉ điền trường trống, mỗi trường có đoạn nguyên văn từ trang; không tự thêm từ cấm hay điều được phép khẳng định ngoài câu chép nguyên văn; hoàn tác được trên trang). Trang còn có mục Gợi ý cho kho từ bài 30 ngày gần đây (khoảng trống kho, số liệu chưa có căn cứ, nguồn AI nêu mà kho chưa có): tính bằng luật, không AI, chỉ gợi ý. Chạy tay và đồng bộ. Chưa có: chạy nền hoặc theo lịch, tìm web, tải tên miền ngoài, đọc PDF, dựng từ khoảng trống kiến thức theo nghĩa (nút Bổ sung từ website chỉ chọn trang có đường dẫn chứa từ khóa), tìm nguồn tốt trên web để gợi ý. Chưa chạy với AI thật và chưa tải website thật (mới kiểm bằng AI giả và Http giả).',
    'reads' => ['website (sitemap, trang công khai)', 'URL người dán', 'tài liệu dán'],
    'writes' => ['brand_profiles (chỉ trường trống, hoàn tác được)', 'knowledge_sources (origin=own_site)', 'knowledge_items (risk_tier, tầng A tự nhận review_mode=auto, còn lại proposed)'],
    'shown_at' => ['Trang Xây kho từ website', 'Trang Kho trí tuệ: mục chờ duyệt'],
    'used_by' => ['article_write', 'article_refresh'],
    'steps' => [
        ['id' => 'guard', 'label' => 'Kiểm cổng: quyền, website hoạt động, dừng khẩn cấp, có AI', 'kind' => 'rule'],
        ['id' => 'select', 'label' => 'Chọn trang từ sitemap hoặc URL dán', 'kind' => 'rule'],
        ['id' => 'page', 'label' => 'Mỗi trang: tải an toàn, trích chữ, AI rút mục, xếp tầng', 'kind' => 'llm', 'task' => 'knowledge', 'when' => 'KnowledgeAiIngest'],
        ['id' => 'brand', 'label' => 'Cuối lượt: AI tự điền nháp Hồ sơ doanh nghiệp (trường trống, có căn cứ trang)', 'kind' => 'llm', 'task' => 'knowledge', 'when' => 'hồ sơ trống từ 3 trường'],
        ['id' => 'suggest', 'label' => 'Gợi ý cho kho từ bài gần đây (khoảng trống, số liệu, nguồn)', 'kind' => 'rule'],
        ['id' => 'gaps', 'label' => 'Dựng từ khoảng trống kiến thức', 'kind' => 'rule', 'planned' => true],
    ],
    'budget' => 'Tối đa 5.000 ₫ mỗi trang; mỗi lượt tối đa min(150.000 ₫, ngân sách chính sách)',
    'human' => ['Người duyệt mục chờ duyệt (tầng B, C: giá, chính sách, sản phẩm sức khỏe, nội dung y khoa)', 'Người duyệt hoàn tác mục tự nhận nếu sai'],
    'features' => [],
    'runner' => BrainBuildService::class,
];
