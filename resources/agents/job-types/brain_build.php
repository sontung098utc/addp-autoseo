<?php

use App\Services\Knowledge\Build\BrainBuildService;

/**
 * Xây kho trí tuệ: cấp kiến thức cho mắt xích 3.
 * Đã có (G2): lượt xây từ website công ty, chạy tay trong trang "Xây kho từ website" hoặc lệnh `brain:build`.
 * Có thêm: cuối lượt tự điền nháp Hồ sơ doanh nghiệp (chỉ trường trống, có đoạn nguyên văn từ trang, hoàn tác được) và mục "Gợi ý cho kho" từ bài gần đây (luật 0 token, chỉ gợi ý).
 * Có thêm: khám phá nguồn trên web (Tavily, cần TAVILY_API_KEY): tìm, gắn nhãn tin cậy bằng luật cố định, nạp nguồn ngoài an toàn; nguồn tin cậy tự nhận, nguồn chưa rõ chỉ đề xuất.
 * Chưa có: chạy nền/lịch, đọc PDF, kiểm tra bản quyền, gọi Tavily thật, dựng từ khoảng trống kiến thức, chạy với AI thật và website thật.
 */
return [
    'id' => 'brain_build',
    'title' => 'Xây kho trí tuệ',
    'link' => 'research',
    'order' => 60,
    'status' => 'active',
    'summary' => 'Có: lượt xây kho từ chính website công ty (chọn trang từ sitemap hoặc URL dán, tải an toàn, trích chữ, AI rút mục đề xuất có đoạn nguyên văn, luật cố định xếp tầng A/B/C: tầng A tự nhận có nhãn và hoàn tác được; B, C chờ người duyệt), ghi một phiên mỗi lượt, mỗi trang một bước. Cuối lượt AI thử tự điền nháp Hồ sơ doanh nghiệp từ 3 trang đầu (chỉ khi hồ sơ trống từ 3 trường; chỉ điền trường trống, mỗi trường có đoạn nguyên văn từ trang; không tự thêm từ cấm hay điều được phép khẳng định ngoài câu chép nguyên văn; hoàn tác được trên trang). Trang còn có mục Gợi ý cho kho từ bài 30 ngày gần đây (khoảng trống kho, số liệu chưa có căn cứ, nguồn AI nêu mà kho chưa có): tính bằng luật, không AI, chỉ gợi ý. Khám phá nguồn trên web: có tìm web qua Tavily khi có TAVILY_API_KEY (đã chọn Tavily, chưa chạy API thật); kết quả được hệ thống tự gắn nhãn tin cậy/chưa rõ/bị loại bằng luật cố định kèm lý do; nguồn được tải ở chế độ ngoài (chặn IP nội bộ, tối đa 3 lần chuyển hướng, 2 MB, robots.txt, giãn cách 1 giây); mục từ nguồn tin cậy có đoạn nguyên văn tự nhận (kể cả chủ đề sức khỏe, trừ giá/chính sách/lầm tưởng/cấm), nguồn chưa rõ chỉ đề xuất; đội AI tự tìm tối đa 3 nguồn tin cậy khi kho vẫn trống sau khi đọc website. Chạy tay và đồng bộ. Chưa có: chạy nền hoặc theo lịch, đọc PDF, kiểm tra bản quyền nguồn ngoài, dựng từ khoảng trống kiến thức theo nghĩa (nút Bổ sung từ website chỉ chọn trang có đường dẫn chứa từ khóa). Chưa chạy với AI thật, chưa gọi Tavily thật và chưa tải website/tên miền ngoài thật (mới kiểm bằng AI giả và Http giả).',
    'reads' => ['website (sitemap, trang công khai)', 'URL người dán', 'tài liệu dán', 'kết quả tìm web (Tavily)', 'trang ngoài người dùng chọn'],
    'writes' => ['brand_profiles (chỉ trường trống, hoàn tác được)', 'knowledge_sources (origin=own_site hoặc search)', 'knowledge_items (risk_tier, tầng A tự nhận review_mode=auto, còn lại proposed)'],
    'shown_at' => ['Trang Xây kho từ website', 'Trang Kho trí tuệ: mục chờ duyệt'],
    'used_by' => ['article_write', 'article_refresh'],
    'steps' => [
        ['id' => 'guard', 'role' => 'system', 'label' => 'Kiểm cổng: quyền, website hoạt động, dừng khẩn cấp, có AI', 'kind' => 'rule'],
        ['id' => 'select', 'role' => 'system', 'label' => 'Chọn trang từ sitemap hoặc URL dán', 'kind' => 'rule'],
        ['id' => 'search', 'role' => 'system', 'label' => 'Tìm nguồn trên web (Tavily) và tự gắn nhãn tin cậy bằng luật cố định', 'kind' => 'rule', 'when' => 'có TAVILY_API_KEY'],
        ['id' => 'source', 'role' => 'system', 'label' => 'Mỗi nguồn ngoài: tải an toàn, trích chữ, AI rút mục; nguồn tin cậy tự nhận, nguồn chưa rõ chỉ đề xuất', 'kind' => 'llm', 'task' => 'knowledge', 'when' => 'KnowledgeAiIngest'],
        ['id' => 'page', 'role' => 'system', 'label' => 'Mỗi trang: tải an toàn, trích chữ, AI rút mục, xếp tầng', 'kind' => 'llm', 'task' => 'knowledge', 'when' => 'KnowledgeAiIngest'],
        ['id' => 'brand', 'role' => 'system', 'label' => 'Cuối lượt: AI tự điền nháp Hồ sơ doanh nghiệp (trường trống, có căn cứ trang)', 'kind' => 'llm', 'task' => 'knowledge', 'when' => 'hồ sơ trống từ 3 trường'],
        ['id' => 'suggest', 'human' => true, 'role' => 'manager', 'label' => 'Gợi ý cho kho từ bài gần đây (khoảng trống, số liệu, nguồn)', 'kind' => 'rule'],
        ['id' => 'gaps', 'role' => 'editor', 'label' => 'Dựng từ khoảng trống kiến thức', 'kind' => 'rule', 'planned' => true],
    ],
    'budget' => 'Tối đa 5.000 ₫ mỗi trang; mỗi lượt tối đa min(150.000 ₫, ngân sách chính sách)',
    'human' => ['Người duyệt mục chờ duyệt (tầng B, C: giá, chính sách, sản phẩm sức khỏe, nội dung y khoa)', 'Người duyệt hoàn tác mục tự nhận nếu sai'],
    'features' => [],
    'runner' => BrainBuildService::class,
    'records' => [
        ['label' => 'Xây kho từ website', 'class' => 'App\\Filament\\Pages\\BrainBuildPage', 'type' => 'output'],
        ['label' => 'Kho trí tuệ', 'class' => 'App\\Filament\\Pages\\BrainLibraryPage', 'type' => 'output'],
    ],
];
