<?php

use App\Services\Knowledge\KnowledgeAiIngest;

/** Xây kho trí tuệ: cấp kiến thức cho mắt xích 3. Hôm nay chỉ nạp tài liệu dán bằng KnowledgeAiIngest. */
return [
    'id' => 'brain_build',
    'title' => 'Xây kho trí tuệ',
    'link' => 'research',
    'order' => 60,
    'status' => 'planned',
    'summary' => 'Hôm nay chỉ có KnowledgeAiIngest tách mục kiến thức từ tài liệu người dùng dán vào (task knowledge). Chưa có: quét website để dựng kho, và dựng kiến thức từ knowledge_gaps do article_write ghi. Chi tiết ghép kho: tài liệu riêng của Kho trí tuệ (thiết kế §3.4); phần này chưa đọc kỹ trong mã.',
    'reads' => ['tài liệu dán', 'website', 'knowledge_gaps'],
    'writes' => ['knowledge_items (status=proposed)'],
    'shown_at' => ['Trang Kho trí tuệ: mục chờ duyệt'],
    'used_by' => ['article_write', 'article_refresh'],
    'steps' => [
        ['id' => 'ingest', 'label' => 'Tách mục từ tài liệu dán', 'kind' => 'llm', 'task' => 'knowledge', 'when' => 'KnowledgeAiIngest'],
        ['id' => 'site', 'label' => 'Dựng từ website', 'kind' => 'rule', 'planned' => true],
        ['id' => 'gaps', 'label' => 'Dựng từ khoảng trống kiến thức', 'kind' => 'rule', 'planned' => true],
    ],
    'budget' => 'Theo tài liệu riêng',
    'human' => ['Người duyệt mục kiến thức; chỉ mục đã duyệt mới được article_write và article_refresh đọc'],
    'features' => [],
    'runner' => KnowledgeAiIngest::class,
];
