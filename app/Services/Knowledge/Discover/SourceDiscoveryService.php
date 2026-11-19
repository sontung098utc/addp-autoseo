<?php

namespace App\Services\Knowledge\Discover;

use App\Contracts\StructuredAiInterface;
use App\Filament\Support\Schedule\ErrorMasker;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Risk\RiskAssessment;
use App\Services\Agent\Risk\RiskClassifier;
use App\Services\Agent\Run\JobRunRecorder;
use App\Services\Brain\BrainSourceRegistry;
use App\Services\Knowledge\Build\BrainBuildService;
use App\Services\Knowledge\Build\ReadableTextExtractor;
use App\Services\Knowledge\Build\RiskTiering;
use App\Services\Knowledge\Build\SafePageFetcher;
use App\Services\Knowledge\KnowledgeAiIngest;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Knowledge\KnowledgeReview;
use App\Services\Publishing\EmergencyStopService;
use App\Services\Search\WebSearchResolver;
use App\Services\Search\WebSearchUnavailable;

/**
 * Khám phá nguồn trên web kiểu NotebookLM "Discover sources" (người mô tả chủ đề, hệ thống đề xuất DANH SÁCH nguồn, người thêm một nguồn hoặc tất cả).
 *
 * discover(): soạn truy vấn bằng luật → tìm (Tavily) → bỏ trùng và nguồn bị loại → xếp nguồn tin cậy trước → tối đa 8 ứng viên, mỗi ứng viên có nhãn tin cậy và LÝ DO (SourceTrust).
 * import(): mỗi nguồn được chọn → tải an toàn (chế độ ngoài) → trích chữ → knowledge_sources (origin=search) → KnowledgeAiIngest (luật "đoạn nguyên văn + số có thật trong đoạn" giữ nguyên)
 *   → phân tầng. Nguyên tắc "AI tự đảm bảo, ít rào cản":
 *   - nguồn tin cậy (luật cố định): mục có đoạn nguyên văn hợp lệ được TỰ NHẬN, kể cả chủ đề sức khỏe (nhãn tự nhận, ghi tên miền và ngày tải, hoàn tác được); riêng loại price/policy/myth/forbidden không tự nhận;
 *   - nguồn chưa rõ: chỉ đề xuất ('proposed') chờ duyệt một lần;
 *   - nguồn bị loại: không tải.
 * Ghi MỘT phiên agent_runs job_type=brain_build: bước 'search' (nếu có meta tìm), mỗi nguồn một bước 'source'.
 * Chưa làm: đọc PDF, kiểm tra bản quyền, gọi Tavily thật, tải tên miền ngoài thật (mới kiểm bằng Http giả).
 */
final class SourceDiscoveryService
{
    public const MAX_CANDIDATES = 8;

    public const MIN_TEXT_CHARS = 200;

    /** Việc nên làm khi một nguồn không tải được. */
    public const FETCH_ADVICE = 'Việc nên làm: kiểm tra địa chỉ, hoặc bỏ nguồn này.';

    /** Mã lỗi tải (không phải lỗi AI): chỉ bỏ nguồn đó, không làm hỏng cả phiên. */
    private const FETCH_ERRORS = ['dns', 'timeout', 'http_error', 'blocked', 'robots', 'too_large', 'not_html', 'off_domain'];

    /** Cửa sổ chống trùng cho lượt hệ thống tự chạy (phút). */
    public const DEDUPE_MINUTES = 10;

    /** Từ gợi ý thêm vào truy vấn theo lĩnh vực rủi ro. */
    private const HINTS = ['health' => 'hướng dẫn khuyến cáo', 'pharma' => 'GMP hướng dẫn tiêu chuẩn', 'finance' => 'quy định hướng dẫn', 'legal' => 'quy định hướng dẫn', 'education' => 'hướng dẫn'];

    /** Cụm chỉ sản xuất dược và thiết bị dược. */
    public const PHARMA_PHRASES = ['máy dập viên', 'máy bào chế', 'thiết bị dược', 'thiết bị bào chế', 'sản xuất dược', 'sản xuất thuốc', 'nhà máy dược', 'nhà máy sản xuất thuốc', 'dây chuyền dược', 'dây chuyền sản xuất thuốc', 'gmp', 'dập viên', 'bao phim', 'tablet press', 'pharmaceutical manufacturing', 'thẩm định thiết bị'];

    private SafePageFetcher $fetcher;

    private KnowledgeAiIngest $ingest;

    private RiskTiering $tiering;

    public function __construct(
        ?SafePageFetcher $fetcher = null,
        ?KnowledgeAiIngest $ingest = null,
        ?RiskTiering $tiering = null,
        private ?EmergencyStopService $stop = null,
        private readonly ?StructuredAiInterface $ai = null,
        private readonly ?float $cap = null,
        private readonly string $label = 'AI',
        private readonly bool $demo = false,
        private ?WebSearchResolver $resolver = null,
    ) {
        $this->fetcher = $fetcher ?? new SafePageFetcher;
        $this->ingest = $ingest ?? new KnowledgeAiIngest;
        $this->tiering = $tiering ?? new RiskTiering;
        $this->resolver ??= new WebSearchResolver;
    }

    /**
     * Tìm trước, CÙNG LÚC, lượt tìm đầu tiên của nhiều chủ đề (chỉ khi nhà cung cấp là cầu nối Claude, mỗi lượt mất vài phút). Truy vấn và bộ lọc tên miền
     * được dựng giống hệt discover() nên khi discover() chạy tuần tự sau đó sẽ đọc từ bộ nhớ, không tìm lại. Không ném lỗi.
     *
     * @param  list<string>  $topics
     */
    public function prefetch(Website $site, array $topics, array $options = []): void
    {
        try {
            $provider = $this->resolver->resolve()['provider'];
            if (! $provider instanceof \App\Services\Search\ClaudeBridgeSearchProvider || count($topics) < 2) {
                return;
            }
            $brain = $site->brains()->first();
            $calls = [];
            foreach ($topics as $topic) {
                $topic = trim(preg_replace('/\s+/u', ' ', (string) $topic) ?? '');
                if ($topic === '') {
                    continue;
                }
                $field = $options['field'] ?? self::fieldFor($topic, $brain);
                $query = mb_substr($topic.(isset(self::HINTS[$field ?? '']) ? ' '.self::HINTS[$field] : ''), 0, 300);
                $opts = ['max_results' => min(10, (int) ($options['max_results'] ?? 10))];
                $domains = $field !== null ? SourceTrust::domainsFor($field) : [];
                $calls[] = [$query, $domains !== [] ? $opts + ['include_domains' => array_slice($domains, 0, 20)] : $opts + ['exclude_domains' => array_slice((array) config('trusted_sources.blocked', []), 0, 20)]];
            }
            $provider->prefetch($calls);
        } catch (\Throwable) {
            // bỏ qua: discover() tuần tự vẫn chạy bình thường
        }
    }

    /** Lĩnh vực rủi ro của chủ đề theo luật (không AI): sức khỏe nếu có cụm sức khỏe hoặc Trí tuệ rủi ro cao và chủ đề xếp hạng cao. */
    public static function fieldFor(string $topic, ?Brain $brain = null): ?string
    {
        $t = mb_strtolower($topic);
        // Sản xuất dược và thiết bị dược (máy dập viên, GMP, dây chuyền...) có nhóm nguồn chính thống riêng (WHO, FDA, EMA, PIC/S, ISPE, USP...); xét trước nhóm sức khỏe chung.
        foreach (self::PHARMA_PHRASES as $p) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($p, '/').'(?![\p{L}\p{N}])/u', $t)) {
                return 'pharma';
            }
        }
        foreach (RiskTiering::HEALTH_PRODUCT_PHRASES as $p) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($p, '/').'(?![\p{L}\p{N}])/u', $t)) {
                return 'health';
            }
        }
        $high = (new RiskClassifier)->assess($topic, '')->level === RiskAssessment::HIGH;
        if ($high && $brain !== null && $brain->riskClass() === 'high' && preg_match('/y tế|sức khỏe|health|bệnh|thuốc|dinh dưỡng|tiểu đường|y khoa/iu', $brain->name.' '.$brain->domain_key)) {
            return 'health';
        }

        return null;
    }

    /**
     * @param  array{max_results?: int, field?: string}  $options
     * @return array{candidates: list<array{title: string, url: string, domain: string, snippet: string, trust: array{level: string, reason: string, field: ?string}, score: float}>, cost_vnd: float, provider: string, query: string, field: ?string, demo: bool, label: ?string, results_total: int}
     *
     * @throws WebSearchUnavailable chưa có khóa (production) hoặc nhà cung cấp lỗi
     */
    public function discover(Website $site, string $topic, array $options = []): array
    {
        $topic = trim(preg_replace('/\s+/u', ' ', $topic) ?? '');
        if ($topic === '') {
            throw new WebSearchUnavailable('Nhập chủ đề cần tìm tài liệu.');
        }
        $brain = $site->brains()->first();
        $field = $options['field'] ?? self::fieldFor($topic, $brain);
        $query = mb_substr($topic.(isset(self::HINTS[$field ?? '']) ? ' '.self::HINTS[$field] : ''), 0, 300);
        $r = $this->resolver->resolve();
        $provider = $r['provider'];
        $cost = 0.0;
        $rows = [];
        $total = 0;
        $providerName = '';
        $searchOpts = ['max_results' => min(10, (int) ($options['max_results'] ?? 10))] + (! empty($options['fresh']) ? ['fresh' => true] : []);
        if ($field !== null && ($domains = SourceTrust::domainsFor($field)) !== []) {
            $res = $provider->search($query, $searchOpts + ['include_domains' => array_slice($domains, 0, 20)]);
            $cost += (float) $res['cost_vnd'];
            $providerName = (string) $res['provider'];
            $total += count($res['results']);
            $rows = $res['results'];
        }
        $good = fn (array $list) => count(array_filter($list, fn ($x) => SourceTrust::assess($x['url'], $brain, $field)['level'] !== 'blocked'));
        if ($rows === [] || $good($rows) < 3) { // ít kết quả: mở rộng ra toàn web
            $res = $provider->search($query, $searchOpts + ['exclude_domains' => array_slice((array) config('trusted_sources.blocked', []), 0, 20)]);
            $cost += (float) $res['cost_vnd'];
            $providerName = (string) $res['provider'];
            $total += count($res['results']);
            $rows = array_merge($rows, $res['results']);
        }

        $seen = [];
        $cands = [];
        foreach ($rows as $x) {
            $url = (string) ($x['url'] ?? '');
            $host = BrainSourceRegistry::hostOf($url);
            if ($host === null || BadSourceMemory::isBad($host)) { // tên miền vừa không tải được (24 giờ): không gợi ý lại
                continue;
            }
            $key = $host.'|'.rtrim((string) parse_url($url, PHP_URL_PATH), '/');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $trust = SourceTrust::assess($url, $brain, $field);
            if ($trust['level'] === 'blocked') {
                continue;
            }
            $cands[] = ['title' => (string) ($x['title'] ?? $url), 'url' => $url, 'domain' => $host, 'snippet' => (string) ($x['snippet'] ?? ''), 'trust' => $trust, 'score' => (float) ($x['score'] ?? 0)];
        }
        usort($cands, fn ($a, $b) => [$a['trust']['level'] === 'trusted' ? 0 : 1, -$a['score']] <=> [$b['trust']['level'] === 'trusted' ? 0 : 1, -$b['score']]);

        return ['candidates' => array_slice($cands, 0, self::MAX_CANDIDATES), 'cost_vnd' => round($cost, 2), 'provider' => $providerName,
            'query' => $query, 'field' => $field, 'demo' => $r['demo'], 'label' => $r['label'], 'results_total' => $total];
    }

    /**
     * @param  list<array{url: string, title?: string}>  $candidates  chỉ cần url (độ tin cậy được tính lại ở đây, không tin dữ liệu gửi lên)
     * @param  array{as_system?: bool, run?: \App\Models\AgentRun, brain_id?: int, budget_cap_vnd?: float, field?: ?string, search?: array{query?: string, results?: int, cost_vnd?: float, provider?: string}, max_sources?: int, only_trusted?: bool, purpose?: string}  $options
     * @return array{run_id: ?int, imported: int, items_auto: int, items_proposed: int, pages: list<array<string,mixed>>, cost_vnd: float, stopped_reason: ?string, auto_item_ids: list<int>, proposed_item_ids: list<int>}
     *
     * @throws KnowledgeError không có quyền, dừng khẩn cấp, website không hoạt động, chưa kết nối AI
     */
    public function import(Website $site, array $candidates, ?User $by, array $options = []): array
    {
        if (! ($options['as_system'] ?? false) && ! KnowledgeReview::canManage($by)) {
            throw new KnowledgeError('Bạn chưa có quyền thêm nguồn vào kho. Cần quyền người duyệt trở lên.');
        }
        $this->stop ??= app(EmergencyStopService::class);
        $why = $site->status !== 'active' ? 'Website không ở trạng thái hoạt động.' : ($this->stop->isStopped($site->id) ? 'Đang dừng khẩn cấp: không tải trang nào.' : null);
        $resolved = null;
        if ($why === null) {
            try {
                $resolved = $this->ai !== null ? ['ai' => $this->ai, 'cap' => $this->cap, 'label' => $this->label, 'demo' => $this->demo] : $this->ingest->resolve();
            } catch (KnowledgeError $e) {
                $why = $e->getMessage();
            }
        }
        $budget = min(isset($options['budget_cap_vnd']) ? max(0.0, (float) $options['budget_cap_vnd']) : PHP_FLOAT_MAX, BrainBuildService::totalBudgetVnd());
        $brain = isset($options['brain_id']) ? \App\Models\Brain::query()->find((int) $options['brain_id']) : $site->brains()->first(); // quy trình Xây kho chuyên ngành chỉ định đúng Trí tuệ
        $field = $options['field'] ?? null;
        $list = [];
        foreach ($candidates as $c) {
            $url = trim((string) ($c['url'] ?? ''));
            if ($url !== '' && ! isset($list[$url])) {
                $list[$url] = ['url' => $url, 'title' => (string) ($c['title'] ?? ''), 'trust' => SourceTrust::assess($url, $brain, $field)];
            }
        }
        $list = array_values($list);
        if (! empty($options['only_trusted'])) {
            $list = array_values(array_filter($list, fn ($c) => $c['trust']['level'] === 'trusted'));
        }
        $list = array_slice($list, 0, (int) ($options['max_sources'] ?? self::MAX_CANDIDATES));
        $topic = mb_substr(trim((string) ($options['topic'] ?? $options['search']['query'] ?? '')), 0, 200); // chủ đề của lượt: hiện ở danh sách và tiêu đề phiên (không có thì người xem chỉ thấy "Xây kho trí tuệ")
        $purpose = (string) ($options['purpose'] ?? 'discover');
        $refs = ['sources' => count($list), 'budget_vnd' => $budget === PHP_FLOAT_MAX ? null : $budget, 'purpose' => $purpose]
            + ($topic !== '' ? ['topic' => $topic] : []) + ($brain ? ['brain_id' => $brain->id, 'brain_name' => mb_substr((string) $brain->name, 0, 120)] : [])
            + ['run_key' => md5(mb_strtolower($topic).'|'.$purpose.'|'.($brain?->id ?? 0).'|'.implode('|', array_column($list, 'url')))];
        $out = ['run_id' => null, 'imported' => 0, 'items_auto' => 0, 'items_proposed' => 0, 'pages' => [], 'cost_vnd' => 0.0, 'stopped_reason' => null, 'auto_item_ids' => [], 'proposed_item_ids' => []];
        $searchCost = (float) ($options['search']['cost_vnd'] ?? 0);

        if ($why !== null) {
            $run = JobRunRecorder::start('brain_build', $site, $refs, $by);
            JobRunRecorder::step($run, 'guard', 'Chưa chạy', 'blocked', [], null, 0, 0, $why.' Chưa tải trang nào.');
            JobRunRecorder::finish($run, 'blocked', null, $why);

            throw new KnowledgeError($why);
        }

        $ownRun = ! (($options['run'] ?? null) instanceof \App\Models\AgentRun);
        // Chống trùng: lượt HỆ THỐNG tự chạy (đội AI tự nạp kho) cùng website, chủ đề, mục đích và danh sách nguồn trong 10 phút thì không mở phiên và không tải lại (tránh ba phiên giống hệt cùng một phút).
        // Lượt người bấm tay không bị chặn: người dùng chủ động thử lại thì phải chạy thật.
        if ($ownRun && ($options['as_system'] ?? false) && $list !== []) {
            $dup = \App\Models\AgentRun::query()->where('website_id', $site->id)->where('job_type', 'brain_build')->where('created_at', '>=', now()->subMinutes(self::DEDUPE_MINUTES))->orderByDesc('id')->limit(20)->get()
                ->first(fn ($r) => ($r->input_refs['run_key'] ?? null) === $refs['run_key']);
            if ($dup !== null) {
                $out['run_id'] = $dup->id;
                $out['stopped_reason'] = 'duplicate';

                return $out;
            }
        } // quy trình Xây kho chuyên ngành truyền phiên của nó vào để các bước nằm chung một phiên
        $run = $ownRun ? JobRunRecorder::start('brain_build', $site, $refs, $by, 'manual', $resolved['demo'] ? 'mock' : 'real') : $options['run'];
        if (isset($options['search'])) {
            $s = $options['search'];
            JobRunRecorder::step($run, 'search', 'Tìm nguồn trên web', 'completed', ['query' => (string) ($s['query'] ?? ''), 'results' => (int) ($s['results'] ?? count($list)), 'provider' => (string) ($s['provider'] ?? '')], null, 0, $searchCost,
                'Đã tìm "'.($s['query'] ?? '').'": '.(int) ($s['results'] ?? count($list)).' kết quả, chi phí ước lượng '.number_format($searchCost, 0, ',', '.').' ₫.');
        }
        $out['cost_vnd'] = $searchCost;
        $builder = new BrainBuildService; // chỉ dùng autoApprove() (một chốt tự nhận chung cho cả hai đường)
        $done = 0;
        foreach ($list as $c) {
            $url = $c['url'];
            $trust = $c['trust'];
            if ($this->stop->isStopped($site->id)) {
                $out['stopped_reason'] = 'emergency_stop';
                JobRunRecorder::step($run, 'halt', 'Dừng khẩn cấp', 'blocked', ['done' => $done], null, 0, 0, 'Dừng khẩn cấp: đã đọc '.$done.'/'.count($list).' nguồn.');
                break;
            }
            if ($out['cost_vnd'] >= $budget) {
                $out['stopped_reason'] = 'budget';
                JobRunRecorder::step($run, 'halt', 'Chạm trần chi phí', 'blocked', ['done' => $done, 'cost_vnd' => $out['cost_vnd']], null, 0, 0, 'Chạm trần chi phí '.number_format($budget, 0, ',', '.').' ₫: đã đọc '.$done.'/'.count($list).' nguồn.');
                break;
            }
            $domain = (string) BrainSourceRegistry::hostOf($url);
            if ($trust['level'] === 'blocked') {
                JobRunRecorder::step($run, 'source', $url, 'skipped', ['url' => $url, 'domain' => $domain, 'trust' => $trust['level'], 'reason' => 'blocked'], null, 0, 0, 'Nguồn bị loại: '.$trust['reason']);

                continue;
            }
            $done++;
            $page = $this->fetcher->fetchExternal($url);
            if (! $page['ok']) {
                $why = self::explainFetch((string) $page['error'], (string) $page['message'], $domain);
                JobRunRecorder::step($run, 'source', $domain !== '' ? $domain : $url, 'failed', ['url' => $url, 'domain' => $domain, 'trust' => $trust['level'], 'error' => $page['error'], 'phase' => 'fetch'], null, 0, 0, $why);
                $out['pages'][] = ['url' => $url, 'domain' => $domain, 'ok' => false, 'error' => $page['error'], 'message' => $page['message'], 'why' => $why, 'trust' => $trust['level']];
                if (BadSourceMemory::hostLevel((string) $page['error'], isset($page['http_status']) ? (int) $page['http_status'] : null)) {
                    BadSourceMemory::mark($domain, (string) $page['error'], (string) $page['message']);
                }

                continue;
            }
            $ex = (new ReadableTextExtractor)->extract($page['html']);
            if (mb_strlen($ex['text']) < self::MIN_TEXT_CHARS) {
                JobRunRecorder::step($run, 'source', $url, 'skipped', ['url' => $url, 'domain' => $domain, 'reason' => 'too_short'], null, 0, 0, 'Trang có quá ít chữ để rút kiến thức.');
                $out['pages'][] = ['url' => $url, 'domain' => $domain, 'ok' => false, 'error' => 'too_short', 'message' => 'Trang có quá ít chữ.', 'trust' => $trust['level']];

                continue;
            }
            $hash = KnowledgeDedupe::hash('', $ex['text']);
            if (KnowledgeSource::query()->where('website_id', $site->id)->where('kind', 'url')->where('content_hash', $hash)->exists()) {
                JobRunRecorder::step($run, 'source', $url, 'skipped', ['url' => $url, 'domain' => $domain, 'reason' => 'unchanged'], null, 0, 0, 'Nội dung đã có trong kho từ trước.');
                $out['pages'][] = ['url' => $url, 'domain' => $domain, 'ok' => false, 'error' => 'unchanged', 'message' => 'Nội dung đã có trong kho.', 'trust' => $trust['level']];

                continue;
            }
            $source = KnowledgeSource::create([
                'brain_id' => $brain?->id, 'website_id' => $site->id, 'kind' => 'url', 'title' => mb_substr($ex['title'] !== '' ? $ex['title'] : ($c['title'] !== '' ? $c['title'] : $url), 0, 200), 'url' => mb_substr($url, 0, 2048),
                'raw_text' => $ex['text'], 'content_hash' => $hash, 'status' => 'new', 'created_by' => $by?->id,
                'origin' => 'search', 'fetched_at' => now(), 'final_url' => mb_substr((string) $page['final_url'], 0, 2048), 'http_status' => $page['http_status'],
            ]);
            $out['imported']++;
            try {
                $log = $this->ingest->run($source, $by ?? (($options['as_system'] ?? false) ? BrainBuildService::systemActor() : null), $resolved['ai'], $resolved['cap'], $resolved['label'], $resolved['demo']);
            } catch (KnowledgeError $e) {
                $log = ['cost_vnd' => 0.0, 'stopped' => 'error', 'error' => $e->getMessage()];
            }
            $out['cost_vnd'] = round($out['cost_vnd'] + (float) ($log['cost_vnd'] ?? 0), 2);

            $auto = $prop = 0;
            foreach (KnowledgeItem::query()->where('source_id', $source->id)->where('status', 'proposed')->orderBy('id')->get() as $item) {
                $t = $this->tiering->tier($item, $brain, false);
                $item->update(['risk_tier' => $t['tier']]);
                // Nguồn TIN CẬY (danh sách cố định) + có đoạn nguyên văn từ trang thì tự nhận, kể cả chủ đề y tế/tài chính (tầng C do hạng rủi ro): nội dung lấy đúng từ nguồn uy tín, hoàn tác được.
                // Loại mục của doanh nghiệp (giá, chính sách, quan niệm sai, điều cấm) và mục mâu thuẫn với mục khác vẫn chờ người duyệt. Tắt bằng trusted_sources.auto_approve_any_risk=false để quay lại "tầng C luôn chờ".
                $anyRisk = (bool) config('trusted_sources.auto_approve_any_risk', true);
                // Mục loại "sản phẩm" từ nguồn ngoài (bài báo, trang nhà sản xuất) KHÔNG tự nhận: kho chỉ coi sản phẩm là sản phẩm của chính công ty (lấy từ website của họ).
                $ok = $trust['level'] === 'trusted' && trim((string) $item->source_span) !== '' && ! in_array($item->type, RiskTiering::C_TYPES, true) && $item->type !== 'product'
                    && ($anyRisk || $t['tier'] !== RiskTiering::C)
                    && $builder->autoApprove($item, $by, 'Tự nhận từ nguồn tin cậy '.$domain.' (tải '.now()->format('d/m/Y').')');
                if ($ok) {
                    $auto++;
                    $out['auto_item_ids'][] = $item->id;
                } else {
                    $prop++;
                    $out['proposed_item_ids'][] = $item->id;
                }
            }
            $out['items_auto'] += $auto;
            $out['items_proposed'] += $prop;
            $n = $auto + $prop;
            $aiWhy = self::explainAi($log, $domain, $n);
            $out['pages'][] = ['url' => $url, 'domain' => $domain, 'ok' => true, 'title' => $source->title, 'source_id' => $source->id, 'fetched_at' => $source->fetched_at?->toIso8601String(), 'trust' => $trust['level'], 'items_auto' => $auto, 'items_proposed' => $prop]
                + ($aiWhy !== null ? ['why' => $aiWhy] : []);
            // Trung thực: tải được trang nhưng AI không rút được mục nào thì bước này là LỖI (hoặc cần chú ý nếu AI chạy được nhưng không có mục hợp lệ), không ghi "Xong".
            $stepStatus = $n > 0 ? (isset($log['error']) ? 'warn' : 'completed') : (isset($log['error']) ? 'failed' : 'warn');
            JobRunRecorder::step($run, 'source', $domain !== '' ? $domain : $url, $stepStatus, ['url' => $url, 'domain' => $domain, 'source_id' => $source->id, 'trust' => $trust['level'], 'trust_reason' => $trust['reason'], 'items' => $n, 'auto' => $auto, 'proposed' => $prop, 'fetched_at' => $source->fetched_at?->toIso8601String(), 'phase' => 'ai']
                + (isset($log['error']) ? ['error' => ErrorMasker::short((string) $log['error'], 200)] : []),
                $resolved['label'], (int) ($log['tokens'] ?? 0), (float) ($log['cost_vnd'] ?? 0), $aiWhy ?? ($n > 0 ? 'Đã rút '.$n.' mục ('.$auto.' tự nhận, '.$prop.' chờ duyệt).' : null));
            if (($log['stopped'] ?? null) === 'error') {
                $out['stopped_reason'] = 'ai_error';
                break;
            }
        }
        // Nguồn không tải được chỉ bị BỎ (ghi cảnh báo từng nguồn): phiên vẫn chạy tiếp các nguồn còn lại.
        $fetchFailed = array_values(array_filter($out['pages'], fn ($p) => empty($p['ok']) && in_array($p['error'] ?? '', self::FETCH_ERRORS, true)));
        $fetchedOk = count(array_filter($out['pages'], fn ($p) => ! empty($p['ok']))) + count(array_filter($out['pages'], fn ($p) => in_array($p['error'] ?? '', ['too_short', 'unchanged'], true)));
        $skipNote = $fetchFailed !== [] ? 'Xong, bỏ '.count($fetchFailed).' nguồn không tải được: '.self::summarizeFetch($fetchFailed) : null;
        $out['skipped_sources'] = count($fetchFailed);
        if ($fetchFailed !== []) {
            JobRunRecorder::step($run, 'source_summary', 'Nguồn không tải được', 'warn', ['count' => count($fetchFailed), 'sources' => array_map(fn ($p) => ['domain' => $p['domain'] ?? '', 'url' => $p['url'] ?? '', 'error' => $p['error'] ?? '', 'why' => $p['why'] ?? ''], $fetchFailed)], null, 0, 0, $skipNote);
        }
        if ($ownRun) {
            $itemIds = array_merge($out['auto_item_ids'], $out['proposed_item_ids']);
            $failedPages = array_values(array_filter($out['pages'], fn ($p) => ! in_array($p['error'] ?? '', self::FETCH_ERRORS, true) && (empty($p['ok']) && ! in_array($p['error'] ?? '', ['too_short', 'unchanged'], true) || (! empty($p['ok']) && ! empty($p['why']) && ((int) ($p['items_auto'] ?? 0) + (int) ($p['items_proposed'] ?? 0)) === 0))));
            $budgetNote = $out['stopped_reason'] === 'budget' ? 'Chạm trần chi phí.' : null;
            [$status, $reason] = match (true) {
                $out['stopped_reason'] === 'emergency_stop' => ['blocked', null],
                $itemIds !== [] => ['ready_for_review', trim(($skipNote ?? '').' '.($budgetNote ?? '')) ?: null],
                $out['stopped_reason'] === 'ai_error' => ['failed', 'Không rút được mục nào. '.self::summarize($failedPages)],
                $fetchFailed !== [] && $fetchedOk === 0 && $failedPages === [] => ['failed', 'Không tải được nguồn nào. '.self::summarizeFetch($fetchFailed)],
                $failedPages !== [] => ['failed', 'Không rút được mục nào. '.self::summarize($failedPages)],
                default => ['ready_for_review', $skipNote ?? ($budgetNote ?? ($list === [] ? 'Không có nguồn nào để nạp (không có nguồn tin cậy trong kết quả tìm).' : 'Không có mục mới: nội dung các nguồn đã có trong kho hoặc quá ít chữ.'))],
            };
            JobRunRecorder::finish($run, $status, ['source_ids' => array_values(array_filter(array_column($out['pages'], 'source_id'))), 'item_ids' => $itemIds, 'auto_approved' => $out['items_auto'], 'proposed' => $out['items_proposed']], $reason);
        }
        $out['run_id'] = $run?->id;

        return $out;
    }

    /** Danh sách nguồn không tải được kèm lý do ngắn và việc nên làm. @param list<array<string,mixed>> $pages */
    private static function summarizeFetch(array $pages): string
    {
        $parts = [];
        foreach (array_slice($pages, 0, 5) as $p) {
            $parts[] = ($p['domain'] ?? '') !== '' ? $p['domain'].' ('.self::shortReason((string) ($p['error'] ?? '')).')' : (string) ($p['url'] ?? '');
        }

        return implode('; ', $parts).(count($pages) > 5 ? '; …' : '').'. '.self::FETCH_ADVICE;
    }

    private static function shortReason(string $code): string
    {
        return match ($code) {
            'dns' => 'không tìm thấy tên miền',
            'timeout' => 'hết giờ chờ',
            'robots' => 'robots.txt cấm đọc',
            'blocked' => 'bị chặn an toàn mạng',
            'too_large' => 'trang quá lớn',
            'not_html' => 'không phải trang chữ',
            'off_domain' => 'ngoài danh sách cho phép',
            default => 'máy chủ nguồn lỗi hoặc từ chối',
        };
    }

    /** Gộp lý do lỗi của vài nguồn thành một câu cho "Lý do kết thúc". @param list<array<string,mixed>> $pages */
    private static function summarize(array $pages): string
    {
        $parts = [];
        foreach (array_slice($pages, 0, 3) as $p) {
            $parts[] = ($p['domain'] ?? '').': '.rtrim((string) ($p['why'] ?? $p['message'] ?? ''), '. ');
        }

        return $parts === [] ? 'Xem từng bước để biết nguồn nào lỗi.' : implode('; ', $parts).'.';
    }

    /** Lý do tải nguồn ngoài thất bại, tiếng Việt, kèm gợi ý cho người vận hành; phân biệt chặn an toàn / robots / hết giờ / mã lỗi của máy chủ nguồn. */
    public static function explainFetch(string $code, string $message, string $domain): string
    {
        $hint = match ($code) {
            'dns' => 'Không tìm thấy tên miền (lỗi DNS) nên hệ thống không thử lại; kiểm tra địa chỉ có gõ đúng không, hoặc bỏ nguồn này.',
            'timeout' => 'Máy chủ nguồn trả lời quá chậm hoặc chặn kết nối từ máy chủ của hệ thống; thử lại sau, hoặc kiểm tra máy chủ chạy hệ thống có ra được Internet không.',
            'blocked' => 'Bị chặn theo quy tắc an toàn mạng (địa chỉ nội bộ hoặc giao thức không cho phép), không phải lỗi nguồn.',
            'robots' => 'Website nguồn cấm robot đọc trang này trong robots.txt; hệ thống tôn trọng và không tải.',
            'too_large' => 'Trang lớn hơn giới hạn 2 MB nên không tải.',
            'not_html' => 'Nguồn không phải trang chữ (ví dụ PDF hoặc ảnh); hệ thống chưa đọc loại này.',
            'off_domain' => 'Tên miền không nằm trong danh sách được phép tải.',
            default => 'Máy chủ nguồn từ chối hoặc không phản hồi (có thể chặn máy chủ của hệ thống, cần đăng nhập, hoặc lỗi mạng/DNS/chứng chỉ).',
        };

        return 'Không tải được '.($domain !== '' ? $domain : 'nguồn').' (AI chưa được gọi): '.rtrim($message, '. ').'. '.$hint.' '.self::FETCH_ADVICE;
    }

    /** Lý do ngắn cho một trang của chính website (không nhắc AI). */
    public static function explainFetchShort(string $code, string $message): string
    {
        return rtrim($message, '. ').'. '.self::FETCH_ADVICE;
    }

    /**
     * Lý do AI không rút được (đủ) mục từ một nguồn đã tải xong; null nếu không có gì bất thường.
     * Phân biệt: hết hạn mức/giới hạn tốc độ, hết giờ, chưa cấu hình/khóa sai, AI trả sai cấu trúc, AI chạy được nhưng không mục nào có đoạn nguyên văn hợp lệ.
     *
     * @param  array<string,mixed>  $log  nhật ký của KnowledgeAiIngest::run
     */
    public static function explainAi(array $log, string $domain, int $items): ?string
    {
        $err = trim((string) ($log['error'] ?? ''));
        if ($err === '' && $items > 0) {
            return null;
        }
        $e = mb_strtolower($err);
        $cause = match (true) {
            $err === '' => 'AI đã đọc nhưng không có mục nào qua kiểm tra (đoạn nguyên văn không khớp trang, số liệu không có trong đoạn, nội dung trùng kho'.((int) ($log['not_vietnamese'] ?? 0) > 0 ? ' hoặc không phải tiếng Việt' : '').'). Bỏ '.((int) ($log['invalid'] ?? 0) + (int) ($log['injection_dropped'] ?? 0)).' mục không hợp lệ, '.(int) ($log['duplicates'] ?? 0).' mục trùng',
            (bool) preg_match('/429|rate.?limit|quota|hạn mức|hết lượt|too many|overloaded|quá tải/u', $e) => 'AI hết hạn mức hoặc bị giới hạn tốc độ (429): '.$err.'. Chờ rồi chạy lại, hoặc kiểm tra hạn mức của tài khoản AI',
            (bool) preg_match('/timed? ?out|hết thời gian|timeout/u', $e) => 'AI trả lời quá lâu (hết thời gian chờ): '.$err.'. Thử lại; nếu lặp lại thì đổi model của tác vụ «knowledge»',
            (bool) preg_match('/401|403|unauthor|api.?key|khóa|chưa (kết nối|cấu hình)|route|không có model|credential/u', $e) => 'Chưa gọi được AI do cấu hình hoặc khóa: '.$err.'. Kiểm tra route AI «knowledge» và khóa tại Cài đặt AI',
            (bool) preg_match('/json|cấu trúc|schema|items/u', $e) => 'AI trả sai định dạng JSON: '.$err.'. Model của tác vụ «knowledge» có thể quá yếu hoặc bị cắt cụt; thử model mạnh hơn',
            default => 'AI gặp lỗi: '.$err,
        };

        return 'Tải được '.($domain !== '' ? $domain : 'nguồn').' nhưng '.($items > 0 ? 'chỉ rút được một phần ('.$items.' mục). ' : 'không rút được mục nào. ').$cause.'.';
    }
}
