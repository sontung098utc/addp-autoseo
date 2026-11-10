<?php

namespace App\Services\Agent\Run;

use App\Contracts\AiProviderInterface;
use App\Filament\Support\Review\ReviewWorkflow;
use App\Filament\Support\Schedule\VnTime;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\CampaignWait;
use App\Models\User;
use App\Services\Agent\Handoff\CmsDraftHandoff;
use App\Services\Agent\Refresh\AgentRunDispatcher;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Publishing\EmergencyStopService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AG-S01: một "nhịp" của chiến dịch — tự chọn từ khóa kế tiếp rồi giao cho đội AI, trong giới hạn bài/ngày.
 * Đây cũng là rào chắn "nội dung tạo hàng loạt": quá giới hạn thì DỪNG, không giao thêm.
 * Lượt tự động cần AI thật; thiếu khóa thì KHÔNG rơi về giả lập (trừ khi người chạy chủ động bật chế độ giả lập).
 */
final class CampaignRunner
{
    public const AUTO_APPROVE_LABEL = 'Tự duyệt bởi đội AI (chế độ Toàn quyền)';

    public const SYSTEM_EMAIL = 'ai-auto@system.local';

    /** @var list<string> việc Autopilot đã làm trong nhịp hiện tại (hiện ở kết quả nhịp). */
    private array $autopilotActions = [];

    /** @param callable(): ?AiProviderInterface $providerFactory trả null nếu chưa cấu hình AI thật */
    public function __construct(private $providerFactory, private ?EmergencyStopService $stop = null)
    {
        $this->stop ??= app(EmergencyStopService::class);
    }

    /**
     * @return array{result: string, message: string, run: ?AgentRun}
     *                                                                result: ran | auto_approved | paused | stopped | limit | draft_cap | cost_cap | blocked_gap | no_keywords | no_ai | site_inactive
     */
    public function tick(Campaign $c, ?CarbonImmutable $now = null): array
    {
        $this->autopilotActions = [];
        $out = $this->tickCore($c, $now);
        if ($this->autopilotActions !== []) {
            $out['actions'] = $this->autopilotActions;
            $out['message'] = trim($out['message'].' '.implode(' ', $this->autopilotActions));
        }

        return $out;
    }

    /** @return array{result: string, message: string, run: ?AgentRun} */
    private function tickCore(Campaign $c, ?CarbonImmutable $now = null): array
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

        $drafts = AgentRun::query()->where('campaign_id', $c->id)->where('status', 'ready_for_review')->where('started_at', '>=', $from)->where('started_at', '<', $to)->count();
        if ($drafts >= $c->daily_draft_cap) {
            return $this->out('draft_cap', "Đã đủ {$drafts}/{$c->daily_draft_cap} bài nháp hôm nay, chờ ngày mai.");
        }

        $spent = (float) AgentRun::query()->where('campaign_id', $c->id)->where('started_at', '>=', $from)->where('started_at', '<', $to)->sum('cost_vnd');
        if ($spent >= $c->daily_cost_cap_vnd) {
            return $this->out('cost_cap', 'Chi phí AI hôm nay đã chạm trần '.number_format((int) $c->daily_cost_cap_vnd, 0, ',', '.').' đ (đã dùng '.number_format($spent, 0, ',', '.').' đ), chờ ngày mai.');
        }

        // Autopilot (chỉ chế độ Toàn quyền): giữ hàng chờ kế hoạch đủ đầy trước khi chọn bài kế tiếp.
        $autopilot = (new \App\Services\Agent\Autopilot\AutopilotRunner)->tick($c, $now);
        $this->autopilotActions = $autopilot['actions'];

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
            $run = AgentRunDispatcher::run($article, $ai, null, 'schedule', $c->id, stop: $this->stop, policy: AgentAi::policy(), handoff: app(CmsDraftHandoff::class));
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

        if ($c->mode === 'autonomous' && $run->status === 'ready_for_review') {
            return $this->autoReview($c, $run, $keyword);
        }

        return ['result' => 'ran', 'message' => "Đã giao \"{$keyword}\": phiên #{$run->id} ({$run->status}).", 'run' => $run];
    }

    /**
     * Chế độ Toàn quyền: tự duyệt có điều kiện. Chỉ tự duyệt khi KHÔNG có quyết định giữ cho chuyên gia, rủi ro không cao và không còn lỗi chặn P0
     * (ReviewWorkflow::approve tự từ chối nếu còn P0 mà không có lý do ngoại lệ; ta không đưa lý do ngoại lệ). Bài còn lại ở "Chờ duyệt" cho người.
     * Không xuất bản: việc đẩy nháp lên CMS vẫn do bậc tin cậy hiện có quyết định.
     *
     * @return array{result: string, message: string, run: ?AgentRun}
     */
    private function autoReview(Campaign $c, AgentRun $run, string $keyword): array
    {
        $run->load('steps', 'finalVersion');
        $needsExpert = $run->risk_level === 'high'
            || $run->steps->contains(fn ($s) => in_array($s->data['decision'] ?? null, ['high_risk_expert_review', 'brain_must_cite_review'], true) || ! empty($s->data['needs_expert_review']));
        $version = $run->finalVersion;
        $held = fn (string $why) => $this->holdForPeople($c, $run, $keyword, $why);
        if ($needsExpert) {
            return $held('rủi ro cao hoặc cần người có chuyên môn');
        }
        if ($version === null || $version->review_status !== 'needs_review') {
            return $held('chưa có bản chờ duyệt hợp lệ');
        }

        $note = self::AUTO_APPROVE_LABEL.': đạt luật chấm, không còn lỗi chặn, chủ đề không thuộc nhóm rủi ro cao (phiên #'.$run->id.').';
        try {
            app(ReviewWorkflow::class)->approve(self::systemUser(), $version, $note);
        } catch (\InvalidArgumentException $e) {
            return $held('còn lỗi chặn hoặc không duyệt được: '.$e->getMessage());
        }

        $run->forceFill(['outcome_reason' => trim(($run->outcome_reason ? $run->outcome_reason.' ' : '').self::AUTO_APPROVE_LABEL.'.')])->save();
        $this->stepNote($run, 'ok', self::AUTO_APPROVE_LABEL, $note, ['decision' => 'auto_approved', 'version' => $version->version]);
        Log::info('campaign.auto_approved', ['campaign_id' => $c->id, 'run_id' => $run->id, 'article_id' => $run->article_id, 'version' => $version->version]);

        return ['result' => 'auto_approved', 'message' => "Đã tự duyệt bài \"{$keyword}\" (phiên #{$run->id}, chế độ Toàn quyền).", 'run' => $run];
    }

    private function holdForPeople(Campaign $c, AgentRun $run, string $keyword, string $why): array
    {
        $this->stepNote($run, 'warn', 'Chờ người xem (chế độ Toàn quyền)', "Không tự duyệt: {$why}.", ['decision' => 'auto_approve_skipped']);
        Log::info('campaign.auto_approve_skipped', ['campaign_id' => $c->id, 'run_id' => $run->id, 'why' => $why]);

        return ['result' => 'ran', 'message' => "Bài \"{$keyword}\" chờ người xem vì rủi ro cao hoặc cần chuyên môn (phiên #{$run->id}).", 'run' => $run];
    }

    private function stepNote(AgentRun $run, string $status, string $title, string $detail, array $data): void
    {
        $now = now();
        AgentRunStep::create(['agent_run_id' => $run->id, 'seq' => (int) $run->steps()->max('seq') + 1, 'stage' => 'decision', 'role' => 'system', 'status' => $status, 'title' => $title, 'detail' => $detail,
            'data' => ['role_key' => 'system', 'role_name' => 'Hệ thống'] + $data, 'tokens_used' => 0, 'cost_vnd' => 0, 'started_at' => $now, 'finished_at' => $now]);
    }

    /** Người thực hiện hệ thống cho việc tự duyệt: tạo một lần, tái dùng. Không đăng nhập được (mật khẩu ngẫu nhiên). */
    public static function systemUser(): User
    {
        $u = User::query()->where('email', self::SYSTEM_EMAIL)->first();
        if ($u === null) {
            $u = User::create(['name' => 'Đội AI (tự động)', 'email' => self::SYSTEM_EMAIL, 'password' => Str::random(64), 'role' => 'approver', 'is_active' => true]);
        } elseif (! $u->is_active || $u->role !== 'approver') {
            $u->forceFill(['is_active' => true, 'role' => 'approver'])->save();
        }

        return $u;
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
