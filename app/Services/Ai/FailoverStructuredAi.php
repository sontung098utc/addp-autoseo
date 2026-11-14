<?php

namespace App\Services\Ai;

use App\Contracts\StructuredAiInterface;

/**
 * Chia việc theo model: thử lần lượt các AI có cấu trúc theo thứ tự ưu tiên (việc nhẹ trước: model rẻ/hạn mức riêng như agy, haiku; model mạnh sau).
 * Một AI lỗi BẤT KỲ (kể cả hết hạn mức của chính nó) thì chuyển sang AI kế tiếp; chỉ ném lỗi khi tất cả đều lỗi. Dùng cho việc phụ trợ
 * (phân loại, đặt yêu cầu ảnh, biên tập) để hạn mức một model không làm dừng cả phiên và việc nhẹ không ăn hạn mức của model viết.
 */
final class FailoverStructuredAi implements StructuredAiInterface
{
    /** @var list<StructuredAiInterface> */
    private array $chain;

    /** @param list<StructuredAiInterface> $chain */
    public function __construct(array $chain)
    {
        $seen = [];
        $this->chain = [];
        foreach ($chain as $ai) {
            if ($ai instanceof StructuredAiInterface && ! in_array(spl_object_id($ai), $seen, true)) {
                $seen[] = spl_object_id($ai);
                $this->chain[] = $ai;
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->chain === [];
    }

    public function generateStructured(string $system, string $user): array
    {
        $last = null;
        foreach ($this->chain as $ai) {
            try {
                return $ai->generateStructured($system, $user);
            } catch (\Throwable $e) {
                $last = $e;
            }
        }

        throw $last ?? new AiProviderError('Chưa có AI nào hỗ trợ JSON có cấu trúc.');
    }
}
