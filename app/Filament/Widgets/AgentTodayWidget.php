<?php

namespace App\Filament\Widgets;

use App\Filament\Clusters\AgentTeam\Pages\PipelineMap;
use App\Filament\Pages\AiUsagePage;
use App\Filament\Pages\BrainBuildPage;
use App\Filament\Resources\AgentRunResource;
use App\Filament\Resources\ArticleResource;
use App\Filament\Support\Agent\RunActivity;
use App\Models\AgentRun;
use App\Models\Article;
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
        $expert = AgentRun::query()->where('created_at', '>=', now()->subDays(3))->where('status', 'ready_for_review')
            ->whereHas('steps', fn ($q) => $q->where('stage', 'decision')->where('title', 'like', '%chuyên môn%'))->count();
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

        return [
            'running' => $running,
            'waiting' => Article::query()->where('workflow_status', 'needs_review')->count(),
            'expert' => $expert,
            'blocked' => (clone $today)->whereIn('status', ['blocked', 'failed'])->count(),
            'runs' => (clone $today)->count(),
            'cost' => (float) (clone $today)->sum('cost_vnd'),
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
