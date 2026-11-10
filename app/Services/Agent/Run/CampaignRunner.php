<?php

namespace App\Services\Agent\Run;

use App\Contracts\AiProviderInterface;
use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\CampaignWait;
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
     *   result: ran | paused | stopped | limit | draft_cap | cost_cap | mode_locked | blocked_gap | no_keywords | no_ai | site_inactive
     */
    public function tick(Campaign $c, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $c->update(['last_tick_at' => $now]);
        self::expireWaits($c, $now);

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

        if ($c->mode === 'autonomous') {
            return $this->out('mode_locked', 'Chế độ Tự chủ đang khóa (cần văn bản chính sách của bạn và connector hỗ trợ gỡ bài). Chiến dịch không giao việc; hãy chuyển sang Thận trọng hoặc Cân bằng.');
        }

        $drafts = AgentRun::query()->where('campaign_id', $c->id)->where('status', 'ready_for_review')->where('started_at', '>=', $from)->where('started_at', '<', $to)->count();
        if ($drafts >= $c->daily_draft_cap) {
            return $this->out('draft_cap', "Đã đủ {$drafts}/{$c->daily_draft_cap} bài nháp hôm nay, chờ ngày mai.");
        }

        $spent = (float) AgentRun::query()->where('campaign_id', $c->id)->where('started_at', '>=', $from)->where('started_at', '<', $to)->sum('cost_vnd');
        if ($spent >= $c->daily_cost_cap_vnd) {
            return $this->out('cost_cap', 'Chi phí AI hôm nay đã chạm trần '.number_format((int) $c->daily_cost_cap_vnd, 0, ',', '.').' đ (đã dùng '.number_format($spent, 0, ',', '.').' đ), chờ ngày mai.');
        }

        $article = null;
        $keyword = null;
        if ($c->source === 'plan') {
            $article = $this->nextPlanned($c);
            $keyword = $article?->primary_keyword;
        }
        $keyword ??= $this->nextKeyword($c);
        if ($keyword === null) {
            self::raiseWait($c, 'no_keywords', $c->source === 'plan' ? 'Chưa có bài đã lên kế hoạch hoặc từ khóa để giao' : 'Danh sách từ khóa đã giao hết',
                'Thêm bài vào Kế hoạch nội dung hoặc thêm từ khóa cho chiến dịch. Chiến dịch không chặn việc khác; sau 24 giờ sẽ tự bỏ qua và ghi giới hạn.', $now);

            return $this->out('no_keywords', 'Đã giao hết từ khóa trong danh sách.');
        }
        CampaignWait::query()->where('campaign_id', $c->id)->where('code', 'no_keywords')->where('status', 'open')->update(['status' => 'resolved']);

        $ai = ($this->providerFactory)();
        if ($ai === null) {
            return $this->out('no_ai', 'Chưa cấu hình AI thật nên lượt tự động không chạy (không dùng bản giả lập một cách im lặng).');
        }

        $fromPlan = $article !== null;
        $article ??= Article::create(['website_id' => $c->website_id, 'created_by' => $c->created_by, 'primary_keyword' => $keyword, 'workflow_status' => 'planned', 'origin' => 'keyword', 'campaign_id' => $c->id]);
        try {
            $run = \App\Services\Agent\Refresh\AgentRunDispatcher::run($article, $ai, null, 'schedule', $c->id, stop: $this->stop, policy: \App\Services\Agent\Settings\AgentAi::policy(), handoff: app(\App\Services\Agent\Handoff\CmsDraftHandoff::class));
        } catch (\Throwable $e) {
            // Không để lại bài mồ côi làm mất lượt của từ khóa này ở nhịp sau.
            if (! $fromPlan && ! AgentRun::query()->where('article_id', $article->id)->exists()) {
                $article->delete();
            }
            throw $e;
        }

        // Chỉ gắn nhãn chế độ; việc đẩy nháp lên CMS là AG-S04.
        $run->forceFill(['trust_mode' => $c->mode])->save();

        if ($run->status === 'blocked' && $run->steps()->where('stage', 'guard')->where('status', 'blocked')->get()->contains(fn ($s) => ($s->data['reason'] ?? null) === 'high_risk_no_knowledge')) {
            self::raiseWait($c, 'kb_gap', "Cần bổ sung kiến thức cho \"{$keyword}\"",
                "Bài \"{$keyword}\" thuộc chủ đề rủi ro cao mà kho chưa có kiến thức đã duyệt khớp nên đội AI không viết (phiên #{$run->id}). Cách xử lý: bổ sung kho (Kho trí tuệ / Xây kho từ website) rồi giao lại bài này, hoặc giao lại và chọn \"Vẫn viết dù kho chưa có kiến thức\". Sau 24 giờ sẽ tự bỏ qua và ghi giới hạn.", $now);

            return ['result' => 'blocked_gap', 'message' => "Dừng \"{$keyword}\": chủ đề rủi ro cao, kho chưa có kiến thức (phiên #{$run->id}). Đã ghi mục chờ bổ sung.", 'run' => $run];
        }

        return ['result' => 'ran', 'message' => "Đã giao \"{$keyword}\": phiên #{$run->id} ({$run->status}).", 'run' => $run];
    }

    /** Bài 'planned' (từ trang Kế hoạch nội dung) của website, chưa có phiên nào, ưu tiên (priority) cao trước (bài làm mới P0 trước), cùng mức thì cũ nhất trước. */
    public function nextPlanned(Campaign $c): ?Article
    {
        return Article::query()->where('website_id', $c->website_id)->where('workflow_status', 'planned')
            ->whereNotNull('primary_keyword')->where('primary_keyword', '!=', '')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('agent_runs')->whereColumn('agent_runs.article_id', 'articles.id'))
            ->orderByRaw('COALESCE(priority, 0) DESC')->orderBy('id')->first();
    }

    /** Ghi một mục chờ bổ sung hạn 24 giờ (không trùng mục đang mở, không lặp lại trong 24 giờ sau khi đã bỏ qua/hết hạn). */
    public static function raiseWait(Campaign $c, string $code, string $title, string $detail, CarbonImmutable $now): void
    {
        $now = $now->setTimezone(config('app.timezone')); // cột thời gian của Eloquent theo múi giờ ứng dụng
        $recent = CampaignWait::query()->where('campaign_id', $c->id)->where('code', $code)
            ->where(fn ($q) => $q->where('status', 'open')->orWhere('updated_at', '>', $now->subDay()))->exists();
        if (! $recent) {
            CampaignWait::create(['campaign_id' => $c->id, 'code' => $code, 'title' => $title, 'detail' => $detail, 'status' => 'open', 'expires_at' => $now->addDay()]);
        }
    }

    /** Quá hạn 24 giờ không được bổ sung thì tự bỏ qua và ghi giới hạn. */
    public static function expireWaits(Campaign $c, CarbonImmutable $now): int
    {
        $now = $now->setTimezone(config('app.timezone'));
        $n = 0;
        foreach (CampaignWait::query()->where('campaign_id', $c->id)->where('status', 'open')->where('expires_at', '<=', $now)->get() as $w) {
            $w->update(['status' => 'expired', 'limit_note' => 'Tự bỏ qua sau 24 giờ không được bổ sung: "'.$w->title.'". Phần việc này chưa được làm.']);
            $n++;
        }

        return $n;
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
