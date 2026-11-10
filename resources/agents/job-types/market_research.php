<?php

/** Khảo sát thị trường: mắt xích 1 (Từ khóa). Chưa có mã chạy loại việc này. */
return [
    'id' => 'market_research',
    'title' => 'Khảo sát thị trường',
    'link' => 'keyword',
    'order' => 10,
    'status' => 'planned',
    'summary' => 'Đề xuất từ khóa mới quanh hạt giống, gom cụm, gán ý định, xếp ưu tiên khoảng trống đối thủ. Chưa có mã chạy loại việc này: trang Từ khóa và Đối thủ hiện chỉ thao tác tay, chưa có bước AI đề xuất từ khóa. Không có nguồn số liệu lượt tìm; AI không biết lượt tìm, nên cột lượt tìm để trống, không điền số đoán. Câu "từ khóa được tìm nhiều nhất" chỉ trả lời được khi có CSV, Search Console hoặc nguồn dữ liệu từ khóa (Q4). Chưa kiểm được trong mã: tên cột `source`, `intent` của `keywords`.',
    'reads' => ['keywords (hạt giống)', 'keyword_gaps (CSV đối thủ)', 'brand_profiles', 'Search Console (sau này)'],
    'writes' => ['keywords mới (source=ai, intent, cluster_id; volume để trống nếu không có nguồn số liệu)', 'keyword_clusters', 'keyword_gaps.priority_score'],
    'shown_at' => ['Trang Từ khóa: từ mới có nhãn "AI đề xuất", bảng cụm', 'Trang Đối thủ: khoảng trống xếp ưu tiên', 'Trang bài: tab Từ khóa và kế hoạch'],
    'used_by' => ['content_plan'],
    'steps' => [
        ['id' => 'guard', 'label' => 'An toàn', 'kind' => 'rule', 'planned' => true],
        ['id' => 'collect', 'label' => 'Gom từ khóa từ kho và đối thủ', 'kind' => 'rule', 'planned' => true, 'when' => 'Loại trùng'],
        ['id' => 'suggest', 'label' => 'Đề xuất từ khóa mới', 'kind' => 'llm', 'task' => 'keyword_suggest', 'planned' => true, 'when' => 'Bỏ qua nếu người dùng chỉ muốn gom cụm; bậc rẻ'],
        ['id' => 'cluster', 'label' => 'Gom cụm, gán ý định', 'kind' => 'llm', 'task' => 'keyword_cluster', 'planned' => true, 'when' => 'Gom lô; chỉ từ chưa có cụm; bậc rẻ'],
        ['id' => 'score', 'label' => 'Tính điểm ưu tiên khoảng trống', 'kind' => 'rule', 'planned' => true],
        ['id' => 'save', 'label' => 'Ghi đề xuất chờ chấp nhận', 'kind' => 'rule', 'planned' => true],
    ],
    'budget' => 'Token tương đối 0,1–0,3 so với một bài viết không sửa (ước lượng)',
    'human' => ['Bạn chấp nhận, gộp, tách cụm (thao tác đã có ở trang Từ khóa)'],
    'features' => [],
    'runner' => null,
];
