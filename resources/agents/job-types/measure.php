<?php

/** Đo lường: sau Xuất bản, nối ngược về kế hoạch. Chưa kết nối. */
return [
    'id' => 'measure',
    'title' => 'Đo lường',
    'link' => 'measure',
    'order' => 70,
    'status' => 'planned',
    'summary' => 'Chưa kết nối: không có mã Search Console chạy được và chưa có bảng lưu số liệu theo bài (cần migration, Task 5.1). Khi có: kéo số liệu, ghép với bài đã đăng, phát hiện bài tụt theo ngưỡng, sinh đề xuất làm mới (origin=gsc).',
    'reads' => ['Search Console', 'publications.published_url'],
    'writes' => ['Số liệu theo bài (chưa có bảng)', 'đề xuất làm mới (origin=gsc)'],
    'shown_at' => ['Trang Search Console', 'Trang bài: mắt xích 7 (lượt hiển thị, nhấp, vị trí)'],
    'used_by' => ['article_refresh', 'market_research'],
    'steps' => [
        ['id' => 'connect', 'label' => 'Kiểm kết nối', 'kind' => 'rule', 'planned' => true, 'when' => 'Chưa nối thì dừng'],
        ['id' => 'pull', 'label' => 'Kéo số liệu', 'kind' => 'rule', 'planned' => true],
        ['id' => 'match', 'label' => 'Ghép với bài đã đăng', 'kind' => 'rule', 'planned' => true],
        ['id' => 'detect', 'label' => 'Phát hiện bài tụt', 'kind' => 'rule', 'planned' => true, 'when' => 'Theo ngưỡng'],
        ['id' => 'suggest', 'label' => 'Sinh đề xuất làm mới', 'kind' => 'rule', 'planned' => true],
    ],
    'budget' => 'Token tương đối 0',
    'human' => [],
    'features' => [],
    'runner' => null,
];
