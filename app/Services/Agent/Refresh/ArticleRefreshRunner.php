<?php

namespace App\Services\Agent\Refresh;

use App\Contracts\AiProviderInterface;
use App\Contracts\PartialRefreshInterface;
use App\Filament\Support\Schedule\ErrorMasker;
use App\Models\AgentRun;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Services\Agent\Ai\AiModelRouter;
use App\Services\Agent\Ai\RoutedAiProvider;
use App\Services\Agent\Handoff\CmsDraftHandoff;
use App\Services\Agent\Risk\RiskClassifier;
use App\Services\Agent\Run\AgentRunOrchestrator;
use App\Services\Agent\Run\JobRunRecorder;
use App\Services\Agent\Run\RunPolicy;
use App\Services\Agent\Run\RunScorer;
use App\Services\Ai\DemoScriptedAiProvider;
use App\Services\Ai\MockAiProvider;
use App\Services\Audit\RefreshOptions;
use App\Services\Brain\BrainContext;
use App\Services\Content\HtmlSanitizer;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityReport;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * G5: làm mới bài SỬA MỘT PHẦN (job_type article_refresh).
 * guard → chấm bản hiện tại → phân loại phạm vi (luật) → meta / mục / viết lại → chấm lại → cổng (điểm sau ≥ trước, P0 không tăng) → bàn giao.
 * Viết lại cả bài (hoặc AI không hỗ trợ sửa một phần) giao cho AgentRunOrchestrator như cũ. Không bao giờ xuất bản; lỗi thì giữ bản cũ nguyên vẹn.
 * Chưa gọi AI thật nên chưa đo được mức tiết kiệm token thật.
 */
final class ArticleRefreshRunner
{
    public const JOB_TYPE = 'article_refresh';

    public function __construct(
        private AiProviderInterface $ai,
        private ContentQualityChecker $checker = new ContentQualityChecker,
        private ?EmergencyStopService $stop = null,
        private RunPolicy $policy = new RunPolicy,
        private ?CmsDraftHandoff $handoff = null,
        private ?AiModelRouter $router = null,
    ) {
        if ($this->router === null && $this->ai instanceof RoutedAiProvider) {
            $this->router = $this->ai->router();
        }
        $this->stop ??= app(EmergencyStopService::class);
    }

    public function run(Article $article, ?User $actor = null, string $trigger = 'manual', ?int $campaignId = null): AgentRun
    {
        $old = $this->latest($article);
        if ($old === null) {
            return $this->rewrite($article, $actor, $trigger, $campaignId, 'Bài chưa có phiên bản nào có nội dung để sửa một phần.');
        }
        $site = $article->website;
        $blocked = $this->stop->isStopped($article->website_id) ? ['Đang dừng khẩn cấp.', 'Hệ thống hoặc website này đang bị dừng; đội AI không chạy để khỏi tốn chi phí.']
            : (! $site || $site->status !== 'active' ? ['Website không hoạt động.', 'Website chưa ở trạng thái hoạt động nên không có nơi nhận bản nháp.'] : null);
        $run = $this->newRun($article, $actor, $trigger, $campaignId);
        if ($blocked !== null) {
            $this->step($run, 'guard', 'blocked', $blocked[0], $blocked[1]);

            return $this->done($run, 'blocked', $blocked[0]);
        }
        $this->step($run, 'guard', 'ok', 'Được phép chạy', 'Không có lệnh dừng, website hoạt động, bậc tin cậy '.$this->policy->trustTier.' (chỉ tạo bản nháp).');

        try {
            return $this->execute($run, $article, $old, $actor, $trigger, $campaignId);
        } catch (Throwable $e) {
            $this->step($run, 'handoff', 'failed', 'Phiên làm mới gặp lỗi', 'Bản cũ được giữ nguyên, bài giữ trạng thái cũ, không có gì được đăng. Lỗi: '.ErrorMasker::short($e->getMessage(), 160), ['error' => class_basename($e)]);

            return $this->done($run, 'failed', 'Lỗi: '.class_basename($e).'. Bản cũ được giữ nguyên.');
        }
    }

    private function execute(AgentRun $run, Article $article, ArticleVersion $old, ?User $actor, string $trigger, ?int $campaignId): AgentRun
    {
        $brainIds = BrainContext::forArticle($article, $campaignId)->ids() ?: null;

        // 2. Chấm bản hiện tại.
        $before = $this->checker->check($old, $article, $brainIds);
        $scoreBefore = RunScorer::score($before);
        $p0Before = $before->counts()['P0'];
        $this->step($run, 'grade_old', 'ok', 'Chấm bản hiện tại (v'.$old->version.')', "Điểm {$scoreBefore}/100, {$p0Before} lỗi P0. Quyết định do luật, không do AI.", ['version' => $old->version, 'score' => $scoreBefore, 'counts' => $before->counts(), 'failed' => $this->failedData($before)]);

        // 3. Phân loại phạm vi.
        $auditCodes = (array) (RefreshOptions::for($article)['refresh']['issue_codes'] ?? []);
        $scope = RefreshScope::classify($before, $auditCodes, (string) $old->html_content);
        $this->step($run, 'scope', 'ok', 'Phạm vi sửa: '.$scope['scope'], implode(' ', $scope['reasons']), ['scope' => $scope['scope'], 'reasons' => $scope['reasons'], 'section_indexes' => $scope['section_indexes'], 'audit_codes' => $auditCodes]);

        if ($scope['scope'] === RefreshScope::NONE) {
            return $this->done($run, 'held', 'Không tìm thấy lỗi cần sửa.');
        }

        $needMeta = $scope['scope'] === RefreshScope::META || str_contains(implode(' ', $scope['reasons']), 'tiêu đề SEO/meta');
        $needSections = $scope['scope'] === RefreshScope::SECTIONS;
        $why = $scope['scope'] === RefreshScope::REWRITE ? 'Phạm vi sửa là viết lại cả bài.' : null;

        // Chọn AI sửa một phần; không có thì rơi về viết lại và nói rõ vì sao.
        $metaAi = $secAi = null;
        if ($why === null && $needMeta) {
            [$metaAi, $why] = $this->partialAi('meta_regen');
        }
        if ($why === null && $needSections) {
            [$secAi, $why] = $this->partialAi('section_repair');
        }
        if ($why !== null) {
            $this->step($run, 'rewrite', 'warn', 'Chuyển sang viết lại cả bài', $why.' Phiên này giao cho đội viết bài như thường lệ.', ['reason' => $why]);

            return $this->rewrite($article, $actor, $trigger, $campaignId, $why, $run);
        }

        $attrs = $old->only(['article_id', 'title', 'slug', 'outline', 'html_content', 'seo_title', 'meta_description', 'focus_keyword', 'faq_schema', 'sources_cited']);
        $feedback = array_values(array_unique(array_merge($scope['reasons'], array_map(fn ($f) => $f['code'].': '.$f['message'], $this->failedData($before)))));
        $tokens = 0;
        $cost = 0.0;
        $model = null;
        $calls = 0;

        // 4a. Meta.
        if ($needMeta) {
            if (($stopped = $this->budgetStop($run, $calls)) !== null) {
                return $stopped;
            }
            $r = $metaAi[0]->regenerateMeta($article, $old, $feedback, $this->aiOptions($article));
            $calls++;
            $attrs['seo_title'] = trim(strip_tags((string) ($r['seo_title'] ?? ''))) ?: $old->seo_title;
            $attrs['meta_description'] = trim(strip_tags((string) ($r['meta_description'] ?? ''))) ?: $old->meta_description;
            [$tokens, $cost, $model] = [$tokens + (int) ($r['tokens_used'] ?? 0), $cost + (float) ($r['cost_vnd'] ?? 0), $r['model'] ?? $metaAi[1]];
            $this->step($run, 'meta', 'ok', 'Sinh lại tiêu đề SEO và meta', 'Chỉ đổi seo_title và meta_description; thân bài giữ nguyên từng byte.', ['changed' => ['seo_title', 'meta_description']], $r['model'] ?? null, (int) ($r['tokens_used'] ?? 0), (float) ($r['cost_vnd'] ?? 0), 'writer');
        }

        // 4b. Các mục có lỗi.
        if ($needSections) {
            if (($stopped = $this->budgetStop($run, $calls)) !== null) {
                return $stopped;
            }
            $all = HtmlSections::split((string) $old->html_content)['sections'];
            $send = array_values(array_filter($all, fn ($s) => in_array($s['index'], $scope['section_indexes'], true)));
            $r = $secAi[0]->repairSections($article, $old, $send, $feedback, $this->aiOptions($article));
            $calls++;
            $repl = [];
            foreach ($send as $s) {
                if (isset($r['sections'][$s['index']]) && trim((string) $r['sections'][$s['index']]) !== '') {
                    $repl[$s['index']] = HtmlSanitizer::clean((string) $r['sections'][$s['index']]);
                }
            }
            $attrs['html_content'] = HtmlSections::replace((string) $old->html_content, $repl);
            [$tokens, $cost, $model] = [$tokens + (int) ($r['tokens_used'] ?? 0), $cost + (float) ($r['cost_vnd'] ?? 0), $r['model'] ?? $secAi[1]];
            $this->step($run, 'section', 'ok', 'Sửa '.count($repl).'/'.count($send).' mục có lỗi', 'Chỉ gửi '.count($send).' trên tổng '.count($all).' mục; các mục khác giữ nguyên từng byte.',
                ['sections_sent' => count($send), 'sections_total' => count($all), 'section_indexes' => $scope['section_indexes'], 'sections_replaced' => array_keys($repl)], $r['model'] ?? null, (int) ($r['tokens_used'] ?? 0), (float) ($r['cost_vnd'] ?? 0), 'writer');
        }

        // 5. Chấm lại và cổng.
        $after = $this->checker->check(new ArticleVersion($attrs), $article, $brainIds);
        $scoreAfter = RunScorer::score($after);
        $p0After = $after->counts()['P0'];
        $passed = $scoreAfter >= $scoreBefore && $p0After <= $p0Before;
        $this->step($run, 'grade', $passed ? 'ok' : 'warn', 'Chấm lại bản mới', "Điểm {$scoreBefore} → {$scoreAfter}, lỗi P0 {$p0Before} → {$p0After}. ".($passed ? 'Không thấp hơn bản cũ.' : 'Thấp hơn bản cũ nên không đưa vào hàng chờ duyệt.'),
            ['score_before' => $scoreBefore, 'score_after' => $scoreAfter, 'p0_before' => $p0Before, 'p0_after' => $p0After, 'passed' => $passed, 'counts' => $after->counts(), 'failed' => $this->failedData($after)]);

        // 6. Bàn giao.
        $version = DB::transaction(function () use ($article, $old, $attrs, $passed, $tokens, $cost, $model) {
            $v = ArticleVersion::create($attrs + [
                'version' => ($article->versions()->max('version') ?? 0) + 1, 'ai_provider' => $model ?? $old->ai_provider, 'ai_model' => $model ?? $old->ai_model,
                'prompt_version' => $old->prompt_version, 'tokens_used' => $tokens, 'cost_vnd' => $cost, 'review_status' => $passed ? 'needs_review' : 'drafting',
            ]);
            foreach (DB::table('article_version_media')->where('article_version_id', $old->id)->get() as $m) { // giữ ảnh đại diện/ảnh gắn của bản cũ
                DB::table('article_version_media')->insert(['article_version_id' => $v->id, 'media_asset_id' => $m->media_asset_id, 'is_featured' => $m->is_featured, 'position' => $m->position, 'custom_alt' => $m->custom_alt, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $v;
        });
        $run->update(['final_version_id' => $version->id]);

        if (! $passed) {
            $reason = "Điểm sau sửa {$scoreAfter} thấp hơn hoặc lỗi P0 tăng so với bản cũ ({$scoreBefore} → {$scoreAfter}; P0 {$p0Before} → {$p0After}). Bản v{$version->version} lưu làm nháp để người xem, không vào hàng chờ duyệt; bài giữ trạng thái cũ.";
            $this->step($run, 'handoff', 'warn', 'Giữ nháp, chờ người xem', $reason, ['version' => $version->version]);

            return $this->done($run, 'held', $reason);
        }
        $article->update(['workflow_status' => 'needs_review']);
        $push = $this->handoff?->push($run, $version, $article->website, true, (new RiskClassifier)->assess($article->primary_keyword)->level);
        $pushed = $push?->status === 'pushed';
        $extra = $pushed ? " Đã đẩy nháp lên {$push->cms}".($push->url ? ": link xem trước {$push->url}" : '').' (không công khai).' : ($push?->note ? ' '.$push->note : '');
        $this->step($run, 'handoff', $push?->status === 'failed' ? 'warn' : 'ok', 'Chuyển người duyệt', 'Bản v'.$version->version.' (sửa một phần từ v'.$old->version.') đã vào hàng chờ duyệt; chưa xuất bản.'.$extra,
            ['version' => $version->version, 'from_version' => $old->version, 'tokens' => $tokens, 'cost_vnd' => $cost] + ($push && $push->status !== 'skipped' ? ['cms_draft' => ['status' => $push->status, 'cms' => $push->cms, 'url' => $push->url, 'remote_id' => $push->remoteId]] : []));

        return $this->done($run, 'ready_for_review', null);
    }

    /** Dừng nếu đã chi hết ngân sách; bản cũ không bị đụng tới. */
    private function budgetStop(AgentRun $run, int $calls): ?AgentRun
    {
        $spent = (float) $run->steps()->sum('cost_vnd');
        if ($spent < $this->policy->budgetVnd) {
            return null;
        }
        $reason = 'Hết ngân sách cho phiên chạy ('.number_format($this->policy->budgetVnd, 0, ',', '.').' ₫) nên không gọi AI thêm; bản cũ được giữ nguyên.';
        $this->step($run, 'handoff', 'warn', 'Dừng vì hết ngân sách', 'Đã chi '.number_format($spent, 0, ',', '.').' ₫. Không tạo phiên bản mới, bài giữ trạng thái cũ.', ['spent_vnd' => $spent, 'budget_vnd' => $this->policy->budgetVnd]);

        return $this->done($run, $calls === 0 ? 'blocked' : 'held', $reason);
    }

    /** @return array{0: ?array{0: PartialRefreshInterface, 1: ?string}, 1: ?string} [[ai, model], lý do không dùng được] */
    private function partialAi(string $task): array
    {
        if ($this->router !== null) {
            if (($why = $this->router->why($task)) !== null) {
                return [null, $why.' Không dùng AI giả lập thay thế.'];
            }
            $t = $this->router->target($task);
            if ($t === null) {
                return [null, 'Chưa có AI cho loại việc "'.$task.'". Không dùng AI giả lập thay thế.'];
            }

            return $t->ai instanceof PartialRefreshInterface ? [[$t->ai, $t->model], null] : [null, 'Model '.$t->label().' chưa hỗ trợ sửa một phần.'];
        }

        return $this->ai instanceof PartialRefreshInterface ? [[$this->ai, null], null] : [null, 'Nhà cung cấp AI hiện tại chưa hỗ trợ sửa một phần.'];
    }

    /** Nhánh viết lại: gọi bộ điều phối như hôm nay, chỉ gắn nhãn job_type lên phiên của nó. */
    private function rewrite(Article $article, ?User $actor, string $trigger, ?int $campaignId, string $why, ?AgentRun $mine = null): AgentRun
    {
        $orch = new AgentRunOrchestrator($this->ai, $this->checker, stop: $this->stop, policy: $this->policy, handoff: $this->handoff, router: $this->router);
        $run = $orch->run($article, $actor, $trigger, $campaignId);
        $run->forceFill(['job_type' => self::JOB_TYPE])->save();
        if ($mine !== null) { // chép các bước guard/chấm/phạm vi sang phiên của bộ điều phối rồi bỏ phiên tạm
            foreach ($mine->steps()->orderBy('seq')->get() as $s) {
                JobRunRecorder::step($run, $s->stage, $s->title, $s->status, (array) $s->data, $s->model, (int) $s->tokens_used, (float) $s->cost_vnd, $s->detail, $s->role);
            }
            $mine->delete();
        }
        $this->step($run, 'rewrite', 'ok', 'Lý do viết lại cả bài', $why, ['reason' => $why]);

        return $run->refresh();
    }

    private function newRun(Article $article, ?User $actor, string $trigger, ?int $campaignId): AgentRun
    {
        return AgentRun::create([
            'website_id' => $article->website_id, 'article_id' => $article->id, 'campaign_id' => $campaignId, 'triggered_by' => $actor?->id, 'trigger' => $trigger, 'job_type' => self::JOB_TYPE,
            'status' => 'running', 'trust_tier' => $this->policy->trustTier, 'ai_mode' => $this->router ? 'real' : (($this->ai instanceof MockAiProvider || $this->ai instanceof DemoScriptedAiProvider) ? 'mock' : 'real'),
            'started_at' => now(),
        ]);
    }

    private function latest(Article $article): ?ArticleVersion
    {
        return $article->versions()->whereNotNull('html_content')->where('html_content', '!=', '')->orderByDesc('version')->first();
    }

    /** @return array<string,mixed> */
    private function aiOptions(Article $article): array
    {
        return RefreshOptions::for($article);
    }

    /** @return list<array{code:string,severity:string,message:string}> */
    private function failedData(QualityReport $r): array
    {
        return array_map(fn ($x) => ['code' => $x->code, 'severity' => $x->severity, 'message' => $x->message], array_slice($r->failed(), 0, 8));
    }

    private function step(AgentRun $run, string $stage, string $status, string $title, string $detail, array $data = [], ?string $model = null, int $tokens = 0, float $cost = 0.0, string $role = 'system'): void
    {
        JobRunRecorder::step($run, $stage, $title, $status, $data, $model, $tokens, $cost, $detail, $role);
    }

    private function done(AgentRun $run, string $status, ?string $reason): AgentRun
    {
        return JobRunRecorder::finish($run, $status, null, $reason) ?? $run->refresh();
    }
}
