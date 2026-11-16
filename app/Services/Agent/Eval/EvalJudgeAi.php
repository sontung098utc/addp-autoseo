<?php

namespace App\Services\Agent\Eval;

use App\Contracts\StructuredAiInterface;
use App\Services\Agent\Ai\AiModelRouter;
use App\Services\Agent\Settings\AgentAi;

/**
 * Q0: chọn AI cho các GIÁM KHẢO đo chất lượng (độ phủ ý định, kiểm nghĩa trích dẫn). Cùng thứ tự với orchestrator:
 * route `citation_check`, rồi `summary`, rồi `repair`; chưa có định tuyến thì dùng AI thật của đội AI nếu hỗ trợ JSON có cấu trúc.
 * Không có AI thật thì trả null (các số đo cần AI sẽ là `not_run`); KHÔNG BAO GIỜ rơi về bản giả lập.
 */
final class EvalJudgeAi
{
    /** Chỉ dùng trong test: thay thế kết quả chọn AI. */
    private static ?StructuredAiInterface $fake = null;

    private static bool $useFake = false;

    public static function fake(?StructuredAiInterface $ai): void
    {
        self::$fake = $ai;
        self::$useFake = true;
    }

    public static function reset(): void
    {
        self::$fake = null;
        self::$useFake = false;
    }

    public static function resolve(): ?StructuredAiInterface
    {
        if (self::$useFake) {
            return self::$fake;
        }
        if (AiModelRouter::isConfigured()) {
            $router = new AiModelRouter();
            foreach (['citation_check', 'summary', 'repair'] as $task) {
                $ai = $router->target($task)?->ai;
                if ($ai instanceof StructuredAiInterface) {
                    return $ai;
                }
            }

            return null;
        }
        $ai = AgentAi::real();

        return $ai instanceof StructuredAiInterface ? $ai : null;
    }
}
