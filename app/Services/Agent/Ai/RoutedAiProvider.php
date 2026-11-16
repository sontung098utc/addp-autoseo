<?php

namespace App\Services\Agent\Ai;

use App\Contracts\AiProviderInterface;
use App\Models\Article;
use App\Services\Agent\Run\TransientAiError;

/**
 * AG-S11: "cổng" để mọi nơi đang truyền AiProviderInterface (nút Giao đội AI, nhịp chiến dịch, lệnh dòng lệnh) đi qua bộ định tuyến.
 * AgentRunOrchestrator nhận ra lớp này và dùng router trực tiếp (thử lại, dự phòng có ghi nhật ký, trần chi phí).
 * Dùng ngoài orchestrator thì chỉ gọi model chính (viết/dàn ý) rồi chuyển dự phòng một lần nếu lỗi tạm thời; không có nhật ký run.
 */
final class RoutedAiProvider implements AiProviderInterface
{
    public function __construct(private AiModelRouter $router = new AiModelRouter())
    {
    }

    public function router(): AiModelRouter
    {
        return $this->router;
    }

    public function generateOutline(Article $article, array $options = []): array
    {
        return $this->via('outline', fn (AiProviderInterface $ai) => $ai->generateOutline($article, $options));
    }

    public function generateArticle(Article $article, array $outline, array $options = []): array
    {
        return $this->via('write', fn (AiProviderInterface $ai) => $ai->generateArticle($article, $outline, $options));
    }

    public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
    {
        return (new \App\Services\Ai\TokenCostCalculator())->calculate($promptTokens, $completionTokens, $model)['total_cost_vnd'];
    }

    private function via(string $task, \Closure $call): array
    {
        $t = $this->router->target($task) ?? throw new AiRouteUnavailable($task, (string) $this->router->why($task));
        try {
            return $call($t->ai);
        } catch (\Throwable $e) {
            $fb = TransientAiError::is($e) ? $this->router->fallback($task) : null;
            if (! $fb) {
                throw $e;
            }

            return $call($fb->ai);
        }
    }
}
