<?php

namespace App\Contracts;

/**
 * AG-S21: lời gọi AI trả JSON có cấu trúc cho tác vụ ngoài viết bài (ví dụ tách kho kiến thức).
 * Bên gọi PHẢI kiểm schema đầu ra: AI chỉ đề xuất dữ liệu, không điều khiển công cụ.
 */
interface StructuredAiInterface
{
    /**
     * @return array{data: array<string,mixed>, tokens_used: int, cost_vnd: float, model: string}
     *
     * @throws \App\Services\Ai\AiSchemaError khi phản hồi không phải JSON
     */
    public function generateStructured(string $system, string $user): array;
}
