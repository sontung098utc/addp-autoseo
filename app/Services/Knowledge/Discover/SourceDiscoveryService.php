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

    /** Từ gợi ý thêm vào truy vấn theo lĩnh vực rủi ro. */
    private const HINTS = ['health' => 'hướng dẫn khuyến cáo', 'finance' => 'quy định hướng dẫn', 'legal' => 'quy định hướng dẫn', 'education' => 'hướng dẫn'];

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

    /** Lĩnh vực rủi ro của chủ đề theo luật (không AI): sức khỏe nếu có cụm sức khỏe hoặc Trí tuệ rủi ro cao và chủ đề xếp hạng cao. */
    public static function fieldFor(string $topic, ?Brain $brain = null): ?string
    {
        $t = mb_strtolower($topic);
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
        $searchOpts = ['max_results' => min(10, (int) ($options['max_results'] ?? 10))];
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
            if ($host === null) {
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
     * @param  array{as_system?: bool, budget_cap_vnd?: float, field?: ?string, search?: array{query?: string, results?: int, cost_vnd?: float, provider?: string}, max_sources?: int, only_trusted?: bool, purpose?: string}  $options
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
        $brain = $site->brains()->first();
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
        $refs = ['sources' => count($list), 'budget_vnd' => $budget === PHP_FLOAT_MAX ? null : $budget, 'purpose' => (string) ($options['purpose'] ?? 'discover')];
        $out = ['run_id' => null, 'imported' => 0, 'items_auto' => 0, 'items_proposed' => 0, 'pages' => [], 'cost_vnd' => 0.0, 'stopped_reason' => null, 'auto_item_ids' => [], 'proposed_item_ids' => []];
        $searchCost = (float) ($options['search']['cost_vnd'] ?? 0);

        if ($why !== null) {
            $run = JobRunRecorder::start('brain_build', $site, $refs, $by);
            JobRunRecorder::step($run, 'guard', 'Chưa chạy', 'blocked', [], null, 0, 0, $why.' Chưa tải trang nào.');
            JobRunRecorder::finish($run, 'blocked', null, $why);

            throw new KnowledgeError($why);
        }

        $run = JobRunRecorder::start('brain_build', $site, $refs, $by, 'manual', $resolved['demo'] ? 'mock' : 'real');
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
                JobRunRecorder::step($run, 'source', $url, 'failed', ['url' => $url, 'domain' => $domain, 'trust' => $trust['level'], 'error' => $page['error']], null, 0, 0, $page['message']);
                $out['pages'][] = ['url' => $url, 'domain' => $domain, 'ok' => false, 'error' => $page['error'], 'message' => $page['message'], 'trust' => $trust['level']];

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
                $ok = $trust['level'] === 'trusted' && trim((string) $item->source_span) !== '' && ! in_array($item->type, RiskTiering::C_TYPES, true)
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
            $out['pages'][] = ['url' => $url, 'domain' => $domain, 'ok' => true, 'title' => $source->title, 'source_id' => $source->id, 'fetched_at' => $source->fetched_at?->toIso8601String(), 'trust' => $trust['level'], 'items_auto' => $auto, 'items_proposed' => $prop];
            JobRunRecorder::step($run, 'source', $url, 'completed', ['url' => $url, 'domain' => $domain, 'source_id' => $source->id, 'trust' => $trust['level'], 'trust_reason' => $trust['reason'], 'items' => $auto + $prop, 'auto' => $auto, 'proposed' => $prop, 'fetched_at' => $source->fetched_at?->toIso8601String()],
                $resolved['label'], (int) ($log['tokens'] ?? 0), (float) ($log['cost_vnd'] ?? 0), isset($log['error']) ? ErrorMasker::short((string) $log['error'], 200) : null);
            if (($log['stopped'] ?? null) === 'error') {
                $out['stopped_reason'] = 'ai_error';
                break;
            }
        }
        JobRunRecorder::finish($run, $out['stopped_reason'] === 'emergency_stop' ? 'blocked' : (($out['stopped_reason'] === 'ai_error' && $out['imported'] === 0) ? 'failed' : 'ready_for_review'),
            ['source_ids' => array_values(array_filter(array_column($out['pages'], 'source_id'))), 'item_ids' => array_merge($out['auto_item_ids'], $out['proposed_item_ids']), 'auto_approved' => $out['items_auto'], 'proposed' => $out['items_proposed']],
            $out['stopped_reason'] === 'budget' ? 'Chạm trần chi phí.' : null);
        $out['run_id'] = $run?->id;

        return $out;
    }
}
