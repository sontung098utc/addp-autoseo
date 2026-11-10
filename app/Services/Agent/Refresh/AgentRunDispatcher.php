<?php

namespace App\Services\Agent\Refresh;

use App\Contracts\AiProviderInterface;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\User;
use App\Services\Agent\Ai\AiModelRouter;
use App\Services\Agent\Handoff\CmsDraftHandoff;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Publishing\EmergencyStopService;

/**
 * G5: chọn bộ chạy cho một bài. Bài kind=refresh đã có ít nhất một phiên bản có nội dung → ArticleRefreshRunner (sửa một phần);
 * mọi bài khác → AgentRunOrchestrator như cũ. Nhận đúng những thứ mà ba nơi gọi đang truyền cho orchestrator.
 */
final class AgentRunDispatcher
{
    public static function usesRefresh(Article $article): bool
    {
        return $article->kind === 'refresh'
            && $article->versions()->whereNotNull('html_content')->where('html_content', '!=', '')->exists();
    }

    public static function run(
        Article $article,
        AiProviderInterface $ai,
        ?User $actor = null,
        string $trigger = 'manual',
        ?int $campaignId = null,
        ?EmergencyStopService $stop = null,
        ?RunPolicy $policy = null,
        ?CmsDraftHandoff $handoff = null,
        ?AiModelRouter $router = null,
        bool $allowWithoutKnowledge = false,
    ): AgentRun {
        $policy ??= new RunPolicy;
        if (self::usesRefresh($article)) {
            return (new ArticleRefreshRunner($ai, stop: $stop, policy: $policy, handoff: $handoff, router: $router))->run($article, $actor, $trigger, $campaignId);
        }

        return (new AgentRunOrchestrator($ai, stop: $stop, policy: $policy, handoff: $handoff, router: $router))->run($article, $actor, $trigger, $campaignId, null, $allowWithoutKnowledge);
    }
}
