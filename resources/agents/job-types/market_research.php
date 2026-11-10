<?php

/** Khảo sát thị trường: mắt xích 1 (Từ khóa). Đã có hai bước AI chạy từ trang Từ khóa (G3). */
return [
    'id' => 'market_research',
    'title' => 'Khảo sát thị trường',
    'link' => 'keyword',
    'order' => 10,
    'status' => 'active',
    'summary' => 'Đề xuất từ khóa mới quanh hạt giống và hồ sơ doanh nghiệp (nút "Khảo sát thị trường (AI)" trên trang Từ khóa, ghi source=ai) và gom cụm bằng AI (nút Gom cụm). Chạy đồng bộ khi bấm nút, có trần chi phí và gom lô. Mỗi lần chạy ghi một phiên agent_runs (job_type=market_research, không gắn bài) kèm từng bước AI: tokens, chi phí, model và kết quả (id từ khóa đã thêm hoặc số đề xuất cụm). Chưa làm: chạy theo lịch, xếp ưu tiên khoảng trống đối thủ, lưu bền đề xuất cụm (đang trong cache 1 ngày, lưu bền cần migration riêng). Chưa chạy AI thật, mới kiểm bằng AI giả. AI không biết lượt tìm: volume và difficulty để trống, không điền số đoán; câu "từ khóa được tìm nhiều nhất" chỉ trả lời được khi có CSV, Search Console hoặc nguồn dữ liệu từ khóa (Q4).',
    'reads' => ['keywords (hạt giống)', 'keyword_gaps (CSV đối thủ)', 'brand_profiles', 'Search Console (sau này)'],
    'writes' => ['keywords mới (source=ai, intent, cluster_id; volume để trống nếu không có nguồn số liệu)', 'keyword_clusters', 'keyword_gaps.priority_score', 'agent_runs (job_type=market_research)', 'agent_run_steps'],
    'shown_at' => ['Trang Từ khóa: từ mới có nhãn "AI đề xuất", bảng cụm', 'Trang Đối thủ: khoảng trống xếp ưu tiên', 'Trang bài: tab Từ khóa và kế hoạch'],
    'used_by' => ['content_plan'],
    'steps' => [
        ['id' => 'guard', 'label' => 'An toàn', 'kind' => 'rule', 'planned' => true],
        ['id' => 'collect', 'label' => 'Gom từ khóa từ kho và đối thủ', 'kind' => 'rule', 'planned' => true, 'when' => 'Loại trùng'],
        ['id' => 'suggest', 'label' => 'Đề xuất từ khóa mới', 'kind' => 'llm', 'task' => 'keyword_suggest', 'when' => 'Bỏ qua nếu người dùng chỉ muốn gom cụm; bậc rẻ'],
        ['id' => 'cluster', 'label' => 'Gom cụm, gán ý định', 'kind' => 'llm', 'task' => 'keyword_cluster', 'when' => 'Gom lô; chỉ từ chưa có cụm; bậc rẻ'],
        ['id' => 'score', 'label' => 'Tính điểm ưu tiên khoảng trống', 'kind' => 'rule', 'planned' => true],
        ['id' => 'save', 'label' => 'Ghi đề xuất chờ chấp nhận', 'kind' => 'rule', 'planned' => true],
    ],
    'budget' => 'Token tương đối 0,1–0,3 so với một bài viết không sửa (ước lượng)',
    'human' => ['Bạn chấp nhận, gộp, tách cụm (thao tác đã có ở trang Từ khóa)'],
    'features' => [],
    'runner' => null,
];
