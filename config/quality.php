<?php

return [
    /**
     * Luật chấm nào được CHẶN bài (mức P0) trong luồng thật. Luật P0 khác chỉ còn là cảnh báo (hạ xuống P1, ghi "chỉ cảnh báo").
     * Mặc định chỉ chặn điều nguy hiểm hoặc sai sự thật: nội dung nguy hiểm, từ cấm, trích nguồn bị chặn, trích dẫn giả, đổi số liệu đã duyệt.
     * Độ dài, cấu trúc heading, từ khóa, trùng lặp là tiêu chí SEO: cảnh báo, không chặn. Đặt QUALITY_BLOCKING_CODES=ALL để chặn mọi luật P0 như trước.
     */
    'blocking_codes' => env('QUALITY_BLOCKING_CODES', 'SAFE01,BAN01,EVID01,CITE01,FACT01'),

    /**
     * Lượt AI kiểm NGHĨA trích dẫn (CITE02) cho Trí tuệ bắt buộc nguồn: mỗi lần chấm thêm một lời gọi AI (khoảng 1 yêu cầu). Đặt false để chỉ dùng kiểm số liệu tất định.
     */
    'citation_judge' => (bool) env('QUALITY_CITATION_JUDGE', true),
];
