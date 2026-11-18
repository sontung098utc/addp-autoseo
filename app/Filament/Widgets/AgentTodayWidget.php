<?php

namespace App\Filament\Widgets;

use App\Filament\Clusters\AgentTeam\Pages\PipelineMap;
use App\Filament\Pages\AiUsagePage;
use App\Filament\Pages\BrainBuildPage;
use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleResource;
use App\Filament\Support\Agent\RunActivity;
use App\Filament\Support\AiUsage\RunCost;
use App\Filament\Support\Review\ReviewQueue;
use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\Website;
use App\Services\Knowledge\Suggest\KnowledgeSuggestions;
use Filament\Widgets\Widget;

/** "Đội AI hôm nay": quản lý nhìn nhanh việc đang chạy, chờ người, cần chuyên gia, bị chặn và chi phí; mỗi số dẫn tới nơi xử lý. Chỉ đếm dữ liệu thật. */
class AgentTodayWidget extends Widget
{
    protected static string $view = 'filament.widgets.agent-today';

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $since = now()->startOfDay();
        $today = AgentRun::query()->where('created_at', '>=', $since);
        $running = AgentRun::query()->where('status', 'running')->get()->filter(fn ($r) => RunActivity::isLive($r))->count();
        $expert = ReviewQueue::expertCount(now()->subDays(3));
        $suggestions = 0;
        if (class_exists(KnowledgeSuggestions::class)) {
            try {
                $svc = new KnowledgeSuggestions;
                foreach (Website::query()->where('status', 'active')->get() as $w) {
                    $suggestions += count($svc->forWebsite($w, 50));
                }
            } catch (\Throwable) {
                // số gợi ý chỉ là thông tin phụ: lỗi thì để 0, không làm hỏng Tổng quan
            }
        }

        $isApprover = (bool) auth()->user()?->isApprover();

        return [
            'isApprover' => $isApprover,
            'running' => $running,
            'waiting' => ReviewQueue::waitingCount(),
            'expert' => $expert,
            'blocked' => ReviewQueue::problemRunCount($since),
            'runs' => (clone $today)->count(),
            'cost' => RunCost::between(VnTime::todayRangeApp()[0], VnTime::todayRangeApp()[1], 'created_at'),
            'suggestions' => $suggestions,
            'urls' => [
                'pipeline' => class_exists(PipelineMap::class) ? PipelineMap::getUrl() : null,
                'review' => ArticleResource::getUrl('index', ['activeTab' => 'needs_review']),
                'runs' => AgentRunResource::getUrl('index'),
                'kb' => class_exists(BrainBuildPage::class) ? BrainBuildPage::getUrl() : null,
                'cost' => class_exists(AiUsagePage::class) ? AiUsagePage::getUrl() : null,
            ],
        ];
    }
}
