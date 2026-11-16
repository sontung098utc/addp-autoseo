<?php

namespace App\Services\Images;

use App\Contracts\ImageGeneratorInterface;

/**
 * Model ảnh chính kèm dự phòng của vai trò Họa viên: chính lỗi thì thử dự phòng một lần cho ảnh đó; cả hai lỗi thì ném lỗi của dự phòng.
 * Chi phí ước tính lấy mức cao hơn của hai để kiểm trần chi phí không bị thấp giả.
 */
final class FallbackImageGenerator implements ImageGeneratorInterface
{
    public function __construct(private readonly ImageGeneratorInterface $primary, private readonly ImageGeneratorInterface $fallback) {}

    public function generate(string $prompt, array $options = []): array
    {
        try {
            return $this->primary->generate($prompt, $options);
        } catch (\Throwable) {
            return $this->fallback->generate($prompt, $options);
        }
    }

    public function estimatedCostVnd(): float
    {
        return max($this->primary->estimatedCostVnd(), $this->fallback->estimatedCostVnd());
    }
}
