<?php

namespace App\Services\Agent\Run;

use App\Contracts\AiProviderInterface;
use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;
use App\Services\Publishing\EmergencyStopService;
use Carbon\CarbonImmutable;

/**
 * AG-S01: một "nhịp" của chiến dịch — tự chọn từ khóa kế tiếp rồi giao cho đội AI, trong giới hạn bài/ngày.
 * Đây cũng là rào chắn "nội dung tạo hàng loạt": quá giới hạn thì DỪNG, không giao thêm.
 * Lượt tự động cần AI thật; thiếu khóa thì KHÔNG rơi về giả lập (trừ khi người chạy chủ động bật chế độ giả lập).
 */
final class CampaignRunner
{
    /** @param callable(): ?AiProviderInterface $providerFactory trả null nếu chưa cấu hình AI thật */
    public function __construct(private $providerFactory, private ?EmergencyStopService $stop = null)
    {
        $this->stop ??= app(EmergencyStopService::class);
    }

    /**
     * @return array{result: string, message: string, run: ?AgentRun}
     *   result: ran | paused | stopped | limit | no_keywords | no_ai | site_inactive
     */
    public function tick(Campaign $c, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $c->update(['last_tick_at' => $now]);

        if ($c->status !== 'active') {
            return $this->out('paused', 'Chiến dịch đang tạm dừng.');
        }
        if ($this->stop->isStopped($c->website_id)) {
            return $this->out('stopped', 'Đang dừng khẩn cấp, không giao việc.');
        }
        if (($c->website?->status) !== 'active') {
            return $this->out('site_inactive', 'Website không hoạt động.');
        }

        [$from, $to] = VnTime::dayRangeUtc(VnTime::toVn($now) ?? $now);
        $today = AgentRun::query()->where('campaign_id', $c->id)->where('started_at', '>=', $from)->where('started_at', '<', $to)->count();
        if ($today >= $c->daily_limit) {
            return $this->out('limit', "Đã đủ {$today}/{$c->daily_limit} bài hôm nay, chờ ngày mai.");
        }

        $keyword = $this->nextKeyword($c);
        if ($keyword === null) {
            return $this->out('no_keywords', 'Đã giao hết từ khóa trong danh sách.');
        }

        $ai = ($this->providerFactory)();
        if ($ai === null) {
            return $this->out('no_ai', 'Chưa cấu hình AI thật nên lượt tự động không chạy (không dùng bản giả lập một cách im lặng).');
        }

        $article = Article::create(['website_id' => $c->website_id, 'created_by' => $c->created_by, 'primary_keyword' => $keyword, 'workflow_status' => 'planned']);
        try {
            $run = (new AgentRunOrchestrator($ai, stop: $this->stop))->run($article, null, 'schedule', $c->id);
        } catch (\Throwable $e) {
            // Không để lại bài mồ côi làm mất lượt của từ khóa này ở nhịp sau.
            if (! AgentRun::query()->where('article_id', $article->id)->exists()) {
                $article->delete();
            }
            throw $e;
        }

        return ['result' => 'ran', 'message' => "Đã giao \"{$keyword}\": phiên #{$run->id} ({$run->status}).", 'run' => $run];
    }

    /** Từ khóa đầu tiên chưa có bài nào trên website (so khớp không phân biệt hoa thường). */
    public function nextKeyword(Campaign $c): ?string
    {
        $used = Article::query()->where('website_id', $c->website_id)->pluck('primary_keyword')->map(fn ($k) => mb_strtolower(trim($k)))->all();
        foreach ((array) $c->keywords as $k) {
            $k = trim((string) $k);
            if ($k !== '' && ! in_array(mb_strtolower($k), $used, true)) {
                return $k;
            }
        }

        return null;
    }

    /** @return array{result: string, message: string, run: ?AgentRun} */
    private function out(string $result, string $message): array
    {
        return ['result' => $result, 'message' => $message, 'run' => null];
    }
}
