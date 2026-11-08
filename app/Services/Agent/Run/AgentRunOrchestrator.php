<?php

namespace App\Services\Agent\Run;

use App\Contracts\AiProviderInterface;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Services\Agent\Ai\AiModelRouter;
use App\Services\Agent\Ai\AiRouteUnavailable;
use App\Services\Agent\Ai\CostCapExceeded;
use App\Services\Agent\Ai\RoutedAiProvider;
use App\Services\Agent\Handoff\CmsDraftHandoff;
use App\Services\Agent\Risk\RiskClassifier;
use App\Services\Agent\Roles\RoleRegistry;
use App\Services\Agent\Roles\RoleSwitches;
use App\Services\Ai\AiProviderError;
use App\Services\Ai\AiSchemaError;
use App\Services\Ai\MockAiProvider;
use App\Services\Ai\Playbook\InvalidPlaybook;
use App\Services\Ai\Playbook\Playbook;
use App\Services\Ai\Playbook\PlaybookLoader;
use App\Filament\Support\Schedule\ErrorMasker;
use App\Services\Content\HtmlSanitizer;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityReport;
use App\Services\Knowledge\KnowledgeContext;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AG-S01: lát dọc đầu tiên của đội AI — an toàn → phân hạng → dàn ý → viết → chấm → sửa (có giới hạn) → bàn giao nháp.
 *
 * Nguyên tắc (xem AGENTS.md §1): luật quyết định đạt/không đạt, AI chỉ viết; nội dung AI luôn qua HtmlSanitizer;
 * ở bậc tin cậy T1 phiên chạy KHÔNG BAO GIỜ xuất bản, chỉ tạo bản nháp chờ người duyệt; mọi bước ghi nhật ký kèm LÝ DO.
 * Bộ điều phối này tất định (không để LLM chọn bước). Dev1 thay các điểm nối: hàng đợi (AG-B10), ngân sách (AG-B09), bộ định tuyến model (AG-B06).
 */
final class AgentRunOrchestrator
{
    private int $seq = 0;

    private ?KnowledgeContext $kb = null;

    public function __construct(
        private AiProviderInterface $ai,
        private ContentQualityChecker $checker = new ContentQualityChecker(),
        private RiskClassifier $risk = new RiskClassifier(),
        private ?EmergencyStopService $stop = null,
        private RunPolicy $policy = new RunPolicy(),
        private ?PlaybookLoader $playbooks = null,
        /** Bộ ngủ tiêm được để test không chờ thật; nhận số giây. */
        private ?\Closure $sleep = null,
        /** AG-S04: điểm nối bàn giao nháp lên CMS. Mặc định null = tắt (chỉ CampaignRunner bật). */
        private ?CmsDraftHandoff $handoff = null,
        /** AG-S11: bộ định tuyến model theo loại việc. Null (và $ai không phải RoutedAiProvider) = hành vi cũ, một nhà cung cấp cho mọi bước. */
        private ?AiModelRouter $router = null,
        /** AG-S14: sổ vai trò (tên, kỹ năng) và công tắc vai trò. Null = đọc từ resources/agents/roles và agent_settings. */
        private ?RoleRegistry $roles = null,
        private ?RoleSwitches $switches = null,
    ) {
        if ($this->router === null && $this->ai instanceof RoutedAiProvider) {
            $this->router = $this->ai->router();
        }
        $this->sleep ??= fn (int $seconds) => sleep($seconds);
        $this->stop ??= app(EmergencyStopService::class);
        $this->playbooks ??= new PlaybookLoader(resource_path('playbooks'));
        $this->roles ??= new RoleRegistry();
        $this->switches ??= new RoleSwitches($this->roles);
    }

    private string $trigger = 'manual';

    /** AG-S14: kỹ năng đã chọn cho từng bước (stage => [Playbook|null, ghi chú]); step() ghi vào dữ liệu mỗi bước. */
    private array $skills = [];

    /** @var list<string> vai trò đang tắt (đã lọc: chỉ vai trò được phép tắt) tại thời điểm bắt đầu phiên */
    private array $disabledRoles = [];

    public function run(Article $article, ?User $actor = null, string $trigger = 'manual', ?int $campaignId = null): AgentRun
    {
        $this->seq = 0;
        $this->trigger = $trigger;
        $this->skills = [];
        $this->disabledRoles = $this->switches->disabled();
        $run = AgentRun::create([
            'website_id' => $article->website_id, 'article_id' => $article->id, 'campaign_id' => $campaignId, 'triggered_by' => $actor?->id, 'trigger' => $trigger,
            'status' => 'running', 'trust_tier' => $this->policy->trustTier, 'ai_mode' => $this->router ? 'real' : (($this->ai instanceof MockAiProvider || $this->ai instanceof \App\Services\Ai\DemoScriptedAiProvider) ? 'mock' : 'real'),
            'started_at' => now(),
        ]);
        $previousStatus = $article->workflow_status;

        try {
            return $this->execute($run, $article, $previousStatus);
        } catch (AiRetriesExhausted $e) {
            $this->step($run, 'handoff', 'system', 'failed', 'Nhà cung cấp AI vẫn lỗi sau khi thử lại', 'Đã thử lại '.$this->policy->maxRetries.' lần, nhà cung cấp AI vẫn lỗi tạm thời ('.ErrorMasker::short($e->getMessage(), 160).'). Không thay bằng AI giả lập; bài được trả về trạng thái cũ, không có gì được đăng.', ['error' => class_basename($e), 'attempts' => $e->attempts]);
            $article->update(['workflow_status' => $previousStatus]);

            return $this->finish($run, 'failed', 'Nhà cung cấp AI lỗi tạm thời, đã hết '.$this->policy->maxRetries.' lần thử lại. Chưa có bản nháp nào được tạo.');
        } catch (AiRouteUnavailable $e) {
            $this->step($run, 'guard', 'system', 'blocked', 'Chưa cấu hình AI cho bước này', $e->getMessage().' Tác vụ không chạy và hệ thống không dùng AI giả lập thay thế. Vào Đội AI → Tích hợp AI để nhập khóa hoặc chọn model.', ['task' => $e->task]);
            $article->update(['workflow_status' => $previousStatus]);

            return $this->finish($run, 'blocked', $e->getMessage());
        } catch (CostCapExceeded $e) {
            $article->update(['workflow_status' => $previousStatus]);

            return $this->finish($run, 'failed', $e->getMessage().' Phiên dừng; chưa có bản nháp nào được tạo.');
        } catch (AiProviderError $e) {
            $this->step($run, 'handoff', 'system', 'failed', 'Nhà cung cấp AI trả lỗi', ErrorMasker::short($e->getMessage(), 200).' Lỗi này thử lại không giúp ích nên phiên dừng; không thay bằng AI giả lập.', ['error' => class_basename($e)]);
            $article->update(['workflow_status' => $previousStatus]);

            return $this->finish($run, 'failed', 'Nhà cung cấp AI từ chối hoặc trả sai định dạng: '.ErrorMasker::short($e->getMessage(), 160));
        } catch (Throwable $e) {
            $this->step($run, 'handoff', 'system', 'failed', 'Phiên chạy gặp lỗi', 'Lỗi không lường trước; bài được trả về trạng thái cũ, không có gì được đăng.', ['error' => class_basename($e)]);
            $article->update(['workflow_status' => $previousStatus]);

            return $this->finish($run, 'failed', 'Lỗi hệ thống: '.class_basename($e).'. Chưa có bản nháp nào được tạo.');
        }
    }

    private function execute(AgentRun $run, Article $article, string $previousStatus): AgentRun
    {
        $site = $article->website;
        $this->kb = new KnowledgeContext(); // AG-S20: mỗi lượt chạy truy xuất kiến thức riêng

        // 1. An toàn: dừng khẩn cấp và website phải hoạt động.
        if ($this->stop->isStopped($article->website_id)) {
            $this->step($run, 'guard', 'system', 'blocked', 'Đang dừng khẩn cấp', 'Hệ thống hoặc website này đang bị dừng; đội AI không chạy để khỏi tốn chi phí.');

            return $this->finish($run, 'blocked', 'Đang dừng khẩn cấp.');
        }
        if (! $site || $site->status !== 'active') {
            $this->step($run, 'guard', 'system', 'blocked', 'Website không hoạt động', 'Website chưa ở trạng thái hoạt động nên không có nơi nhận bản nháp.');

            return $this->finish($run, 'blocked', 'Website không hoạt động.');
        }
        $this->step($run, 'guard', 'system', 'ok', 'Được phép chạy', 'Không có lệnh dừng, website hoạt động, bậc tin cậy '.$this->policy->trustTier.' (chỉ tạo bản nháp).');

        // 2. Phân hạng rủi ro theo từ khóa (luật, không dùng AI).
        $assess = $this->risk->assess($article->primary_keyword);
        $run->update(['risk_level' => $assess->level]);
        $this->step($run, 'risk', 'editor', $assess->level === 'high' ? 'warn' : 'ok', 'Hạng rủi ro: '.$this->levelLabel($assess->level),
            $assess->reasons === [] ? 'Chủ đề thường, không chạm nhóm nhạy cảm.' : implode(' ', $assess->reasons).($assess->level === 'high' ? ' Bài cần người có chuyên môn duyệt.' : ''),
            ['level' => $assess->level]);

        if ($this->router) {
            foreach (($this->roleOff('strategist') ? ['write'] : ['outline', 'write']) as $task) {
                if (($why = $this->router->why($task)) !== null) {
                    throw new AiRouteUnavailable($task, $why);
                }
            }
        }

        $article->update(['workflow_status' => 'drafting']);

        // 3. Dàn ý (vai trò Chiến lược; tắt được: bỏ bước này, người viết tự bố cục, các bước sau không phụ thuộc).
        $this->skills['write'] = $this->usePlaybook('writer', 'article-draft');
        if ($this->roleOff('strategist')) {
            $outline = ['outline' => []];
            $this->skills['brief'] = [null, 'Vai trò Chiến lược đang tắt.'];
            $this->step($run, 'brief', 'strategist', 'skipped', 'Bỏ qua bước dàn ý', 'Vai trò Chiến lược đang bị tắt trên tab Vai trò nên không lập dàn ý riêng; người viết tự bố cục theo từ khóa. Cổng an toàn (chấm luật) vẫn chạy đủ.', ['outline' => []]);
        } else {
            $this->skills['brief'] = $this->usePlaybook('brief', 'content-brief');
            [$briefPb, $briefNote] = $this->skills['brief'];
            $briefOptions = ($briefPb ? ['playbook' => $briefPb->body, 'playbook_ref' => $briefPb->ref()] : []) + $this->roleOptions('strategist');
            $outline = $this->callAi($run, 'outline', 'lập dàn ý', fn (AiProviderInterface $ai) => $ai->generateOutline($article, $briefOptions));
            $this->step($run, 'brief', 'strategist', 'ok', 'Lập dàn ý ('.count($outline['outline'] ?? []).' mục)', 'Dàn ý dựa trên từ khóa chính và ý định tìm kiếm của bài. '.$briefNote,
                ['outline' => $outline['outline'] ?? [], 'playbook' => $this->pbData($briefPb)] + $this->providerData($outline), $outline['model'] ?? null, (int) ($outline['tokens_used'] ?? 0), (float) ($outline['cost_vnd'] ?? 0));
        }
        $this->skills['grade'] = $this->skills['repair'] = $this->usePlaybook('editor', 'qa-review');
        [$editorPb, $editorNote] = $this->skills['grade'];

        // 4. Viết.
        $attrs = $this->write($run, $article, $outline['outline'] ?? [], [], 'write', 'Viết bản đầu', null);
        $report = $this->grade($run, $article, $attrs, 'Chấm bản đầu', 0, $editorPb, $editorNote);
        $penalty = $this->penalty($report);
        $score = RunScorer::score($report);

        // 5. Vòng sửa có giới hạn.
        $reason = null;
        $round = 0;
        while ($report->hasBlocking() && $round < $this->policy->maxRepairRounds) {
            if ($this->spent($run) >= $this->policy->budgetVnd) {
                $reason = 'Hết ngân sách cho phiên chạy ('.number_format($this->policy->budgetVnd, 0, ',', '.').' ₫) nên dừng sửa.';
                $this->decision($run, 'budget_exhausted', 'Dừng sửa vì hết ngân sách', $reason, ['spent_vnd' => $this->spent($run), 'budget_vnd' => $this->policy->budgetVnd, 'score' => $score]);
                break;
            }
            $round++;
            if ($this->router && ($why = $this->router->why('repair')) !== null) {
                $round--;
                $reason = 'Chưa có AI cho bước sửa nên giữ nháp: '.$why;
                $this->decision($run, 'repair_route_missing', 'Dừng sửa vì thiếu cấu hình AI', $reason, ['task' => 'repair']);
                break;
            }
            $feedback = array_map(fn ($r) => "{$r->code}: {$r->message}", $report->blocking());
            $attrs = $this->write($run, $article, $outline['outline'] ?? [], $feedback, 'repair', "Sửa lần {$round}", $editorPb);
            $report = $this->grade($run, $article, $attrs, "Chấm lại sau lần sửa {$round}", $round, $editorPb, '');
            $new = $this->penalty($report);
            $newScore = RunScorer::score($report);
            if ($report->hasBlocking() && $new >= $penalty) {
                $reason = 'Sửa không cải thiện điểm (từ '.$penalty.' xuống '.$new.') nên dừng, tránh tốn thêm chi phí.';
                $this->decision($run, 'no_improvement', 'Dừng sửa vì không cải thiện', $reason." Điểm {$score} → {$newScore}.", ['penalty_before' => $penalty, 'penalty_after' => $new, 'score_before' => $score, 'score_after' => $newScore]);
                $penalty = $new;
                $score = $newScore;
                break;
            }
            $penalty = $new;
            $score = $newScore;
        }
        if ($reason === null && $report->hasBlocking() && $round >= $this->policy->maxRepairRounds) {
            $this->decision($run, 'max_rounds', 'Dừng sửa vì hết số vòng cho phép', "Đã sửa {$round}/{$this->policy->maxRepairRounds} vòng, còn lỗi chặn nên không sửa thêm.", ['rounds' => $round, 'max_rounds' => $this->policy->maxRepairRounds, 'score' => $score]);
        }
        $run->update(['repair_rounds' => $round]);

        // 6. Bàn giao: chỉ tạo BẢN NHÁP. Không bao giờ xuất bản ở bậc T1.
        $passed = ! $report->hasBlocking();
        $version = $this->persist($article, $attrs, $passed);
        $run->update(['final_version_id' => $version->id]);
        $this->kb?->recordUsage($version);

        if ($passed && $assess->level === 'high') {
            $this->decision($run, 'high_risk_expert_review', 'Giữ cho người có chuyên môn duyệt', 'Đạt luật nhưng chủ đề rủi ro cao nên hệ thống không coi là xong; cần người có chuyên môn duyệt trước khi dùng.', ['risk_level' => 'high', 'score' => $score]);
        }
        if ($passed) {
            $article->update(['workflow_status' => 'needs_review']);
            $push = $this->handoff?->push($run, $version, $site, true, $assess->level);
            $pushed = $push?->status === 'pushed';
            $pushFailed = $push?->status === 'failed';
            $extra = $pushed ? " Đã đẩy nháp lên {$push->cms}".($push->url ? ": link xem trước {$push->url}" : '').' (không công khai).' : ($push?->note ? ' '.$push->note : '');
            $this->step($run, 'handoff', 'system', $pushFailed ? 'warn' : 'ok', 'Chuyển người duyệt', 'Đạt mọi luật bắt buộc. Bản nháp v'.$version->version.' đã vào hàng chờ duyệt; chưa xuất bản vì bậc tin cậy '.$this->policy->trustTier.'.'.$extra,
                ['version' => $version->version] + ($push && $push->status !== 'skipped' ? ['cms_draft' => ['status' => $push->status, 'cms' => $push->cms, 'url' => $push->url, 'remote_id' => $push->remoteId]] : []));

            $reasonOut = $assess->level === 'high' ? 'Đạt luật nhưng chủ đề rủi ro cao: cần người có chuyên môn duyệt.' : null;
            if ($pushFailed) {
                $reasonOut = trim(($reasonOut ?? '').' '.$push->note);
            }

            return $this->finish($run, 'ready_for_review', $reasonOut);
        }

        $article->update(['workflow_status' => 'drafting']);
        $codes = implode(', ', $report->blockingCodes());
        $reason ??= "Đã sửa {$round} lần nhưng còn lỗi chặn: {$codes}.";
        $this->step($run, 'handoff', 'system', 'warn', 'Giữ nháp, chờ người xem', $reason.' Bản nháp v'.$version->version.' được lưu để người xem, không vào hàng chờ duyệt.', ['blocking' => $report->blockingCodes()]);

        return $this->finish($run, 'held', $reason);
    }

    /** @return array<string, mixed> thuộc tính ArticleVersion (đã làm sạch HTML) */
    private function write(AgentRun $run, Article $article, array $outline, array $feedback, string $stage, string $title, ?Playbook $editorPb): array
    {
        $options = $feedback === [] ? [] : ['feedback' => $feedback];
        // Viết bản đầu dùng kỹ năng của người viết (writer/article-draft); sửa dùng bộ chấm editor/qa-review (STEP_PLAYBOOK).
        $pb = $feedback === [] ? ($this->skills['write'][0] ?? null) : $editorPb;
        if ($pb) {
            $options['playbook'] = $pb->body;
            $options['playbook_ref'] = $pb->ref();
        }
        $options += $this->roleOptions('writer');
        $this->kb ??= new KnowledgeContext();
        $this->kb->load($article, $outline); // AG-S20: kiến thức đã duyệt, truy xuất một lần mỗi lượt chạy
        $options += $this->kb->options();
        [$kbData, $kbNote] = $this->kb->stepInfo();
        $r = $this->callAi($run, $stage, $title, fn (AiProviderInterface $ai) => $ai->generateArticle($article, $outline, $options));
        $html = HtmlSanitizer::clean($r['html_content'] ?? '');
        // Nhà cung cấp thật có thể rơi về bản giả lập một cách im lặng (thiếu khóa, JSON hỏng). Không được gọi đó là AI thật.
        $fellBack = str_starts_with((string) ($r['model'] ?? ''), 'mock');
        if ($fellBack && $run->ai_mode !== 'mock') {
            $run->update(['ai_mode' => 'mock']);
        }
        $silentMock = $fellBack && ! $this->ai instanceof MockAiProvider;
        $this->step($run, $stage, 'writer', $silentMock ? 'warn' : 'ok', $title, ($silentMock ? 'Nhà cung cấp AI đã rơi về bản giả lập (thiếu khóa hoặc phản hồi hỏng); nội dung không phải AI thật. ' : '').($feedback === []
            ? 'Viết theo dàn ý; HTML được làm sạch trước khi chấm.'.$kbNote
            : 'Gửi lại cho AI '.count($feedback).' lỗi chặn cần sửa: '.implode(' | ', array_map(fn ($f) => mb_substr($f, 0, 70), array_slice($feedback, 0, 3)))),
            ['words' => str_word_count(strip_tags($html)) ?: mb_strlen(strip_tags($html)), 'playbook' => $this->pbData($pb)] + $kbData + $this->providerData($r), $r['model'] ?? null, (int) ($r['tokens_used'] ?? 0), (float) ($r['cost_vnd'] ?? 0));

        return [
            'article_id' => $article->id, 'title' => $r['title'] ?? $article->primary_keyword, 'slug' => $r['slug'] ?? 'bai-viet', 'outline' => $outline,
            'html_content' => $html, 'seo_title' => $r['seo_title'] ?? null, 'meta_description' => $r['meta_description'] ?? null,
            'focus_keyword' => $r['focus_keyword'] ?? $article->primary_keyword, 'faq_schema' => $r['faq_schema'] ?? [], 'sources_cited' => $r['sources_cited'] ?? [],
            'ai_provider' => $r['model'] ?? 'ai-engine', 'ai_model' => $r['model'] ?? 'unknown', 'prompt_version' => $r['prompt_version'] ?? 'v1.0',
            'tokens_used' => (int) ($r['tokens_used'] ?? 0), 'cost_vnd' => (float) ($r['cost_vnd'] ?? 0),
        ];
    }

    private function grade(AgentRun $run, Article $article, array $attrs, string $title, int $round, ?Playbook $editorPb = null, string $note = ''): QualityReport
    {
        $report = $this->checker->check(new ArticleVersion($attrs), $article);
        $c = $report->counts();
        $failed = array_map(fn ($r) => ['code' => $r->code, 'severity' => $r->severity, 'message' => $r->message], array_slice($report->failed(), 0, 8));
        $block = $report->blockingCodes();
        $score = RunScorer::score($report);
        $this->step($run, 'grade', 'editor', $block === [] ? 'ok' : 'warn', $title,
            "Điểm {$score}/100 (tham khảo, mốc ".RunScorer::REFERENCE_THRESHOLD.'). '.($block === [] ? "Đạt mọi luật bắt buộc (còn {$c['P1']} mục P1, {$c['P2']} mục P2 chỉ nên xem)." : 'Trượt luật bắt buộc: '.implode(', ', $block).'. Quyết định do luật, không do AI.').($note !== '' ? ' '.$note : ''),
            ['blocking' => $block, 'counts' => $c, 'score' => $score, 'round' => $round, 'threshold' => RunScorer::REFERENCE_THRESHOLD, 'penalty' => $this->penalty($report), 'failed' => $failed, 'playbook' => $this->pbData($editorPb)]);

        return $report;
    }

    private function persist(Article $article, array $attrs, bool $passed): ArticleVersion
    {
        return DB::transaction(function () use ($article, $attrs, $passed) {
            $next = ($article->versions()->max('version') ?? 0) + 1;

            return ArticleVersion::create($attrs + ['version' => $next, 'review_status' => $passed ? 'needs_review' : 'drafting']);
        });
    }

    /**
     * Nạp playbook của một vai trò. Chạy thủ công được dùng bản nháp (người đang xem); chạy tự động (lịch) CHỈ dùng bản active.
     *
     * AG-S14: ánh xạ bước → playbook khai báo ở RoleRegistry::STEP_PLAYBOOK; thiếu/hỏng/chưa active (lượt lịch) thì dùng chỉ dẫn mặc định và ghi lý do.
     *
     * @return array{0: ?Playbook, 1: string} playbook (hoặc null) và ghi chú giải thích
     */
    private function usePlaybook(string $role, string $key): array
    {
        try {
            $pb = $this->playbooks->find($role, $key);
        } catch (InvalidPlaybook $e) {
            return [null, 'Playbook lỗi định dạng nên bỏ qua, dùng chỉ dẫn mặc định.'];
        }
        if ($pb === null) {
            return [null, 'Chưa có playbook cho vai trò này, dùng chỉ dẫn mặc định.'];
        }
        if (! $pb->isRunnable() && $this->trigger === 'schedule') {
            return [null, "Playbook {$pb->ref()} chưa qua bộ mẫu (đang {$pb->status}) nên lượt tự động không dùng, dùng chỉ dẫn mặc định."];
        }

        return [$pb, "Dùng playbook {$pb->ref()}".($pb->isRunnable() ? '.' : ' (bản nháp, chỉ cho lượt thủ công).')];
    }

    private function roleOff(string $key): bool
    {
        return in_array($key, $this->disabledRoles, true);
    }

    /** Tùy chọn vai trò truyền cho adapter AI: khóa + tên tiếng Việt, để prompt có dòng "Bạn đang đóng vai". */
    private function roleOptions(string $key): array
    {
        return ['role' => $key, 'role_name' => $this->roles->roleName($key)];
    }

    /** @return array<string, string>|null */
    private function pbData(?Playbook $pb): ?array
    {
        return $pb ? ['ref' => $pb->ref(), 'status' => $pb->status, 'checksum' => substr($pb->checksum, 0, 12), 'source' => $pb->lineage['source'] ?? ''] : null;
    }

    /**
     * AG-S11: gọi AI cho một loại việc. Có router: dùng model chính của route; hết lượt thử vì lỗi tạm thời (hoặc phản hồi sai định dạng)
     * thì chuyển sang model dự phòng THẬT và ghi bước 'failover'; vượt trần chi phí/lần thì ghi quyết định rồi dừng.
     * Không có router: một nhà cung cấp như cũ.
     *
     * @param \Closure(AiProviderInterface): array $call
     */
    private function callAi(AgentRun $run, string $task, string $what, \Closure $call): array
    {
        if ($this->router === null) {
            return $this->withRetry($run, $what, fn () => $call($this->ai));
        }
        $primary = $this->router->target($task) ?? throw new AiRouteUnavailable($task, (string) $this->router->why($task));
        $used = $primary;
        try {
            $r = $this->withRetry($run, $what, fn () => $call($primary->ai));
        } catch (AiRetriesExhausted|AiSchemaError $e) {
            $fb = $this->router->fallback($task);
            if ($fb === null) {
                throw $e;
            }
            $prev = $e->getPrevious() ?? $e;
            $why = $e instanceof AiRetriesExhausted
                ? 'tạm thời ('.($prev instanceof TransientAiError ? ErrorMasker::short($prev->getMessage(), 100) : TransientAiError::describe($prev)).')'
                : 'định dạng ('.ErrorMasker::short($e->getMessage(), 100).')';
            $this->step($run, 'failover', 'system', 'warn', "Chuyển model dự phòng: {$what}",
                "Đã chuyển từ {$primary->label()} sang {$fb->label()} vì lỗi {$why}. Dự phòng là model thật, không phải AI giả lập.",
                ['task' => $task, 'from' => ['provider' => $primary->providerName, 'model' => $primary->model], 'to' => ['provider' => $fb->providerName, 'model' => $fb->model], 'reason' => $why], $fb->model);
            $used = $fb;
            $r = $this->withRetry($run, $what.' (dự phòng)', fn () => $call($fb->ai));
        }
        $r['_provider_id'] = $used->providerId;
        $cost = (float) ($r['cost_vnd'] ?? 0);
        if ($used->costCapVnd !== null && $used->costCapVnd > 0 && $cost > $used->costCapVnd) {
            $e = new CostCapExceeded($task, $cost, $used->costCapVnd);
            $this->step($run, 'decision', 'system', 'warn', "Dừng vì vượt trần chi phí: {$what}", $e->getMessage().' Kết quả của lần gọi này bị bỏ; chi phí đã phát sinh vẫn được tính vào phiên.',
                ['decision' => 'cost_cap_exceeded', 'task' => $task, 'cost_vnd' => $cost, 'cap_vnd' => $used->costCapVnd] + $this->providerData($r), $used->model, (int) ($r['tokens_used'] ?? 0), $cost);
            throw $e;
        }

        return $r;
    }

    /** @return array{provider_id?: int} nhãn nhà cung cấp để thống kê chi tiêu theo provider */
    private function providerData(array $r): array
    {
        return isset($r['_provider_id']) ? ['provider_id' => $r['_provider_id']] : [];
    }

    /**
     * Gọi nhà cung cấp AI; lỗi TẠM THỜI được thử lại tối đa policy->maxRetries lần với backoff, mỗi lần ghi một bước 'retry'.
     * Lỗi không tạm thời ném thẳng ra (phiên 'failed'). Hết lần thì ném AiRetriesExhausted; không bao giờ thay bằng Mock.
     *
     * @template T
     * @param \Closure(): T $call
     * @return T
     */
    private function withRetry(AgentRun $run, string $what, \Closure $call): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $call();
            } catch (Throwable $e) {
                if (! TransientAiError::is($e)) {
                    throw $e;
                }
                $attempt++;
                if ($attempt > $this->policy->maxRetries) {
                    throw new AiRetriesExhausted($what, $attempt, $e);
                }
                $delays = $this->policy->backoffSeconds;
                $wait = (int) ($delays[$attempt - 1] ?? ($delays === [] ? 1 : end($delays)));
                $this->step($run, 'retry', 'system', 'warn', "Thử lại lần {$attempt}/{$this->policy->maxRetries}: {$what}",
                    'Nhà cung cấp AI lỗi tạm thời ('.TransientAiError::describe($e).'; '.ErrorMasker::short($e->getMessage(), 120)."). Chờ {$wait} giây rồi thử lại, không đổi sang AI giả lập.",
                    ['attempt' => $attempt, 'max' => $this->policy->maxRetries, 'wait_seconds' => $wait, 'error' => TransientAiError::describe($e)]);
                ($this->sleep)($wait);
            }
        }
    }

    /** Ghi một quyết định hệ thống tự đưa ra kèm dữ kiện trước/sau. */
    private function decision(AgentRun $run, string $code, string $title, string $detail, array $facts): void
    {
        $this->step($run, 'decision', 'system', 'ok', $title, $detail, ['decision' => $code] + $facts);
    }

    private function penalty(QualityReport $r): int
    {
        $c = $r->counts();

        return $c['P0'] * 10 + $c['P1'] * 3 + $c['P2'];
    }

    private function spent(AgentRun $run): float
    {
        return (float) $run->steps()->sum('cost_vnd');
    }

    private function step(AgentRun $run, string $stage, string $role, string $status, string $title, string $detail, array $data = [], ?string $model = null, int $tokens = 0, float $cost = 0.0): AgentRunStep
    {
        $now = now();
        // AG-S14: mọi bước ghi vai trò (khóa + tên) và kỹ năng; bước không dùng playbook ghi rõ "Chỉ dẫn mặc định" kèm lý do nếu có.
        [$pb, $skillNote] = $this->skills[$stage] ?? [null, ''];
        $data += [
            'role_key' => $role, 'role_name' => $this->roles->roleName($role),
            'skill' => $this->pbData($pb), 'skill_label' => $pb ? $pb->ref().($pb->isRunnable() ? '' : ' (bản nháp)') : 'Chỉ dẫn mặc định',
        ] + ($pb === null && $skillNote !== '' ? ['skill_note' => $skillNote] : []);

        return AgentRunStep::create([
            'agent_run_id' => $run->id, 'seq' => ++$this->seq, 'stage' => $stage, 'role' => $role, 'status' => $status, 'title' => $title,
            'detail' => $detail, 'data' => $data ?: null, 'model' => $model, 'tokens_used' => $tokens, 'cost_vnd' => $cost, 'started_at' => $now, 'finished_at' => $now,
        ]);
    }

    private function finish(AgentRun $run, string $status, ?string $reason): AgentRun
    {
        $run->update([
            'status' => $status, 'outcome_reason' => $reason, 'finished_at' => now(),
            'tokens_used' => (int) $run->steps()->sum('tokens_used'), 'cost_vnd' => $this->spent($run),
        ]);

        return $run->refresh();
    }

    private function levelLabel(string $level): string
    {
        return ['low' => 'thấp', 'medium' => 'vừa', 'high' => 'cao'][$level] ?? $level;
    }
}
