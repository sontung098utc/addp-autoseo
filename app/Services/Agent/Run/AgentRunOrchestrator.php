<?php

namespace App\Services\Agent\Run;

use App\Contracts\AiProviderInterface;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\User;
use App\Services\Agent\Risk\RiskClassifier;
use App\Services\Ai\MockAiProvider;
use App\Services\Ai\Playbook\InvalidPlaybook;
use App\Services\Ai\Playbook\Playbook;
use App\Services\Ai\Playbook\PlaybookLoader;
use App\Services\Content\HtmlSanitizer;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityReport;
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

    public function __construct(
        private AiProviderInterface $ai,
        private ContentQualityChecker $checker = new ContentQualityChecker(),
        private RiskClassifier $risk = new RiskClassifier(),
        private ?EmergencyStopService $stop = null,
        private RunPolicy $policy = new RunPolicy(),
        private ?PlaybookLoader $playbooks = null,
    ) {
        $this->stop ??= app(EmergencyStopService::class);
        $this->playbooks ??= new PlaybookLoader(resource_path('playbooks'));
    }

    private string $trigger = 'manual';

    public function run(Article $article, ?User $actor = null, string $trigger = 'manual', ?int $campaignId = null): AgentRun
    {
        $this->seq = 0;
        $this->trigger = $trigger;
        $run = AgentRun::create([
            'website_id' => $article->website_id, 'article_id' => $article->id, 'campaign_id' => $campaignId, 'triggered_by' => $actor?->id, 'trigger' => $trigger,
            'status' => 'running', 'trust_tier' => $this->policy->trustTier, 'ai_mode' => ($this->ai instanceof MockAiProvider || $this->ai instanceof \App\Services\Ai\DemoScriptedAiProvider) ? 'mock' : 'real',
            'started_at' => now(),
        ]);
        $previousStatus = $article->workflow_status;

        try {
            return $this->execute($run, $article, $previousStatus);
        } catch (Throwable $e) {
            $this->step($run, 'handoff', 'system', 'failed', 'Phiên chạy gặp lỗi', 'Lỗi không lường trước; bài được trả về trạng thái cũ, không có gì được đăng.', ['error' => class_basename($e)]);
            $article->update(['workflow_status' => $previousStatus]);

            return $this->finish($run, 'failed', 'Lỗi hệ thống: '.class_basename($e).'. Chưa có bản nháp nào được tạo.');
        }
    }

    private function execute(AgentRun $run, Article $article, string $previousStatus): AgentRun
    {
        $site = $article->website;

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

        $article->update(['workflow_status' => 'drafting']);

        // 3. Dàn ý.
        [$briefPb, $briefNote] = $this->usePlaybook('brief', 'content-brief');
        $outline = $this->ai->generateOutline($article, $briefPb ? ['playbook' => $briefPb->body, 'playbook_ref' => $briefPb->ref()] : []);
        $this->step($run, 'brief', 'strategist', 'ok', 'Lập dàn ý ('.count($outline['outline'] ?? []).' mục)', 'Dàn ý dựa trên từ khóa chính và ý định tìm kiếm của bài. '.$briefNote,
            ['outline' => $outline['outline'] ?? [], 'playbook' => $this->pbData($briefPb)], $outline['model'] ?? null, (int) ($outline['tokens_used'] ?? 0), (float) ($outline['cost_vnd'] ?? 0));
        [$editorPb, $editorNote] = $this->usePlaybook('editor', 'qa-review');

        // 4. Viết.
        $attrs = $this->write($run, $article, $outline['outline'] ?? [], [], 'write', 'Viết bản đầu', null);
        $report = $this->grade($run, $article, $attrs, 'Chấm bản đầu', $editorPb, $editorNote);
        $penalty = $this->penalty($report);

        // 5. Vòng sửa có giới hạn.
        $reason = null;
        $round = 0;
        while ($report->hasBlocking() && $round < $this->policy->maxRepairRounds) {
            if ($this->spent($run) >= $this->policy->budgetVnd) {
                $reason = 'Hết ngân sách cho phiên chạy ('.number_format($this->policy->budgetVnd, 0, ',', '.').' ₫) nên dừng sửa.';
                break;
            }
            $round++;
            $feedback = array_map(fn ($r) => "{$r->code}: {$r->message}", $report->blocking());
            $attrs = $this->write($run, $article, $outline['outline'] ?? [], $feedback, 'repair', "Sửa lần {$round}", $editorPb);
            $report = $this->grade($run, $article, $attrs, "Chấm lại sau lần sửa {$round}", $editorPb, '');
            $new = $this->penalty($report);
            if ($report->hasBlocking() && $new >= $penalty) {
                $reason = 'Sửa không cải thiện điểm (từ '.$penalty.' xuống '.$new.') nên dừng, tránh tốn thêm chi phí.';
                $penalty = $new;
                break;
            }
            $penalty = $new;
        }
        $run->update(['repair_rounds' => $round]);

        // 6. Bàn giao: chỉ tạo BẢN NHÁP. Không bao giờ xuất bản ở bậc T1.
        $passed = ! $report->hasBlocking();
        $version = $this->persist($article, $attrs, $passed);
        $run->update(['final_version_id' => $version->id]);

        if ($passed) {
            $article->update(['workflow_status' => 'needs_review']);
            $this->step($run, 'handoff', 'system', 'ok', 'Chuyển người duyệt', 'Đạt mọi luật bắt buộc. Bản nháp v'.$version->version.' đã vào hàng chờ duyệt; chưa xuất bản vì bậc tin cậy '.$this->policy->trustTier.'.', ['version' => $version->version]);

            return $this->finish($run, 'ready_for_review', $assess->level === 'high' ? 'Đạt luật nhưng chủ đề rủi ro cao: cần người có chuyên môn duyệt.' : null);
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
        if ($feedback !== [] && $editorPb) {
            $options['playbook'] = $editorPb->body;
            $options['playbook_ref'] = $editorPb->ref();
        }
        $r = $this->ai->generateArticle($article, $outline, $options);
        $html = HtmlSanitizer::clean($r['html_content'] ?? '');
        // Nhà cung cấp thật có thể rơi về bản giả lập một cách im lặng (thiếu khóa, JSON hỏng). Không được gọi đó là AI thật.
        $fellBack = str_starts_with((string) ($r['model'] ?? ''), 'mock');
        if ($fellBack && $run->ai_mode !== 'mock') {
            $run->update(['ai_mode' => 'mock']);
        }
        $silentMock = $fellBack && ! $this->ai instanceof MockAiProvider;
        $this->step($run, $stage, 'writer', $silentMock ? 'warn' : 'ok', $title, ($silentMock ? 'Nhà cung cấp AI đã rơi về bản giả lập (thiếu khóa hoặc phản hồi hỏng); nội dung không phải AI thật. ' : '').($feedback === []
            ? 'Viết theo dàn ý; HTML được làm sạch trước khi chấm.'
            : 'Gửi lại cho AI '.count($feedback).' lỗi chặn cần sửa: '.implode(' | ', array_map(fn ($f) => mb_substr($f, 0, 70), array_slice($feedback, 0, 3)))),
            ['words' => str_word_count(strip_tags($html)) ?: mb_strlen(strip_tags($html))], $r['model'] ?? null, (int) ($r['tokens_used'] ?? 0), (float) ($r['cost_vnd'] ?? 0));

        return [
            'article_id' => $article->id, 'title' => $r['title'] ?? $article->primary_keyword, 'slug' => $r['slug'] ?? 'bai-viet', 'outline' => $outline,
            'html_content' => $html, 'seo_title' => $r['seo_title'] ?? null, 'meta_description' => $r['meta_description'] ?? null,
            'focus_keyword' => $r['focus_keyword'] ?? $article->primary_keyword, 'faq_schema' => $r['faq_schema'] ?? [], 'sources_cited' => $r['sources_cited'] ?? [],
            'ai_provider' => $r['model'] ?? 'ai-engine', 'ai_model' => $r['model'] ?? 'unknown', 'prompt_version' => $r['prompt_version'] ?? 'v1.0',
            'tokens_used' => (int) ($r['tokens_used'] ?? 0), 'cost_vnd' => (float) ($r['cost_vnd'] ?? 0),
        ];
    }

    private function grade(AgentRun $run, Article $article, array $attrs, string $title, ?Playbook $editorPb = null, string $note = ''): QualityReport
    {
        $report = $this->checker->check(new ArticleVersion($attrs), $article);
        $c = $report->counts();
        $failed = array_map(fn ($r) => ['code' => $r->code, 'severity' => $r->severity, 'message' => $r->message], array_slice($report->failed(), 0, 8));
        $block = $report->blockingCodes();
        $this->step($run, 'grade', 'editor', $block === [] ? 'ok' : 'warn', $title,
            $block === [] ? "Đạt mọi luật bắt buộc (còn {$c['P1']} mục P1, {$c['P2']} mục P2 chỉ nên xem)." : 'Trượt luật bắt buộc: '.implode(', ', $block).'. Quyết định do luật, không do AI.'.($note !== '' ? ' '.$note : ''),
            ['blocking' => $block, 'counts' => $c, 'penalty' => $this->penalty($report), 'failed' => $failed, 'playbook' => $this->pbData($editorPb)]);

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

    /** @return array<string, string>|null */
    private function pbData(?Playbook $pb): ?array
    {
        return $pb ? ['ref' => $pb->ref(), 'status' => $pb->status, 'checksum' => substr($pb->checksum, 0, 12), 'source' => $pb->lineage['source'] ?? ''] : null;
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
