<?php

namespace App\Contracts;

/** Nhà cung cấp tạo ảnh. Lỗi: ném App\Services\Images\ImageGenerationError (thông điệp đã che khóa). */
interface ImageGeneratorInterface
{
    /**
     * @param  array{aspect?:string, role?:string}  $options  aspect: '16:9' | '4:3' | '1:1'
     * @return array{bytes:string, mime:string, model:string, cost_vnd:float, prompt:string, revised_prompt:?string}
     */
    public function generate(string $prompt, array $options = []): array;

    /** Chi phí ước tính một ảnh (VND), dùng để kiểm trần TRƯỚC khi gọi. */
    public function estimatedCostVnd(): float;
}
