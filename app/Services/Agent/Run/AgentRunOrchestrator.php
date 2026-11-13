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
use App\Contracts\StructuredAiInterface;
use App\Services\Content\CitationRenderer;
use App\Services\Content\CitationSupportJudge;
use App\Services\Knowledge\AiJson;
use App\Services\Content\MedicalDisclaimer;
use App\Services\Content\HtmlSanitizer;
use App\Models\BrandProfile;
use App\Services\Brand\BrandProfileData;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\Quality\QualityReport;
use App\Services\Agent\Context\ContextAssets;
use App\Services\Brain\BrainContext;
use App\Services\Brain\BrainSourceRegistry;
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

    /** Mốc đồng hồ (giây, mili) lúc bước trước xong / phiên bắt đầu: thời lượng bước = hiện tại - mốc. */
    private float $markT = 0.0;

    /** Mili giây chờ AI (kể cả thử lại) cộng dồn kể từ bước trước. */
    private float $aiMs = 0.0;

    private ?KnowledgeContext $kb = null;

    private ?ContextAssets $assets = null; // AG-S25: ảnh thư viện + liên kết nội bộ

    /** Bước 'images' đang chạy song song với bước viết (kế hoạch ảnh đã lập) và tiến trình con của nó nếu có. */
    private ?AgentRunStep $imageStep = null;

    private mixed $imageProc = null;

    /** Kế hoạch ảnh lập cùng dàn ý (xem imagePlanFor). */
    private array $imageCtx = [];

    /** AG-B1: các Trí tuệ của bài đang chạy (theo chiến dịch hoặc website) và chiến dịch giao việc. */
    private ?BrainContext $brainCtx = null;

    private ?int $campaignId = null;

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
        /** Điểm nối tự chuẩn bị kiến thức: fn(Website, array $options): array (kết quả BrainBuildService::run). Null = dùng BrainBuildService thật. */
        private ?\Closure $knowledgeBuilder = null,
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

    /** @var list<mixed>|null dàn ý có sẵn do người gọi truyền vào (G4); null = tự lập dàn ý như cũ */
    private ?array $presetOutline = null;

    /** @var list<string>|null điểm dừng chờ người của chiến dịch ('outline' dừng sau dàn ý do AI lập) */
    private ?array $checkpoints = null;

    public const GAP_PAGES = 5;

    /** kho vẫn chưa có mục đã duyệt khớp sau khi tự chuẩn bị, chủ đề rủi ro cao: bài cần chuyên gia xem lại sau khi viết */
    private bool $needsExpert = false;

    public function run(Article $article, ?User $actor = null, string $trigger = 'manual', ?int $campaignId = null, ?array $presetOutline = null, bool $allowWithoutKnowledge = false /* deprecated: không còn tác dụng, không có cổng dừng */, ?array $checkpoints = null): AgentRun
    {
        $this->checkpoints = $checkpoints;
        $this->needsExpert = false;
        $this->presetOutline = $presetOutline === [] ? null : $presetOutline;
        $this->seq = 0;
        $this->markT = microtime(true);
        $this->aiMs = 0.0;
        $this->trigger = $trigger;
        $this->campaignId = $campaignId;
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
        } catch (\App\Services\Ai\AiQuotaExceeded $e) {
            $this->step($run, 'handoff', 'system', 'failed', 'Hết hạn mức ngày của nhà cung cấp AI', $e->getMessage().' Không thử lại vì chờ vài giây không giúp ích; không thay bằng AI giả lập, bài được trả về trạng thái cũ.', ['error' => 'AiQuotaExceeded']);
            $article->update(['workflow_status' => $previousStatus]);

            return $this->finish($run, 'failed', 'Hết hạn mức theo ngày của nhà cung cấp AI cho model này. Chưa có bản nháp nào được tạo; đổi model, chờ hạn mức đặt lại hoặc bật thanh toán.');
        } catch (AiProviderError $e) {
            $this->step($run, 'handoff', 'system', 'failed', 'Nhà cung cấp AI trả lỗi', ErrorMasker::short($e->getMessage(), 200).' Lỗi này thử lại không giúp ích nên phiên dừng; không thay bằng AI giả lập.', ['error' => class_basename($e)]);
            $article->update(['workflow_status' => $previousStatus]);

            return $this->finish($run, 'failed', 'Nhà cung cấp AI từ chối hoặc trả sai định dạng: '.ErrorMasker::short($e->getMessage(), 160));
        } catch (Throwable $e) {
            $this->logUnexpected($e, $run, $article);
            $this->step($run, 'handoff', 'system', 'failed', 'Phiên chạy gặp lỗi', 'Lỗi không lường trước; bài được trả về trạng thái cũ, không có gì được đăng.', ['error' => class_basename($e)]);
            $article->update(['workflow_status' => $previousStatus]);

            return $this->finish($run, 'failed', 'Lỗi hệ thống: '.class_basename($e).'. Chưa có bản nháp nào được tạo.');
        }
    }


    /**
     * Ghi log lỗi không lường trước (trước đây bị nuốt, rất khó chẩn đoán, vd cột thiếu trong DB). Có ngữ cảnh phiên/bài; KHÔNG ghi nội dung bài,
     * tham số SQL hay khóa: với lỗi SQL chỉ lấy thông điệp gốc của driver (không kèm câu SQL đầy đủ và giá trị bind), đã che bằng ErrorMasker.
     */
    private function logUnexpected(Throwable $e, AgentRun $run, Article $article): void
    {
        $msg = $e instanceof \Illuminate\Database\QueryException ? (string) ($e->getPrevious()?->getMessage() ?: 'lỗi truy vấn') : $e->getMessage();
        try {
            \Illuminate\Support\Facades\Log::error('Phiên chạy đội AI gặp lỗi không lường trước', [
                'run_id' => $run->id, 'article_id' => $article->id, 'stage_seq' => $this->seq, 'exception' => get_class($e),
                'message' => ErrorMasker::short($msg, 300), 'where' => basename($e->getFile()).':'.$e->getLine(),
            ]);
        } catch (Throwable) {
            // ghi log lỗi thì không được làm hỏng việc đóng phiên
        }
    }

    /**
     * "AI tự chuẩn bị kiến thức" (thay cổng chặn cũ): rủi ro cao mà kho chưa có mục đã duyệt khớp thì đọc tối đa GAP_PAGES trang của website
     * (trần chi phí ≤ 10% ngân sách phiên), rồi viết tiếp. Không dừng bao giờ; còn trống thì gắn cờ cần chuyên gia xem lại SAU khi viết.
     */
    private function prepareKnowledge(AgentRun $run, Article $article, \App\Services\Agent\Risk\RiskAssessment $assess): void
    {
        if ($assess->level !== 'high') {
            return;
        }
        try {
            if (\App\Services\Agent\Readiness\ArticleReadiness::for($article, $this->campaignId)['knowledge']['count'] > 0) {
                return;
            }
        } catch (Throwable $e) {
            report($e);

            return;
        }
        $site = $article->website;
        $data = ['reason' => 'high_risk_no_knowledge', 'risk_level' => $assess->level, 'purpose' => 'gap'];
        $webAv = (new \App\Services\Search\WebSearchResolver)->availability();
        $noWeb = $webAv['ok'] ? '' : ' Chưa cấu hình tìm kiếm web ('.$webAv['reason'].')';
        $spent = 0.0;
        if (trim((string) $site->base_url) === '') {
            $this->step($run, 'research', 'system', 'warn', 'Chưa tự đọc được website để bổ sung kiến thức', 'Chủ đề rủi ro cao, kho chưa có kiến thức khớp, nhưng website chưa có địa chỉ để đọc. Vẫn viết; bài sẽ được gắn nhãn cần chuyên gia xem lại.'.$noWeb, $data);
        } else {
            $opts = ['max_pages' => self::GAP_PAGES, 'purpose' => 'gap', 'as_system' => true, 'keyword' => (string) $article->primary_keyword, 'budget_cap_vnd' => $this->policy->budgetVnd * 0.1];
            try {
                $r = $this->knowledgeBuilder
                    ? ($this->knowledgeBuilder)($site, $opts)
                    : (new \App\Services\Knowledge\Build\BrainBuildService)->run($site, null, $opts);
                $this->step($run, 'research', 'system', ($r['pages_read'] ?? 0) > 0 ? 'ok' : 'warn', 'Tự đọc website để bổ sung kiến thức',
                    'Chủ đề rủi ro cao, kho chưa có kiến thức khớp nên AI tự đọc '.(int) ($r['pages_read'] ?? 0).'/'.(int) ($r['pages_planned'] ?? 0).' trang trên website công ty: '
                    .(int) ($r['items_auto'] ?? 0).' mục tự nhận (tầng A, hoàn tác được), '.(int) ($r['items_proposed'] ?? 0).' mục chờ người duyệt.',
                    $data + ['pages_read' => (int) ($r['pages_read'] ?? 0), 'items_auto' => (int) ($r['items_auto'] ?? 0), 'items_proposed' => (int) ($r['items_proposed'] ?? 0), 'build_run_id' => $r['run_id'] ?? null],
                    null, 0, (float) ($r['cost_vnd'] ?? 0));
                $spent = (float) ($r['cost_vnd'] ?? 0);
            } catch (Throwable $e) {
                $this->step($run, 'research', 'system', 'warn', 'Chưa tự đọc được website để bổ sung kiến thức', ErrorMasker::short($e->getMessage(), 160).' Vẫn viết; bài sẽ được gắn nhãn cần chuyên gia xem lại.'.$noWeb, $data);
            }
        }
        $this->prepareFromWeb($run, $article, $webAv['ok'], $spent);
        try {
            $left = \App\Services\Agent\Readiness\ArticleReadiness::for($article, $this->campaignId)['knowledge']['count'];
        } catch (Throwable) {
            $left = 0;
        }
        $this->needsExpert = $left === 0;
    }

    /**
     * Sau khi đọc website mà kho vẫn chưa có mục đã duyệt khớp: tìm nguồn trên web (Tavily), nạp tối đa 3 nguồn tin cậy.
     * Tổng chi phí chuẩn bị kiến thức ≤ 15% ngân sách phiên. Lỗi mạng hay thiếu khóa không làm hỏng phiên.
     */
    private function prepareFromWeb(AgentRun $run, Article $article, bool $available, float $spent): void
    {
        if (! $available) {
            return;
        }
        try {
            if (\App\Services\Agent\Readiness\ArticleReadiness::for($article, $this->campaignId)['knowledge']['count'] > 0) {
                return;
            }
        } catch (Throwable $e) {
            report($e);

            return;
        }
        $data = ['reason' => 'high_risk_no_knowledge', 'purpose' => 'web_discover'];
        $cap = $this->policy->budgetVnd * 0.15 - $spent;
        if ($cap <= 0) {
            $this->step($run, 'research', 'system', 'warn', 'Chưa tìm thêm nguồn trên web', 'Đã dùng hết phần ngân sách dành cho chuẩn bị kiến thức (15% ngân sách phiên). Vẫn viết; bài sẽ được gắn nhãn cần chuyên gia xem lại.', $data);

            return;
        }
        try {
            $svc = app(\App\Services\Knowledge\Discover\SourceDiscoveryService::class);
            $d = $svc->discover($article->website, (string) $article->primary_keyword);
            $trusted = array_values(array_filter($d['candidates'], fn ($c) => $c['trust']['level'] === 'trusted'));
            $imp = $svc->import($article->website, $trusted, null, ['as_system' => true, 'max_sources' => 3, 'only_trusted' => true, 'field' => $d['field'], 'budget_cap_vnd' => $cap, 'purpose' => 'gap',
                'search' => ['query' => $d['query'], 'results' => count($d['candidates']), 'cost_vnd' => $d['cost_vnd'], 'provider' => $d['provider']]]);
            $domains = implode(', ', array_map(fn ($p) => $p['domain'], array_filter($imp['pages'], fn ($p) => $p['ok'])));
            $this->step($run, 'research', 'system', $imp['imported'] > 0 ? 'ok' : 'warn', 'Tự tìm nguồn trên web để bổ sung kiến thức',
                'Đã tìm '.count($d['candidates']).' nguồn ('.count($trusted).' tin cậy), nạp '.$imp['imported'].' nguồn vào kho: '.$imp['items_auto'].' mục tự nhận (nguồn tin cậy, có đoạn nguyên văn, hoàn tác được), '.$imp['items_proposed'].' mục chờ duyệt'.($domains !== '' ? ' — '.$domains : '').'.'.($d['demo'] ? ' (Tìm kiếm mô phỏng, môi trường local.)' : ''),
                $data + ['query' => $d['query'], 'candidates' => count($d['candidates']), 'trusted' => count($trusted), 'imported' => $imp['imported'], 'items_auto' => $imp['items_auto'], 'items_proposed' => $imp['items_proposed'], 'build_run_id' => $imp['run_id'],
                    'web_sources' => array_values(array_map(fn ($p) => ['domain' => $p['domain'], 'url' => $p['url'], 'title' => $p['title'] ?? '', 'fetched_at' => $p['fetched_at'] ?? null, 'trust' => $p['trust']], array_filter($imp['pages'], fn ($p) => $p['ok'])))],
                null, 0, (float) $imp['cost_vnd']); // cost_vnd của import đã gồm chi phí tìm
        } catch (Throwable $e) {
            $this->step($run, 'research', 'system', 'warn', 'Chưa tìm được nguồn trên web', ErrorMasker::short($e->getMessage(), 160).' Vẫn viết; bài sẽ được gắn nhãn cần chuyên gia xem lại.', $data);
        }
    }

    private function execute(AgentRun $run, Article $article, string $previousStatus): AgentRun
    {
        $site = $article->website;
        $attached = [];
        if ($site && $site->status === 'active') {
            try {
                $attached = \App\Services\Agent\Readiness\BrainAutoAttach::ensureFor($article, $run->triggered_by ? User::find($run->triggered_by) : null);
            } catch (Throwable $e) {
                report($e); // tự gắn Trí tuệ lỗi thì chạy tiếp như cũ
            }
        }
        $this->brandProfile = BrandProfileData::forWebsite($article->website_id);
        $this->brainCtx = BrainContext::forArticle($article, $this->campaignId); // AG-B1
        $this->kb = (new KnowledgeContext())->scopeToBrains($this->writerBrainScope($this->brainCtx->ids())); // AG-S20: mỗi lượt chạy truy xuất kiến thức riêng; AG-B1: trong phạm vi Trí tuệ
        $this->assets = new ContextAssets();

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
        if ($attached !== []) {
            $this->step($run, 'guard', 'system', 'ok', 'Đã tự gắn Trí tuệ cho website', implode(' ', array_map(fn ($b) => 'Đã tự gắn Trí tuệ «'.$b['name'].'» ('.lcfirst($b['reason']).'). Có thể gỡ lại ở trang Trí tuệ.', $attached)), ['auto_attached' => $attached]);
        }

        // 2. Phân hạng rủi ro theo từ khóa (luật, không dùng AI).
        $assess = $this->risk->assess($article->primary_keyword);
        // AG-B1: Trí tuệ có thể NÂNG hạng (must_cite: tối thiểu vừa; hoặc theo risk_class), không bao giờ hạ hạng đã tính.
        $raised = $this->brainCtx->raise($assess->level);
        $brainRaise = $raised !== $assess->level ? ' Hạng được nâng từ '.$this->levelLabel($assess->level).' lên '.$this->levelLabel($raised).' theo cấu hình của Trí tuệ ('.$this->brainCtx->names().').' : '';
        if ($raised !== $assess->level) {
            $assess = new \App\Services\Agent\Risk\RiskAssessment($raised, $assess->reasons, $assess->sensitiveClaims);
        }
        $run->update(['risk_level' => $assess->level]);
        $this->step($run, 'risk', 'editor', $assess->level === 'high' ? 'warn' : 'ok', 'Hạng rủi ro: '.$this->levelLabel($assess->level),
            ($assess->reasons === [] && $brainRaise === '' ? 'Chủ đề thường, không chạm nhóm nhạy cảm.' : implode(' ', $assess->reasons).$brainRaise).($assess->level === 'high' ? ' Bài cần người có chuyên môn duyệt.' : ''),
            ['level' => $assess->level] + ($brainRaise !== '' ? ['raised_by_brain' => true] : []) + $this->brainCtx->stepData());

        // 2b. AI tự chuẩn bị kiến thức (không có cổng dừng).
        $this->prepareKnowledge($run, $article, $assess);

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
        $this->skills['layout'] = $this->usePlaybook('writer', 'article-layout'); // quy tắc chọn khối bố cục, nạp kèm vào prompt viết bài
        if ($this->presetOutline !== null) {
            $outline = ['outline' => $this->presetOutline];
            $this->skills['brief'] = [null, 'Dàn ý có sẵn.'];
            $this->imagePlanFor($article, $this->presetOutline);
            $this->step($run, 'brief', 'strategist', 'skipped', 'Dùng dàn ý có sẵn', 'Dàn ý do người gọi truyền vào ('.count($this->presetOutline).' mục) nên không gọi AI lập dàn ý.', ['outline' => $this->presetOutline, 'preset' => true] + $this->imagePlanData());
        } elseif ($this->roleOff('strategist')) {
            $outline = ['outline' => []];
            $this->skills['brief'] = [null, 'Vai trò Chiến lược đang tắt.'];
            $this->imagePlanFor($article, []);
            $this->step($run, 'brief', 'strategist', 'skipped', 'Bỏ qua bước dàn ý', 'Vai trò Chiến lược đang bị tắt trên tab Vai trò nên không lập dàn ý riêng; người viết tự bố cục theo từ khóa. Cổng an toàn (chấm luật) vẫn chạy đủ.', ['outline' => []] + $this->imagePlanData());
        } else {
            $this->skills['brief'] = $this->usePlaybook('brief', 'content-brief');
            [$briefPb, $briefNote] = $this->skills['brief'];
            $briefOptions = ($briefPb ? ['playbook' => $briefPb->body, 'playbook_ref' => $briefPb->ref()] : []) + $this->guidanceOptions($article) + $this->roleOptions('strategist') + $this->brandOptions() + $this->brainCtx->options($article);
            $outline = $this->callAi($run, 'outline', 'lập dàn ý', fn (AiProviderInterface $ai) => $ai->generateOutline($article, $briefOptions));
            $offTopic = BrandProfileData::looksOffTopic($this->brandProfile, (string) $article->primary_keyword);
            $this->imagePlanFor($article, $outline['outline'] ?? []);
            $this->step($run, 'brief', 'strategist', $offTopic ? 'warn' : 'ok', 'Lập dàn ý ('.count($outline['outline'] ?? []).' mục)', 'Dàn ý dựa trên từ khóa chính và ý định tìm kiếm của bài. '.($this->guidanceOptions($article) !== [] ? 'Đã áp dụng chỉ dẫn thêm của người phụ trách bài. ' : '').$briefNote.$this->brandNote().$this->brainCtx->note()
                .($offTopic ? ' Cảnh báo: chủ đề "'.mb_substr((string) $article->primary_keyword, 0, 80).'" có thể không phù hợp website (lĩnh vực trong hồ sơ: '.mb_substr((string) $this->brandProfile?->business_field, 0, 120).'). Chỉ cảnh báo, không chặn.' : ''),
                ['outline' => $outline['outline'] ?? [], 'playbook' => $this->pbData($briefPb)] + $this->imagePlanData() + $this->brandData() + $this->brainCtx->stepData() + ($offTopic ? ['off_topic' => true] : []) + $this->providerData($outline), $outline['model'] ?? null, (int) ($outline['tokens_used'] ?? 0), (float) ($outline['cost_vnd'] ?? 0));
            if (in_array('outline', (array) $this->checkpoints, true)) {
                return $this->holdAtOutline($run, $article, $outline['outline'] ?? []);
            }
        }
        // Dàn ý xong (và không dừng chờ người): bắt đầu tạo ảnh theo kế hoạch ảnh trong dàn ý, song song với viết.
        $this->startImages($run, $article);

        $this->skills['grade'] = $this->skills['repair'] = $this->usePlaybook('editor', 'qa-review');
        [$editorPb, $editorNote] = $this->skills['grade'];

        // 4. Nghiên cứu: nạp ngữ cảnh bằng luật (không gọi AI), rồi kiểm ngân sách trước khi viết.
        $this->research($run, $article, $outline['outline'] ?? []);
        if ($this->spent($run) >= $this->policy->budgetVnd) {
            $reason = 'Hết ngân sách cho phiên chạy ('.number_format($this->policy->budgetVnd, 0, ',', '.').' ₫) trước bước viết nên không gọi AI viết.';
            $this->step($run, 'handoff', 'system', 'warn', 'Dừng trước bước viết vì hết ngân sách', 'Đã chi '.number_format($this->spent($run), 0, ',', '.').' ₫ trên ngân sách '.number_format($this->policy->budgetVnd, 0, ',', '.').' ₫. Chưa có bản nháp nào; bài được trả về trạng thái cũ.', ['spent_vnd' => $this->spent($run), 'budget_vnd' => $this->policy->budgetVnd]);
            $this->decision($run, 'budget_exhausted_before_write', 'Dừng trước bước viết vì hết ngân sách', $reason, ['spent_vnd' => $this->spent($run), 'budget_vnd' => $this->policy->budgetVnd]);
            $article->update(['workflow_status' => $previousStatus]);

            return $this->finish($run, 'blocked', $reason);
        }

        // 5. Viết.
        $attrs = $this->write($run, $article, $outline['outline'] ?? [], [], 'write', 'Viết bản đầu', null);
        $report = $this->grade($run, $article, $attrs, 'Chấm bản đầu', 0, $editorPb, $editorNote);
        $penalty = $this->penalty($report);
        $score = RunScorer::score($report);

        // 6. Vòng sửa có giới hạn.
        $reason = null;
        $round = 0;
        // Vòng sửa chạy khi còn lỗi CHẶN, hoặc (Trí tuệ bắt buộc nguồn) còn đoạn khẳng định chưa có dấu trích dẫn [KB#id] (CITE01, P1): lỗi sau không chặn bàn giao nhưng được sửa.
        while (($report->hasBlocking() || $report->citationGaps() !== [] || $report->layoutGaps() !== [] || $report->supportGaps() !== []) && $round < $this->policy->maxRepairRounds) {
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
            foreach ($report->citationGaps() as $excerpt) {
                $feedback[] = "CITE01: đoạn «{$excerpt}» có khẳng định nhưng chưa có dấu [KB#id]; thêm dấu của mục kho đã cung cấp hỗ trợ khẳng định đó, hoặc bỏ/nêu chung chung nếu kho không có căn cứ (không bịa mã).";
            }
            foreach (array_slice($report->supportGaps(), 0, 6) as $gap) {
                $feedback[] = 'CITE02: '.$gap.' (chỉ trích mục thật sự nói điều đó, hoặc bỏ/nêu chung chung; không bịa mã.)';
            }
            foreach (array_slice($report->layoutGaps(), 0, 6) as $gap) {
                $feedback[] = 'LAY01: '.$gap;
            }
            $attrs = $this->write($run, $article, $outline['outline'] ?? [], $feedback, 'repair', "Sửa lần {$round}", $editorPb);
            $report = $this->grade($run, $article, $attrs, "Chấm lại sau lần sửa {$round}", $round, $editorPb, '');
            $new = $this->penalty($report);
            $newScore = RunScorer::score($report);
            if (($report->hasBlocking() || $report->citationGaps() !== [] || $report->layoutGaps() !== [] || $report->supportGaps() !== []) && $new >= $penalty) {
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

        // 7. Bàn giao: chỉ tạo BẢN NHÁP. Không bao giờ xuất bản ở bậc T1.
        $passed = ! $report->hasBlocking();
        // Dấu [KB#id] chỉ để máy kiểm tra: bản lưu cho người đọc dùng chú thích số + khối Nguồn tham khảo (vẫn mang data-kb để chấm lại được).
        if (isset($attrs['html_content']) && is_string($attrs['html_content'])) {
            $attrs['html_content'] = CitationRenderer::render($attrs['html_content']);
        }
        $version = $this->persist($article, $attrs, $passed);
        $run->update(['final_version_id' => $version->id]);
        $this->kb?->recordUsage($version);
        $this->assets?->attachFeatured($version); // AG-S25
        // 6b. Lắp ráp: đợi ảnh (chạy song song với bước viết), gắn vào phiên bản và chèn vào nội dung theo kế hoạch.
        $this->assembleImages($run, $article, $version);

        if ($passed && $assess->level === 'high') {
            $this->decision($run, 'high_risk_expert_review', 'Giữ cho người có chuyên môn duyệt', 'Đạt luật nhưng chủ đề rủi ro cao nên hệ thống không coi là xong; cần người có chuyên môn duyệt trước khi dùng.'.($this->needsExpert ? ' Kho chưa có kiến thức đã duyệt khớp chủ đề nên cần xem kỹ các khẳng định.' : ''), ['risk_level' => 'high', 'score' => $score] + ($this->needsExpert ? ['needs_expert' => true, 'reason' => 'high_risk_no_knowledge'] : []));
        }
        $brainMustCite = $this->brainCtx->reliance() === 'must_cite';
        if ($passed && $brainMustCite && $assess->level !== 'high') {
            $this->decision($run, 'brain_must_cite_review', 'Giữ cho người có chuyên môn duyệt', 'Đạt luật nhưng Trí tuệ ('.$this->brainCtx->names().') đặt mức dựa vào AI là Bắt buộc nguồn nên hệ thống không coi là xong; cần người có chuyên môn duyệt. Việc kiểm từng khẳng định có nguồn chưa được tự động hóa ở bản này.', ['ai_reliance' => 'must_cite', 'score' => $score]);
        }
        if ($passed) {
            $article->update(['workflow_status' => 'needs_review']);
            $push = $this->handoff?->push($run, $version, $site, true, $assess->level);
            $pushed = $push?->status === 'pushed';
            $pushFailed = $push?->status === 'failed';
            $extra = $pushed ? " Đã đẩy nháp lên {$push->cms}".($push->url ? ": link xem trước {$push->url}" : '').' (không công khai).' : ($push?->note ? ' '.$push->note : '');
            $this->step($run, 'handoff', 'system', $pushFailed ? 'warn' : 'ok', 'Chuyển người duyệt', 'Đạt mọi luật bắt buộc. Bản nháp v'.$version->version.' đã vào hàng chờ duyệt; chưa xuất bản vì bậc tin cậy '.$this->policy->trustTier.'.'.$extra,
                ['version' => $version->version] + ($push && $push->status !== 'skipped' ? ['cms_draft' => ['status' => $push->status, 'cms' => $push->cms, 'url' => $push->url, 'remote_id' => $push->remoteId]] : []));

            $reasonOut = $assess->level === 'high' ? 'Đạt luật nhưng chủ đề rủi ro cao: cần người có chuyên môn duyệt.' : ($brainMustCite ? 'Đạt luật nhưng lĩnh vực bắt buộc nguồn: cần người có chuyên môn duyệt.' : null);
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

    /**
     * Kế hoạch ảnh là MỘT PHẦN của dàn ý (không phải bước riêng): từ dàn ý xác định cần ảnh nào, đặt sau mục nào, mô tả ảnh ra sao (luật, 0 token).
     * Kết quả nằm trong dữ kiện của bước 'brief' (khóa image_plan) để ngăn chi tiết hiện cùng dàn ý; startImages() dùng nó để bắt đầu tạo ảnh.
     *
     * @return array{plan:?array, skip_code:?string, skipped_reason:?string, generator?:string}
     */
    private function imagePlanFor(Article $article, array $outline): array
    {
        $this->imageCtx = ['plan' => null, 'skip_code' => null, 'skipped_reason' => null];
        $skip = function (string $code, string $why): array {
            return $this->imageCtx = ['plan' => null, 'skip_code' => $code, 'skipped_reason' => $why];
        };
        try {
            if ($this->roleOff('designer')) {
                return $skip('role_off', 'Vai trò Họa viên hình ảnh đang bị tắt trên tab Vai trò nên bước tạo ảnh được bỏ qua; bài và các bước khác không đổi.');
            }
            if (! config('agent.images.enabled', true)) {
                return $skip('disabled', 'Tính năng tự tạo ảnh đang tắt.');
            }
            $svc = new \App\Services\Images\ArticleImageService;
            $brand = \App\Models\BrandProfile::query()->where('website_id', $article->website_id)->first();
            $health = $svc->isHealth($article, $brand);
            if (! config('agent.images.medical', false) && $health) {
                return $skip('medical', 'Lĩnh vực sức khỏe: không tự tạo ảnh.');
            }
            try {
                $resolved = \App\Services\Images\ImageGeneratorResolver::resolve();
            } catch (\App\Services\Images\ImageGeneratorUnavailable $e) {
                return $skip('no_provider', $e->getMessage());
            }
            $this->assets?->load($article, $outline); // truy xuất ảnh thư viện một lần (research() dùng lại): có ảnh khớp thì làm ảnh bìa, không tạo ảnh bìa AI
            $libraryCover = ($this->assets?->options()['images'] ?? []) !== [];
            $plan = \App\Services\Images\ImagePlanner::plan($article, $outline, ['health' => $health, 'brand' => $brand, 'library_cover' => $libraryCover]);
            if ($plan['status'] !== 'ok') {
                return $skip((string) $plan['skip_code'], (string) $plan['reason']);
            }

            return $this->imageCtx = ['plan' => $plan, 'skip_code' => null, 'skipped_reason' => null, 'generator' => $resolved['label'], 'resolved' => $resolved];
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Lập kế hoạch ảnh lỗi, bỏ qua: '.class_basename($e));

            return $skip('plan_error', 'Không lập được kế hoạch ảnh.');
        }
    }

    /** Dữ kiện kế hoạch ảnh để ghi vào bước 'brief' (không chứa đối tượng). */
    private function imagePlanData(): array
    {
        $c = $this->imageCtx;

        return ['image_plan' => ['plan' => $c['plan'] ?? null, 'skip_code' => $c['skip_code'] ?? null, 'skipped_reason' => $c['skipped_reason'] ?? null, 'generator' => $c['generator'] ?? null]];
    }

    /**
     * Sau dàn ý: bắt đầu tạo ảnh theo kế hoạch ảnh nằm trong dàn ý, SONG SONG với bước viết (tiến trình con khi config agent.images.parallel bật,
     * nếu không thì chạy ngay tại đây). Mọi lý do không tạo ảnh ghi ở bước 'images'; lỗi ở đây không làm hỏng phiên.
     */
    private function startImages(AgentRun $run, Article $article): void
    {
        $this->imageStep = null;
        $this->imageProc = null;
        $c = $this->imageCtx;
        try {
            if (($c['plan'] ?? null) === null) {
                if (($c['skip_code'] ?? null) !== null) {
                    $this->skills['images'] = [null, ($c['skip_code'] === 'role_off') ? 'Vai trò Họa viên đang tắt.' : ''];
                    $this->step($run, 'images', 'designer', 'skipped', 'Không tạo ảnh', (string) $c['skipped_reason'], ['skip_code' => $c['skip_code'], 'skipped_reason' => $c['skipped_reason'], 'generated' => 0, 'items' => [], 'cost' => 0.0, 'errors' => []]);
                }

                return;
            }
            $this->skills['images'] = [null, ''];
            $plan = $c['plan'];
            $n = count($plan['slots']);
            $cap = $this->policy->budgetVnd * (float) config('agent.images.budget_share', 0.10);
            $this->imageStep = $this->step($run, 'images', 'designer', 'ok', 'Đang tạo ảnh theo kế hoạch trong dàn ý', "Tạo {$n} ảnh bằng {$c['generator']}, trần chi phí ảnh ".number_format($cap, 0, ',', '.').' ₫.',
                ['plan' => $plan, 'article_id' => $article->id, 'cap_vnd' => $cap, 'generator' => $c['generator'], 'gen' => ['done' => false], 'parallel' => \App\Services\Images\ImageJob::parallelEnabled()]);
            if (\App\Services\Images\ImageJob::parallelEnabled() && ($this->imageProc = \App\Services\Images\ImageJob::spawn($this->imageStep)) !== null) {
                return;
            }
            \App\Services\Images\ImageJob::execute($this->imageStep, $c['resolved']['generator'] ?? null, $article);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Bắt đầu tạo ảnh lỗi, bỏ qua: '.class_basename($e));
        }
    }

    /** Đợi ảnh xong, lắp ráp vào phiên bản (ảnh bìa + ảnh trong bài), ghi kết quả vào bước 'images' và bước 'assemble'. Không làm hỏng phiên. */
    private function assembleImages(AgentRun $run, Article $article, ArticleVersion $version): void
    {
        if ($this->imageStep === null) {
            return;
        }
        $stepRow = $this->imageStep;
        $this->imageStep = null;
        try {
            if ($this->imageProc !== null) {
                try {
                    $this->imageProc->wait();
                } catch (Throwable) {
                    // tiến trình con quá hạn hoặc chết: xem kết quả ghi được tới lúc này
                }
                $this->imageProc = null;
            }
            $stepRow->refresh();
            $data = (array) $stepRow->data;
            $plan = (array) ($data['plan'] ?? []);
            $slots = (array) ($plan['slots'] ?? []);
            $gen = (array) ($data['gen'] ?? []);
            $done = (bool) ($gen['done'] ?? false);
            $items = (array) ($gen['items'] ?? []);
            $res = (new \App\Services\Images\ArticleImageService)->assemble($version, $slots, $items);
            $made = count(array_filter($items, fn ($i) => ($i['status'] ?? '') === 'ok'));
            $errors = (array) ($gen['errors'] ?? []);
            $okItems = [];
            foreach ($slots as $sl) {
                $it = $items[$sl['id']] ?? null;
                if (($it['status'] ?? '') === 'ok') {
                    $okItems[] = ['asset_id' => $it['asset_id'], 'role' => $sl['role'], 'prompt' => $it['prompt'] ?? $sl['prompt'], 'slot' => $sl['id']];
                }
            }
            $skipped = $made === 0 ? ($done ? ($errors !== [] ? 'Nhà cung cấp ảnh lỗi: '.$errors[0] : 'Không tạo được ảnh nào.') : 'Tạo ảnh chưa xong khi bài đã sẵn sàng; không chờ thêm.') : null;
            $status = $made === count($slots) && $done ? 'ok' : 'warn';
            $title = $made > 0 ? "Đã tạo {$made}/".count($slots).' ảnh minh họa (AI tạo)' : 'Không tạo ảnh';
            $stepRow->update([
                'status' => $made === 0 ? 'warn' : $status, 'title' => $title,
                'detail' => $made > 0 ? "Chi phí ảnh: ".(int) ($gen['cost_vnd'] ?? 0).'đ. Ảnh gắn vào phiên bản, nhãn "AI tạo" ghi ở thư viện media.'.($errors !== [] ? ' Có ảnh lỗi: '.$errors[0] : '') : (string) $skipped,
                'model' => $made > 0 ? (trim(explode(' (', (explode(' / ', (string) ($data['generator'] ?? ''), 2)[1] ?? ''))[0]) ?: 'image') : null,
                'data' => array_merge($data, ['version_id' => $version->id, 'generated' => $made, 'skipped_reason' => $skipped, 'skip_code' => $made === 0 ? ($done ? 'provider_error' : 'not_finished') : null,
                    'items' => $okItems, 'cost' => (float) ($gen['cost_vnd'] ?? 0), 'errors' => $errors, 'placements' => $res['placements']]),
            ]);
            $ins = count(array_filter($res['placements'], fn ($p) => $p['status'] === 'inserted'));
            $this->skills['assemble'] = [null, ''];
            $this->step($run, 'assemble', 'system', $made === 0 || collect($res['placements'])->contains(fn ($p) => in_array($p['status'], ['missing', 'unplaced'], true)) ? 'warn' : 'ok',
                'Lắp ráp bài: '.$ins.' ảnh trong bài'.(collect($res['placements'])->contains('status', 'featured') ? ' + ảnh bìa' : ''),
                'Ghép bài viết với ảnh theo kế hoạch: '.implode('; ', array_map(fn ($p) => ($p['role'] === 'featured' ? 'Ảnh bìa' : 'Ảnh '.$p['slot']).' — '.$p['note'], $res['placements'])).'.',
                ['version_id' => $version->id, 'placements' => $res['placements']]);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Lắp ráp ảnh lỗi, bỏ qua: '.class_basename($e));
        }
    }

    /** Bước 'research': chỉ nạp và ghi lại ngữ cảnh; write() dùng lại vì load() chỉ chạy một lần. */
    private function research(AgentRun $run, Article $article, array $outline): void
    {
        $this->kb ??= new KnowledgeContext();
        $this->kb->load($article, $outline);
        $this->assets ??= new ContextAssets();
        $this->assets->load($article, $outline);
        $citable = $this->brainCtx->reliance() === 'must_cite' ? BrainSourceRegistry::forBrains($this->brainCtx->ids())->citable() : collect();
        $citableData = $citable->map(fn ($s) => ['name' => $s->name, 'domain' => $s->domain, 'tier' => (string) $s->tier])->values()->all();
        [$kbData] = $this->kb->stepInfo();
        [$assetData] = $this->assets->stepInfo();
        unset($assetData['images']['inserted_ids'], $assetData['internal_links']['inserted']); // chỉ biết sau khi viết
        $nImg = count($assetData['images']['selected']);
        $nLink = count($assetData['internal_links']['suggested']);
        $brand = $this->brandData();
        $gap = (bool) ($kbData['knowledge']['gap'] ?? false);
        $this->step($run, 'research', 'system', $gap ? 'warn' : 'ok', 'Chuẩn bị tài liệu',
            'Đã nạp '.$kbData['knowledge']['count'].' mục kiến thức đã duyệt, '.$nImg.' ảnh gợi ý, '.$nLink.' liên kết nội bộ gợi ý, '.count($citableData).' nguồn được phép trích'
            .(($brand['brand_profile_fields'] ?? 0) > 0 ? ', hồ sơ doanh nghiệp ('.$brand['brand_profile_fields'].' trường).' : ', chưa có hồ sơ doanh nghiệp.')
            .($gap ? ' Kho kiến thức chưa có mục đã duyệt phù hợp; đã ghi khoảng trống kiến thức.' : ''),
            $kbData + $assetData + ['citable_sources' => $citableData] + $brand + $this->brainCtx->stepData() + ($this->needsExpert ? ['needs_expert' => true, 'reason' => 'high_risk_no_knowledge'] : []));
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
        if ($run->risk_level === 'high' || $this->brainCtx->reliance() === 'must_cite') {
            $options['medical'] = true; // bài sức khỏe/rủi ro cao: bắt buộc có đoạn đi khám và câu lưu ý (luật MED01)
        }
        if (($lay = $this->skills['layout'][0] ?? null) !== null) {
            $options['layout'] = $lay->body;
            $options['layout_ref'] = $lay->ref();
        }
        $options += $this->guidanceOptions($article) + $this->roleOptions('writer') + $this->brandOptions() + $this->brainCtx->options($article);
        $this->kb ??= new KnowledgeContext();
        $this->kb->load($article, $outline); // AG-S20: kiến thức đã duyệt, truy xuất một lần mỗi lượt chạy
        $options += $this->kb->options();
        $citable = $this->brainCtx->reliance() === 'must_cite' ? BrainSourceRegistry::forBrains($this->brainCtx->ids())->citable() : collect();
        if ($citable->isNotEmpty()) {
            $options['citable_sources'] = $citable->map(fn ($s) => ['name' => $s->name, 'domain' => $s->domain, 'tier' => (string) $s->tier])->all(); // AG-B3
        }
        $this->assets ??= new ContextAssets();
        $this->assets->load($article, $outline); // AG-S25: truy xuất một lần mỗi lượt chạy
        $options += $this->assets->options();
        $options += \App\Services\Audit\RefreshOptions::for($article); // AG-S26: bài làm mới từ lỗi Audit
        [$kbData, $kbNote] = $this->kb->stepInfo();
        $r = $this->callAi($run, $stage, $title, fn (AiProviderInterface $ai) => $ai->generateArticle($article, $outline, $options));
        $html = HtmlSanitizer::clean($r['html_content'] ?? '');
        $disclaimerAdded = false;
        if (! empty($options['medical'])) {
            [$html, $disclaimerAdded] = MedicalDisclaimer::ensure($html); // AI quên câu lưu ý y tế thì hệ thống tự thêm (MED01)
        }
        [$assetData, $assetNote] = $this->assets->stepInfo($html);
        // Nhà cung cấp thật có thể rơi về bản giả lập một cách im lặng (thiếu khóa, JSON hỏng). Không được gọi đó là AI thật.
        $fellBack = str_starts_with((string) ($r['model'] ?? ''), 'mock');
        if ($fellBack && $run->ai_mode !== 'mock') {
            $run->update(['ai_mode' => 'mock']);
        }
        $silentMock = $fellBack && ! $this->ai instanceof MockAiProvider;
        $mustCite = $this->brainCtx->reliance() === 'must_cite' && $feedback === [];
        $this->step($run, $stage, 'writer', $silentMock || $mustCite ? 'warn' : 'ok', $title, ($silentMock ? 'Nhà cung cấp AI đã rơi về bản giả lập (thiếu khóa hoặc phản hồi hỏng); nội dung không phải AI thật. ' : '').($feedback === []
            ? 'Viết theo dàn ý'.(($options['guidance'] ?? '') !== '' ? ' và chỉ dẫn thêm của người phụ trách bài' : '').'; HTML được làm sạch trước khi chấm.'.$this->brandNote().$this->brainCtx->note().($mustCite ? ' Lĩnh vực bắt buộc nguồn: cần người có chuyên môn duyệt (luật EVID01 kiểm bằng chứng theo Sổ nguồn ở mức cả bài, chưa nối từng khẳng định với từng nguồn; hiện có '.$citable->count().' nguồn đã xác nhận).' : '').$kbNote.$assetNote.($disclaimerAdded ? ' AI quên câu lưu ý y tế nên hệ thống đã tự thêm khung lưu ý cố định cuối bài.' : '')
            : 'Gửi lại cho AI '.count($feedback).' lỗi cần sửa (lỗi chặn hoặc đoạn thiếu trích dẫn): '.implode(' | ', array_map(fn ($f) => mb_substr($f, 0, 70), array_slice($feedback, 0, 3)))),
            ['words' => \App\Services\Content\VietnameseText::wordCountHtml($html), 'playbook' => $this->pbData($pb), 'layout_skill' => $this->pbData($this->skills['layout'][0] ?? null)] + $this->brandData() + $this->brainCtx->stepData() + ($mustCite ? ['needs_expert_review' => true] : []) + $kbData + $assetData + $this->providerData($r), $r['model'] ?? null, (int) ($r['tokens_used'] ?? 0), (float) ($r['cost_vnd'] ?? 0));

        return [
            'article_id' => $article->id, 'title' => $r['title'] ?? $article->primary_keyword, 'slug' => $r['slug'] ?? 'bai-viet', 'outline' => $outline,
            'html_content' => $html, 'seo_title' => is_string($r['seo_title'] ?? null) ? mb_substr($r['seo_title'], 0, 70) : null, 'meta_description' => is_string($r['meta_description'] ?? null) ? mb_substr($r['meta_description'], 0, 160) : null,
            'focus_keyword' => $r['focus_keyword'] ?? $article->primary_keyword, 'faq_schema' => $r['faq_schema'] ?? [], 'sources_cited' => $r['sources_cited'] ?? [],
            'ai_provider' => $r['model'] ?? 'ai-engine', 'ai_model' => $r['model'] ?? 'unknown', 'prompt_version' => $r['prompt_version'] ?? 'v1.0',
            'tokens_used' => (int) ($r['tokens_used'] ?? 0), 'cost_vnd' => (float) ($r['cost_vnd'] ?? 0),
        ];
    }

    private function grade(AgentRun $run, Article $article, array $attrs, string $title, int $round, ?Playbook $editorPb = null, string $note = ''): QualityReport
    {
        $report = $this->checker->check(new ArticleVersion($attrs), $article, $this->brainCtx->ids() ?: null);
        [$report, $judge] = $this->judgeCitations($report, (string) ($attrs['html_content'] ?? ''));
        $c = $report->counts();
        $failed = array_map(fn ($r) => ['code' => $r->code, 'severity' => $r->severity, 'message' => $r->message], array_slice($report->failed(), 0, 8));
        $block = $report->blockingCodes();
        $score = RunScorer::score($report);
        $this->step($run, 'grade', 'editor', $block === [] ? 'ok' : 'warn', $title,
            "Điểm {$score}/100 (tham khảo, mốc ".RunScorer::REFERENCE_THRESHOLD.'). '.($block === [] ? "Đạt mọi luật bắt buộc (còn {$c['P1']} mục P1, {$c['P2']} mục P2 chỉ nên xem)." : 'Trượt luật bắt buộc: '.implode(', ', $block).'. Quyết định do luật, không do AI.').($note !== '' ? ' '.$note : ''),
            ['blocking' => $block, 'counts' => $c, 'score' => $score, 'round' => $round, 'threshold' => RunScorer::REFERENCE_THRESHOLD, 'penalty' => $this->penalty($report), 'failed' => $failed, 'playbook' => $this->pbData($editorPb)] + ($judge['data'] !== [] ? ['citation_support' => $judge['data']] : []),
            $judge['model'], $judge['tokens'], $judge['cost']);

        return $report;
    }

    /**
     * Lượt AI kiểm NGHĨA trích dẫn (kỹ năng editor/citation-support) cho Trí tuệ bắt buộc nguồn. Có giới hạn thời gian riêng (AiJson: tối đa 90 giây chờ), lỗi AI chỉ ghi "chưa chạy"
     * (không giả là đạt, không làm hỏng phiên). Chỉ chạy khi bài có dấu [KB#id] và nhà cung cấp hỗ trợ JSON có cấu trúc.
     *
     * @return array{0:QualityReport,1:array{data:array<string,mixed>,model:?string,tokens:int,cost:float}}
     */
    private function judgeCitations(QualityReport $report, string $html): array
    {
        $none = ['data' => [], 'model' => null, 'tokens' => 0, 'cost' => 0.0];
        if (! config('quality.citation_judge', true) || $this->brainCtx->reliance() !== 'must_cite' || CitationSupportJudge::pairs($html) === []) {
            return [$report, $none];
        }
        $target = null;
        foreach (['citation_check', 'summary', 'repair'] as $task) { // tuyến riêng, chưa có thì dùng tuyến rẻ/sửa bài đã cấu hình
            if ($this->router && ($target = $this->router->target($task)) !== null) {
                break;
            }
        }
        $ai = $target?->ai ?? $this->ai;
        if (! $ai instanceof StructuredAiInterface) {
            return [$report, ['data' => ['status' => 'not_run', 'reason' => 'Nhà cung cấp AI của bước chấm không hỗ trợ JSON có cấu trúc.']] + $none];
        }
        try {
            $pb = $this->usePlaybook('editor', 'citation-support')[0] ?? null;
            $aiJson = new AiJson($ai);
            $t = microtime(true);
            $r = (new CitationSupportJudge($aiJson, (string) $pb?->body))->judge($html);
            $this->aiMs += (microtime(true) - $t) * 1000;
        } catch (\Throwable $e) {
            report($e);

            return [$report, ['data' => ['status' => 'not_run', 'reason' => 'Lượt kiểm nghĩa trích dẫn lỗi: '.class_basename($e)]] + $none];
        }
        $meta = ['model' => $aiJson->model, 'tokens' => $aiJson->tokens, 'cost' => $aiJson->cost];
        if ($r['error'] !== null) {
            return [$report, ['data' => ['status' => 'not_run', 'reason' => 'Chưa kiểm được nghĩa trích dẫn: '.$r['error']]] + $meta];
        }
        if ($r['result'] === null) {
            return [$report, ['data' => []] + $meta];
        }
        $data = ['status' => 'done', 'checked' => $r['checked'], 'supported' => $r['supported'], 'partial' => $r['partial'], 'unsupported' => $r['unsupported'], 'verdicts' => array_slice($r['verdicts'], 0, 24), 'skill' => $this->pbData($pb)];

        return [new QualityReport([...$report->results, $r['result']]), ['data' => $data] + $meta];
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

    /** AG-S19: hồ sơ thương hiệu của website của bài đang chạy (null nếu chưa có). */
    private ?BrandProfile $brandProfile = null;

    /** @return array<string, mixed> */
    private function brandOptions(): array
    {
        $b = BrandProfileData::forPrompt($this->brandProfile);

        return $b ? ['brand' => $b] : [];
    }

    private function brandNote(): string
    {
        $n = BrandProfileData::forPrompt($this->brandProfile)['fields_used'] ?? 0;

        $src = $this->brandSource();

        return $n > 0 ? " Đã dùng hồ sơ thương hiệu ({$n} trường)".($src ? ", nguồn: {$src}" : '').'.' : '';
    }

    /** AG-B2: nhãn nguồn "Trí tuệ X · Hồ sơ doanh nghiệp" khi website có Trí tuệ Thương hiệu (chỉ nhãn; dữ liệu vẫn qua options['brand']). */
    private function brandSource(): ?string
    {
        $wid = $this->brandProfile?->website_id;
        $site = $wid ? \App\Models\Website::query()->find($wid) : null;
        $brain = $site ? (new \App\Services\Brain\BrainService())->findBrandBrain($site) : null;

        return $brain ? 'Trí tuệ '.$brain->name.' · Hồ sơ doanh nghiệp' : null;
    }

    /** @return array<string, mixed> */
    private function brandData(): array
    {
        $n = BrandProfileData::forPrompt($this->brandProfile)['fields_used'] ?? 0;

        return $n > 0 ? ['brand_profile_fields' => $n] + ($this->brandSource() ? ['brand_source' => $this->brandSource()] : []) : [];
    }

    /** Agent Studio: nếu admin cấp quyền Trí tuệ cho Người viết thì chỉ truy xuất kiến thức trong giao của quyền đó và Trí tuệ đã gắn; rỗng = như cũ. @param list<int> $ids @return list<int> */
    private function writerBrainScope(array $ids): array
    {
        try {
            $grants = \App\Services\Agent\Roles\AgentProfiles::brainGrants('writer');
        } catch (Throwable) {
            $grants = [];
        }

        return $grants === [] ? $ids : array_values(array_intersect($ids, $grants));
    }

    private function roleOff(string $key): bool
    {
        return in_array($key, $this->disabledRoles, true);
    }

    /** Tùy chọn vai trò truyền cho adapter AI: khóa + tên tiếng Việt, để prompt có dòng "Bạn đang đóng vai". */
    /**
     * Định hướng của người cho riêng bài này: chỉ dẫn thêm (articles.guidance) và, nếu bản mới nhất vừa bị người duyệt từ chối kèm ghi chú,
     * góp ý của người duyệt (để giao lại không phải gõ lại góp ý). Chỉ là dữ liệu định hướng, không thay quy tắc an toàn. @return array<string,string>
     */
    private function guidanceOptions(Article $article): array
    {
        $parts = [];
        $g = trim((string) $article->guidance);
        if ($g !== '') {
            $parts[] = $g;
        }
        $latest = $article->versions()->orderByDesc('version')->first();
        if ($latest && $latest->review_status === 'rejected' && trim((string) $latest->review_notes) !== '') {
            $parts[] = 'Góp ý của người duyệt ở bản trước (bản đó bị từ chối, hãy sửa theo): '.trim((string) $latest->review_notes);
        }

        return $parts === [] ? [] : ['guidance' => implode("\n\n", $parts)];
    }

    private function roleOptions(string $key): array
    {
        $opts = ['role' => $key, 'role_name' => $this->roles->roleName($key)];
        try { // Agent Studio: hồ sơ do quản trị tùy chỉnh; lỗi (bảng thiếu...) không làm hỏng phiên chạy
            $block = \App\Services\Agent\Roles\AgentProfiles::promptBlock($key);
            if ($block !== null) {
                $opts['role_profile'] = $block;
            }
        } catch (Throwable) {
        }

        return $opts;
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
        $t = microtime(true);
        try {
            return $this->callAiInner($run, $task, $what, $call);
        } finally {
            $this->aiMs += (microtime(true) - $t) * 1000;
        }
    }

    /** @param \Closure(AiProviderInterface): array $call */
    private function callAiInner(AgentRun $run, string $task, string $what, \Closure $call): array
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
        // prompt_tokens/completion_tokens đi cùng dữ kiện bước; step() tách chúng ra cột riêng (không lưu trùng trong JSON).
        return (isset($r['_provider_id']) ? ['provider_id' => $r['_provider_id']] : [])
            + (isset($r['prompt_tokens'], $r['completion_tokens']) ? ['prompt_tokens' => (int) $r['prompt_tokens'], 'completion_tokens' => (int) $r['completion_tokens']] : [])
            + (isset($r['exchange']) && is_array($r['exchange']) ? ['exchange' => $r['exchange']] : []);
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
        $waited = 0;
        while (true) {
            try {
                return $call();
            } catch (Throwable $e) {
                if (! TransientAiError::is($e)) {
                    throw $e;
                }
                $attempt++;
                $hint = TransientAiError::retryAfterOf($e);
                // Nhà cung cấp nói rõ cần chờ bao lâu thì được thử nhiều lần hơn (trong trần thời gian); lỗi không có gợi ý giữ số lần cũ.
                $limit = $hint !== null ? max($this->policy->maxRetries, $this->policy->maxRateLimitRetries) : $this->policy->maxRetries;
                // 504 = cổng (vd cầu nối CLI) đã chờ trọn timeout mà không có kết quả; thử lại tốn thêm cả timeout nữa nên chỉ thử 1 lần.
                if (TransientAiError::statusOf($e) === 504) {
                    $limit = min($limit, 1);
                }
                $wait = $this->retryWait($attempt, $hint);
                if ($attempt > $limit || ($hint !== null && $waited + $wait > $this->policy->retryBudgetSeconds)) {
                    throw new AiRetriesExhausted($what, $attempt, $e);
                }
                $waited += $wait;
                $this->step($run, 'retry', 'system', 'warn', "Thử lại lần {$attempt}/{$limit}: {$what}",
                    'Nhà cung cấp AI lỗi tạm thời ('.TransientAiError::describe($e).'; '.ErrorMasker::short($e->getMessage(), 120)."). Chờ {$wait} giây rồi thử lại, không đổi sang AI giả lập.",
                    ['attempt' => $attempt, 'max' => $limit, 'wait_seconds' => $wait, 'retry_after' => $hint, 'error' => TransientAiError::describe($e)]);
                ($this->sleep)($wait);
            }
        }
    }

    /**
     * Số giây chờ trước lần thử $attempt: bậc thang cấu hình trước, quá bậc thì nhân đôi theo cấp số mũ có nhiễu ±20% (tránh nhiều tiến trình cùng thử lại một lúc),
     * và không bao giờ ngắn hơn Retry-After của nhà cung cấp (cộng 0–2 giây nhiễu). Trần mỗi lần chờ: policy->maxRetryWaitSeconds.
     */
    private function retryWait(int $attempt, ?int $hint): int
    {
        $delays = $this->policy->backoffSeconds;
        if ($delays === []) {
            $wait = 1.0;
        } elseif ($attempt <= count($delays)) {
            $wait = (float) $delays[$attempt - 1];
        } else {
            $wait = (float) end($delays) * (2 ** ($attempt - count($delays)));
            $wait *= 0.8 + mt_rand(0, 400) / 1000;
        }
        if ($hint !== null) {
            $wait = max($wait, $hint + mt_rand(0, 2));
        }

        return (int) max(0, min(ceil($wait), $this->policy->maxRetryWaitSeconds));
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

    /** Điểm dừng 'outline': ghi bước chờ người rồi kết thúc phiên, KHÔNG gọi AI viết. Bài ở lại 'drafting' (chờ). */
    private function holdAtOutline(AgentRun $run, Article $article, array $outline): AgentRun
    {
        $this->step($run, 'checkpoint', 'system', 'warn', 'Dừng sau dàn ý: chờ bạn xem', 'Chiến dịch đặt điểm dừng sau dàn ý. Đội AI chưa viết (không tốn token viết); bạn sửa dàn ý rồi bấm "Duyệt dàn ý và viết tiếp" trên trang bài.', ['checkpoint' => 'outline', 'outline' => $outline]);
        $article->update(['workflow_status' => 'drafting']);

        return $this->finish($run, 'held', 'Dừng sau dàn ý: chờ bạn xem và sửa dàn ý.');
    }

    private function step(AgentRun $run, string $stage, string $role, string $status, string $title, string $detail, array $data = [], ?string $model = null, int $tokens = 0, float $cost = 0.0): AgentRunStep
    {
        $now = now();
        // Thời lượng ĐO THẬT bằng đồng hồ máy chủ (mili giây): từ lúc bước trước xong đến lúc bước này xong. ai_ms = phần chờ AI (kể cả thử lại) trong đó. Bước chạy nền (ảnh) ghi lại khi xong.
        $ms = $this->markT > 0 ? max(0, (int) round((microtime(true) - $this->markT) * 1000)) : null;
        $this->markT = microtime(true);
        if ($ms !== null) {
            $data['duration_ms'] = $ms;
        }
        if ($this->aiMs > 0) {
            $data['ai_ms'] = (int) round($this->aiMs);
            $this->aiMs = 0.0;
        }
        // Token vào/ra tách riêng (null = nhà cung cấp không báo, vd AI giả lập); cộng dồn lại bằng tokens_used khi có đủ cả hai.
        $tokensIn = isset($data['prompt_tokens']) ? (int) $data['prompt_tokens'] : null;
        $tokensOut = isset($data['completion_tokens']) ? (int) $data['completion_tokens'] : null;
        unset($data['prompt_tokens'], $data['completion_tokens']);
        // AG-S14: mọi bước ghi vai trò (khóa + tên) và kỹ năng; bước không dùng playbook ghi rõ "Chỉ dẫn mặc định" kèm lý do nếu có.
        [$pb, $skillNote] = $this->skills[$stage] ?? [null, ''];
        $data += [
            'role_key' => $role, 'role_name' => $this->roles->roleName($role),
            'skill' => $this->pbData($pb), 'skill_label' => $pb ? $pb->ref().($pb->isRunnable() ? '' : ' (bản nháp)') : 'Chỉ dẫn mặc định',
        ] + ($pb === null && $skillNote !== '' ? ['skill_note' => $skillNote] : []);

        $attrs = [
            'agent_run_id' => $run->id, 'seq' => ++$this->seq, 'stage' => $stage, 'role' => $role, 'status' => $status, 'title' => $title,
            'detail' => $detail, 'data' => $data ?: null, 'model' => $model, 'tokens_used' => $tokens, 'cost_vnd' => $cost, 'started_at' => $ms !== null ? $now->copy()->subMilliseconds($ms) : $now, 'finished_at' => $now,
        ];
        // Môi trường chưa chạy migration 2026_11_12_000002 (thiếu cột token vào/ra) vẫn phải chạy được: không ghi hai cột đó thay vì làm hỏng cả phiên.
        if (self::hasTokenSplitColumns()) {
            $attrs += ['prompt_tokens' => $tokensIn, 'completion_tokens' => $tokensOut];
        }

        return AgentRunStep::create($attrs);
    }

    private static ?bool $tokenSplit = null;

    private static function hasTokenSplitColumns(): bool
    {
        if (self::$tokenSplit !== true) { // chỉ nhớ kết quả "có"; "chưa có" kiểm lại để nhận ra ngay khi migration vừa chạy
            self::$tokenSplit = \Illuminate\Support\Facades\Schema::hasColumns('agent_run_steps', ['prompt_tokens', 'completion_tokens']);
        }

        return self::$tokenSplit;
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
