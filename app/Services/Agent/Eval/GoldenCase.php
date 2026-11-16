<?php

namespace App\Services\Agent\Eval;

/**
 * Một mẫu vàng: đầu vào của playbook và kết quả MONG ĐỢI sau khi chấm bằng luật.
 * `expectBlocking` = đúng tập mã luật P0 phải trượt (rỗng = bài phải sạch P0).
 */
final class GoldenCase
{
    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $expectBlocking
     */
    public function __construct(
        public readonly string $id,
        public readonly string $playbook,
        public readonly array $input,
        public readonly array $expectBlocking,
        public readonly string $note = '',
    ) {
    }
}
