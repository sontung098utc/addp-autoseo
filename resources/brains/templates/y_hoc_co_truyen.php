<?php

use App\Services\Brain\BrainTemplates;

/** AG-B2: mẫu CẤU TRÚC cho y học cổ truyền và thảo dược. Không có tên vị thuốc, công dụng hay bài thuốc. */
return BrainTemplates::health([
    'name' => 'Y học cổ truyền và thảo dược',
    'description' => 'Giới thiệu y học cổ truyền, thảo dược, phương pháp không dùng thuốc, dùng an toàn. Không khẳng định chữa khỏi. Bắt buộc nguồn, hạng rủi ro cao.',
    'domain_key' => 'y_hoc_co_truyen',
    'reliance_reason' => 'Nhiều quan niệm chưa có bằng chứng hiện đại và dễ bị thổi phồng; tương tác với thuốc điều trị cần chuyên gia đánh giá.',
    'topics' => [
        'Khái niệm nền tảng' => ['Cách tiếp cận của y học cổ truyền', 'Y học cổ truyền và y học hiện đại'],
        'Thảo dược' => ['Hiểu về dược liệu', 'Dùng thảo dược an toàn', 'Tương tác với thuốc'],
        'Phương pháp không dùng thuốc' => ['Châm cứu', 'Xoa bóp bấm huyệt', 'Dưỡng sinh'],
        'Quy định' => ['Cơ sở khám chữa bệnh được cấp phép', 'Quảng cáo dược liệu'],
    ],
    'types' => [
        'gioi_thieu_phuong_phap_cot_truyen' => ['Giới thiệu một phương pháp y học cổ truyền', ['Phương pháp là gì', 'Nguồn gốc và cách tiếp cận', 'Điều đã có nghiên cứu và điều còn hạn chế', 'Ai thực hiện và ở đâu', 'Trao đổi với bác sĩ trước khi dùng']],
        'dung_duoc_lieu_an_toan' => ['Dùng thảo dược và dược liệu an toàn', ['Thảo dược không đồng nghĩa với vô hại', 'Nguy cơ tương tác với thuốc đang dùng', 'Chất lượng và nguồn gốc dược liệu', 'Hỏi thầy thuốc hoặc dược sĩ khi nào']],
        'co_truyen_va_hien_dai' => ['Kết hợp y học cổ truyền với điều trị hiện đại', ['Hai cách tiếp cận khác nhau', 'Vì sao cần thông tin cho bác sĩ điều trị', 'Dấu hiệu không nên chậm đi khám', 'Câu hỏi thường gặp']],
        'quan_niem_dan_gian' => ['Giải mã quan niệm dân gian về sức khỏe', ['Quan niệm đang lưu truyền', 'Điều các nguồn chính thức nói', 'Vì sao dễ tin', 'Lời khuyên an toàn']],
    ],
    'terms' => ['Dược liệu', 'Vị thuốc', 'Bài thuốc', 'Châm cứu', 'Bấm huyệt', 'Dưỡng sinh', 'Âm dương', 'Thể trạng', 'Bào chế', 'Đông y', 'Y học dựa trên bằng chứng'],
    'rules' => ['KHÔNG khẳng định chữa khỏi bệnh bằng thảo dược hay bài thuốc; không dùng "đặc trị", "thần dược", "chữa dứt điểm"', 'Không nêu tên vị thuốc, bài thuốc, công dụng hay liều dùng nếu chưa có nguồn chuyên gia duyệt', 'Phân biệt rõ quan niệm truyền thống với điều đã có nghiên cứu hiện đại', 'Không nói "tự nhiên thì an toàn"; nhắc nguy cơ tương tác với thuốc đang dùng và thận trọng với trẻ em, phụ nữ mang thai', 'Không khuyên bỏ điều trị hiện đại để dùng phương pháp cổ truyền'],
    'do' => ['Tôn trọng truyền thống nhưng trung thực về mức bằng chứng'],
    'dont' => ['Dùng lời chứng thực của người dùng như bằng chứng', 'Bài xích y học hiện đại'],
    'rubric' => ['Không có từ chữa khỏi hay đặc trị', 'Có nêu mức bằng chứng và giới hạn', 'Có nhắc hỏi bác sĩ hoặc thầy thuốc có giấy phép'],
    'sources' => ['who.int', 'moh.gov.vn', 'dav.gov.vn', 'nih.gov', 'pubmed.ncbi.nlm.nih.gov', 'cochranelibrary.com'],
    'hints' => ['Thầy thuốc y học cổ truyền có giấy phép cùng bác sĩ y học hiện đại duyệt'],
]);
