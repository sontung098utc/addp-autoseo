<?php

return [
    /**
     * Độ liên quan tối thiểu (0..1) để một mục kho được đưa cho người viết. 0 = không lọc (như trước).
     * Mục dưới ngưỡng bị bỏ: từ khóa lạc đề không còn bị nhồi các mục kho không liên quan rồi trích dẫn gượng; bài đó được ghi "kho chưa có kiến thức phù hợp".
     */
    'min_relevance' => (float) env('KNOWLEDGE_MIN_RELEVANCE', 0.2),
];
