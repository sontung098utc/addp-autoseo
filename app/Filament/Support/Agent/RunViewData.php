<?php

namespace App\Filament\Support\Agent;

use App\Filament\Resources\AgentRunResource;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Services\Agent\Pipeline\JobTypeRegistry;

/**
 * Dữ liệu cho phần "chi tiết một phiên chạy" (partials/run-detail): dùng chung cho trang phiên không gắn bài và tab Phiên chạy của trang bài,
 * để hai nơi hiện y hệt nhau. Làm mới phiên mỗi lần gọi (poll thấy bước mới và trạng thái mới).
 */
final class RunViewData
{
    /** Tên hiển thị: bài viết (tiêu đề bản bài, không có thì từ khóa chính); phiên không gắn bài thì tên loại việc. */
    public static function articleName(AgentRun $run): string
    {
        if (! $run->article_id && $run->job_type && $run->job_type !== 'article_write') {
            return JobTypeRegistry::find($run->job_type)['title'] ?? $run->job_type;
        }
        $title = trim((string) $run->finalVersion?->title);

        return $title !== '' ? $title : (trim((string) $run->article?->primary_keyword) ?: 'Phiên chạy #'.$run->id);
    }

    /** @return array<string,mixed> */
    public static function for(AgentRun $run): array
    {
        $run->refresh()->load(['steps', 'article', 'website', 'finalVersion']);
        $live = RunActivity::isLive($run);
        $stale = RunActivity::isStale($run);
        $current = ($live || $stale) ? RunActivity::currentStageOf($run, $run->steps) : null;

        $flow = RunFlow::build($run, $run->steps, $live, $stale, $current);
        $problem = $live || $stale || in_array($run->status, ['failed', 'blocked', 'held'], true) || collect($flow['nodes'])->contains('state', 'waiting');

        return [
            'run' => $run,
            'verdict' => RunFlow::verdict($run, $flow, $live, $stale),
            'focus' => RunFlow::focusStage($flow['nodes']),
            // Mở phiên nào cũng vào tab Diễn biến trước (luồng, chỉ số); Báo cáo và Chi phí ở tab bên cạnh.
            'tab' => 'dien-bien',
            'name' => self::articleName($run),
            'campaign' => $run->campaign_id ? Campaign::query()->find($run->campaign_id) : null,
            'blueprint' => AgentFlowBlueprint::byStage(),
            'statusMap' => AgentRunResource::STATUS,
            'live' => $live,
            'stale' => $stale,
            'currentStage' => $live ? $current : null,
            'flow' => $flow,
            'elapsed' => RunActivity::formatElapsed(RunActivity::elapsedSeconds($run)),
            'pollSeconds' => $live ? RunActivity::POLL_LIVE : null,
        ];
    }
}
