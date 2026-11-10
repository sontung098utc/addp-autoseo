<?php

namespace App\Services\Knowledge\Build;

use App\Contracts\StructuredAiInterface;
use App\Filament\Support\Schedule\ErrorMasker;
use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Run\JobRunRecorder;
use App\Services\Agent\Settings\AgentAi;
use App\Services\Crawler\SitemapCrawlerService;
use App\Services\Knowledge\KnowledgeAiIngest;
use App\Services\Knowledge\KnowledgeConflicts;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeError;
use App\Services\Knowledge\KnowledgeGaps;
use App\Services\Knowledge\KnowledgeReview;
use App\Services\Publishing\EmergencyStopService;
use Illuminate\Support\Str;

/**
 * G2: xây kho từ website công ty (loại việc brain_build). Chọn trang từ sitemap (hoặc base_url + URL người dán) → tải an toàn →
 * trích chữ → bỏ trang không đổi → KnowledgeAiIngest rút mục đề xuất → RiskTiering (luật cố định) →
 * tầng A tự nhận (nhãn "auto", hoàn tác được), tầng B và C giữ 'proposed' chờ người duyệt.
 *
 * An toàn (AGENTS.md §1.3): AI chỉ ĐỀ XUẤT; mục chỉ vào kho khi có đoạn nguyên văn từ trang (KnowledgeAiIngest kiểm); tầng do luật quyết;
 * nội dung trang là DỮ LIỆU (KnowledgeAiIngest đặt trong khung có nonce); chỉ tải tên miền của chính website.
 * Trần chi phí: mỗi trang theo KnowledgeAiIngest::budgetVnd() (mặc định 5.000 ₫), mỗi lượt min(150.000 ₫, ngân sách chính sách).
 * Ghi MỘT phiên agent_runs job_type=brain_build, mỗi trang một bước. Chạy đồng bộ; chưa có chạy nền.
 */
final class BrainBuildService
{
    public const DEFAULT_PAGES = 30;

    public const HARD_PAGES = 50;

    public const MIN_TEXT_CHARS = 200;

    /** Điểm ưu tiên theo đường dẫn: nhỏ đi trước. */
    private const PRIORITY = [
        0 => ['gioi-thieu', 've-chung-toi', 'about'],
        1 => ['dich-vu', 'san-pham', 'product', 'service', 'giai-phap'],
        2 => ['chinh-sach', 'dieu-khoan', 'bao-hanh', 'van-chuyen', 'doi-tra', 'policy', 'terms'],
        3 => ['hoi-dap', 'cau-hoi', 'faq', 'huong-dan'],
        4 => ['lien-he', 'contact'],
    ];

    private SafePageFetcher $fetcher;

    private RiskTiering $tiering;

    private KnowledgeAiIngest $ingest;

    private KnowledgeConflicts $conflicts;

    /** Trần chi phí cả lượt do người gọi đặt (nhỏ hơn thì thắng) và từ khóa ưu tiên trang; chỉ đặt trong run(). */
    private ?float $capOverride = null;

    /** @var list<string> */
    private array $keywordTokens = [];

    public function __construct(
        ?SafePageFetcher $fetcher = null,
        private ?SitemapCrawlerService $sitemap = null,
        ?KnowledgeAiIngest $ingest = null,
        ?RiskTiering $tiering = null,
        private ?EmergencyStopService $stop = null,
        private readonly ?StructuredAiInterface $ai = null,
        private readonly ?float $cap = null,
        private readonly string $label = 'AI',
        private readonly bool $demo = false,
    ) {
        $this->fetcher = $fetcher ?? new SafePageFetcher;
        $this->tiering = $tiering ?? new RiskTiering;
        $this->ingest = $ingest ?? new KnowledgeAiIngest;
        $this->conflicts = new KnowledgeConflicts;
    }

    public static function clampPages(?int $n): int
    {
        return max(1, min(self::HARD_PAGES, $n ?? self::DEFAULT_PAGES));
    }

    /** Trần chi phí cả lượt (VND): nhỏ hơn giữa cấu hình (150.000) và ngân sách phiên của AgentAi::policy(). */
    public static function totalBudgetVnd(): float
    {
        return min((float) config('knowledge.build_total_budget_vnd', 150000), AgentAi::policy()->budgetVnd);
    }

    /** @return array{ok: bool, reason: ?string, label: ?string, demo: bool} */
    public function availability(): array
    {
        if ($this->ai !== null) {
            return ['ok' => true, 'reason' => null, 'label' => $this->label, 'demo' => $this->demo];
        }

        return $this->ingest->availability();
    }

    /**
     * @param  array{max_pages?: int, urls?: list<string>|string, as_system?: bool, budget_cap_vnd?: float, keyword?: string, purpose?: string}  $options
     *                                                                                                                                                     as_system: do hệ thống tự chạy (đội AI tự chuẩn bị kiến thức), bỏ kiểm quyền người dùng; mọi luật còn lại (tầng, tự nhận, tên miền) giữ nguyên.
     *                                                                                                                                                     budget_cap_vnd: trần riêng cho lượt này. keyword: ưu tiên trang có đường dẫn chứa từ khóa.
     * @return array{run_id: ?int, pages_planned: int, pages_read: int, pages_skipped: int, pages_failed: int, items_auto: int, items_proposed: int, cost_vnd: float,
     *               stopped_reason: ?string, message: ?string, source_ids: list<int>, auto_item_ids: list<int>, proposed_item_ids: list<int>, rejected_urls: list<string>, label: ?string, demo: bool,
     *               brand_profile: array{applied: bool, fields: list<string>, reason: ?string}}
     *
     * @throws KnowledgeError không có quyền, website không hoạt động, dừng khẩn cấp, chưa kết nối AI
     */
    public function run(Website $site, ?User $by, array $options = []): array
    {
        if (! ($options['as_system'] ?? false) && ! KnowledgeReview::canManage($by)) {
            throw new KnowledgeError('Bạn chưa có quyền xây kho. Cần quyền người duyệt trở lên.');
        }
        $this->capOverride = isset($options['budget_cap_vnd']) ? max(0.0, (float) $options['budget_cap_vnd']) : null;
        $this->keywordTokens = array_values(array_filter(preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower((string) ($options['keyword'] ?? '')))) ?: [], fn ($w) => strlen($w) >= 3));
        $maxPages = self::clampPages(isset($options['max_pages']) ? (int) $options['max_pages'] : null);
        $allowed = SafePageFetcher::allowedHostsFor($site);
        $pasted = $this->pastedUrls($options['urls'] ?? [], $allowed);
        $refs = ['max_pages' => $maxPages, 'pasted_urls' => count($pasted['ok']), 'rejected_urls' => count($pasted['rejected']), 'budget_vnd' => $this->budget()] + (isset($options['purpose']) ? ['purpose' => (string) $options['purpose']] : []);

        // Cổng: website hoạt động, không dừng khẩn cấp, có AI. Chưa qua cổng thì chưa tốn gì và không tải gì.
        $this->stop ??= app(EmergencyStopService::class);
        $why = null;
        if ($site->status !== 'active') {
            $why = 'Website không ở trạng thái hoạt động.';
        } elseif ($this->stop->isStopped($site->id)) {
            $why = 'Đang dừng khẩn cấp: không tải trang nào.';
        } elseif ($allowed === []) {
            $why = 'Website chưa có địa chỉ (base_url) hợp lệ.';
        }
        $resolved = null;
        if ($why === null) {
            try {
                $resolved = $this->ai !== null
                    ? ['ai' => $this->ai, 'cap' => $this->cap, 'label' => $this->label, 'demo' => $this->demo]
                    : $this->ingest->resolve();
            } catch (KnowledgeError $e) {
                $why = $e->getMessage();
            }
        }
        if ($why !== null) {
            $run = JobRunRecorder::start('brain_build', $site, $refs, $by);
            JobRunRecorder::step($run, 'guard', 'Chưa chạy', 'blocked', [], null, 0, 0, $why.' Chưa tải trang nào nên không tốn chi phí.');
            JobRunRecorder::finish($run, 'blocked', null, $why);

            throw new KnowledgeError($why);
        }

        $run = JobRunRecorder::start('brain_build', $site, $refs, $by, 'manual', $resolved['demo'] ? 'mock' : 'real');
        $brain = $site->brains()->first();
        try {
            $out = $this->work($site, $by, $run, $resolved, $brain, $allowed, $maxPages, $pasted);
        } catch (\Throwable $e) {
            JobRunRecorder::finish($run, 'failed', null, 'Lỗi: '.class_basename($e));
            throw $e;
        }
        $out['brand_profile'] = $this->draftBrand($site, $by, $run, $resolved, $out);
        $outputRef = ['source_ids' => $out['source_ids'], 'item_ids' => array_merge($out['auto_item_ids'], $out['proposed_item_ids']), 'auto_approved' => $out['items_auto'], 'proposed' => $out['items_proposed']];
        $status = $out['stopped_reason'] === 'emergency_stop' ? 'blocked' : (($out['stopped_reason'] === 'ai_error' && $out['pages_read'] === 0) ? 'failed' : 'ready_for_review');
        JobRunRecorder::finish($run, $status, $outputRef, $out['message']);
        $out['run_id'] = $run?->id;

        return $out;
    }

    /**
     * Cuối lượt: tự điền nháp Hồ sơ doanh nghiệp (chỉ trường trống, có căn cứ trang, hoàn tác được). Lỗi ở đây không làm hỏng lượt xây.
     *
     * @return array{applied: bool, fields: list<string>, reason: ?string}
     */
    private function draftBrand(Website $site, ?User $by, $run, array $resolved, array &$out): array
    {
        if ($out['pages_read'] === 0 || $out['stopped_reason'] !== null) {
            return ['applied' => false, 'fields' => [], 'reason' => 'Lượt này không đọc trang mới hoặc đã dừng giữa chừng (trần chi phí, lỗi AI, dừng khẩn cấp) nên chưa tự điền hồ sơ doanh nghiệp.'];
        }
        try {
            $drafter = new BrandProfileDrafter($resolved['ai'], $resolved['label'], $resolved['demo'], $this->fetcher);
            $res = $drafter->draftFor($site, $by);
            $out['cost_vnd'] = round($out['cost_vnd'] + $drafter->lastCost, 2);
        } catch (\Throwable $e) {
            $res = ['applied' => false, 'fields' => [], 'reason' => 'Lỗi khi tự điền hồ sơ doanh nghiệp: '.class_basename($e)];
        }
        JobRunRecorder::step($run, 'brand', 'Tự điền hồ sơ doanh nghiệp', $res['applied'] ? 'completed' : 'skipped', ['applied' => $res['applied'], 'fields' => $res['fields']], $resolved['label'], 0, 0,
            $res['applied'] ? 'Đã tự điền: '.implode(', ', $res['fields']).'. Hoàn tác được trên trang Xây kho.' : $res['reason']);

        return $res;
    }

    /** Hoàn tác một mục đã tự nhận: về 'proposed', bỏ nhãn tự nhận. Chỉ người duyệt trở lên. */
    public function undoAuto(KnowledgeItem $item, ?User $by): KnowledgeItem
    {
        if (! KnowledgeReview::canManage($by)) {
            throw new KnowledgeError('Bạn chưa có quyền hoàn tác.');
        }
        if ($item->review_mode !== 'auto' || $item->status !== 'approved') {
            throw new KnowledgeError('Mục này không phải mục tự nhận đang có hiệu lực.');
        }
        $item->update(['status' => 'proposed', 'review_mode' => null, 'approved_by' => null, 'approved_at' => null]);
        $this->note($item, 'proposed', $by, 'Hoàn tác tự nhận');

        return $item;
    }

    private function budget(): float
    {
        return $this->capOverride !== null ? min($this->capOverride, self::totalBudgetVnd()) : self::totalBudgetVnd();
    }

    /** @param ?array{ai: StructuredAiInterface, cap: ?float, label: string, demo: bool} $resolved */
    private function work(Website $site, ?User $by, $run, array $resolved, ?Brain $brain, array $allowed, int $maxPages, array $pasted): array
    {
        $urls = $this->selectPages($site, $pasted['ok'], $maxPages);
        $total = count($urls);
        $budget = $this->budget();
        $r = ['run_id' => null, 'pages_planned' => $total, 'pages_read' => 0, 'pages_skipped' => 0, 'pages_failed' => 0, 'items_auto' => 0, 'items_proposed' => 0, 'cost_vnd' => 0.0,
            'stopped_reason' => null, 'message' => null, 'source_ids' => [], 'auto_item_ids' => [], 'proposed_item_ids' => [], 'rejected_urls' => $pasted['rejected'], 'label' => $resolved['label'], 'demo' => $resolved['demo']];
        JobRunRecorder::step($run, 'select', 'Chọn trang', 'completed', ['pages' => $total, 'from_sitemap' => count($urls) - count($pasted['ok']), 'pasted' => count($pasted['ok']), 'rejected_urls' => $pasted['rejected']], null, 0, 0, 'Đã chọn '.$total.' trang trên '.implode(', ', $allowed).'.');

        $done = 0;
        foreach ($urls as $url) {
            $stage = 'page'; // mỗi trang một bước (cùng mã chặng, tiêu đề là URL)
            if ($this->stop->isStopped($site->id)) {
                $r['stopped_reason'] = 'emergency_stop';
                $r['message'] = 'Dừng khẩn cấp: đã đọc '.$done.'/'.$total.' trang.';
                JobRunRecorder::step($run, 'halt', 'Dừng khẩn cấp', 'blocked', ['done' => $done, 'total' => $total], null, 0, 0, $r['message']);
                break;
            }
            if ($r['cost_vnd'] >= $budget) {
                $r['stopped_reason'] = 'budget';
                $r['message'] = 'Chạm trần chi phí '.number_format($budget, 0, ',', '.').' ₫: đã đọc '.$done.'/'.$total.' trang.';
                JobRunRecorder::step($run, 'halt', 'Chạm trần chi phí', 'blocked', ['done' => $done, 'total' => $total, 'cost_vnd' => $r['cost_vnd']], null, 0, 0, $r['message']);
                break;
            }
            $done++;
            $page = $this->fetcher->fetch($url, $allowed);
            if (! $page['ok']) {
                $r['pages_failed']++;
                JobRunRecorder::step($run, $stage, $url, 'failed', ['url' => $url, 'error' => $page['error']], null, 0, 0, $page['message']);

                continue;
            }
            $ex = (new ReadableTextExtractor)->extract($page['html']);
            if (mb_strlen($ex['text']) < self::MIN_TEXT_CHARS) {
                $r['pages_skipped']++;
                JobRunRecorder::step($run, $stage, $url, 'skipped', ['url' => $url, 'reason' => 'too_short', 'chars' => mb_strlen($ex['text'])], null, 0, 0, 'Trang có quá ít chữ để rút kiến thức.');

                continue;
            }
            $hash = KnowledgeDedupe::hash('', $ex['text']);
            if (KnowledgeSource::query()->where('website_id', $site->id)->where('kind', 'url')->where('content_hash', $hash)->exists()) {
                $r['pages_skipped']++;
                JobRunRecorder::step($run, $stage, $url, 'skipped', ['url' => $url, 'reason' => 'unchanged'], null, 0, 0, 'Trang không đổi so với lần đọc trước.');

                continue;
            }
            $source = KnowledgeSource::create([
                'brain_id' => $brain?->id, 'website_id' => $site->id, 'kind' => 'url', 'title' => mb_substr($ex['title'] !== '' ? $ex['title'] : $url, 0, 200), 'url' => mb_substr($url, 0, 2048),
                'raw_text' => $ex['text'], 'content_hash' => $hash, 'status' => 'new', 'created_by' => $by?->id,
                'origin' => 'own_site', 'fetched_at' => now(), 'final_url' => mb_substr((string) $page['final_url'], 0, 2048), 'http_status' => $page['http_status'],
            ]);
            $r['source_ids'][] = $source->id;
            $r['pages_read']++;
            try {
                $log = $this->ingest->run($source, $by, $resolved['ai'], $resolved['cap'], $resolved['label'], $resolved['demo']);
            } catch (KnowledgeError $e) {
                $log = ['cost_vnd' => 0.0, 'stopped' => 'error', 'error' => $e->getMessage()];
            }
            $r['cost_vnd'] = round($r['cost_vnd'] + (float) ($log['cost_vnd'] ?? 0), 2);

            $tiers = ['A' => 0, 'B' => 0, 'C' => 0];
            $auto = 0;
            foreach (KnowledgeItem::query()->where('source_id', $source->id)->where('status', 'proposed')->orderBy('id')->get() as $item) {
                $t = $this->tiering->tier($item, $brain, true);
                $tiers[$t['tier']]++;
                $item->update(['risk_tier' => $t['tier']]);
                if ($t['tier'] === RiskTiering::A && $this->autoApprove($item, $by)) {
                    $auto++;
                    $r['auto_item_ids'][] = $item->id;
                } else {
                    $r['proposed_item_ids'][] = $item->id;
                }
            }
            $r['items_auto'] += $auto;
            $n = array_sum($tiers);
            $r['items_proposed'] += $n - $auto;
            JobRunRecorder::step($run, $stage, $url, 'completed', ['url' => $url, 'source_id' => $source->id, 'items' => $n, 'tiers' => $tiers, 'auto' => $auto, 'http_status' => $page['http_status']],
                $resolved['label'], (int) ($log['tokens'] ?? 0), (float) ($log['cost_vnd'] ?? 0), isset($log['error']) ? ErrorMasker::short((string) $log['error'], 200) : null);
            if (($log['stopped'] ?? null) === 'error') {
                $r['stopped_reason'] = 'ai_error';
                $r['message'] = 'AI báo lỗi nên dừng: đã đọc '.$done.'/'.$total.' trang.';

                break;
            }
        }

        return $r;
    }

    /** @return list<string> */
    private function selectPages(Website $site, array $pasted, int $max): array
    {
        $base = rtrim((string) $site->base_url, '/');
        $found = [];
        try {
            $this->sitemap ??= app(SitemapCrawlerService::class);
            foreach ($this->sitemap->discoverUrls($site, null, 500) as $row) {
                $u = is_array($row) ? ($row['url'] ?? null) : $row;
                if (is_string($u) && $u !== '' && SafePageFetcher::hostAllowed((string) parse_url($u, PHP_URL_HOST), SafePageFetcher::allowedHostsFor($site))) {
                    $found[] = $u;
                }
            }
        } catch (\Throwable) {
            $found = []; // không có sitemap: dùng trang chủ
        }
        if ($found === []) {
            $found = [$base.'/'];
        }
        $found = array_values(array_unique($found));
        $scored = [];
        foreach ($found as $idx => $u) {
            $scored[] = [$this->score($u), $idx, $u];
        }
        usort($scored, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $ordered = array_map(fn ($x) => $x[2], $scored);

        return array_slice(array_values(array_unique(array_merge($pasted, $ordered))), 0, $max);
    }

    private function score(string $url): int
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '' || $path === '/') {
            return 5;
        }
        $p = Str::ascii(mb_strtolower($path));
        foreach ($this->keywordTokens as $w) {
            if (str_contains($p, $w)) {
                return -1; // trang trùng từ khóa của bài đi trước
            }
        }
        foreach (self::PRIORITY as $score => $needles) {
            foreach ($needles as $n) {
                if (str_contains($p, $n)) {
                    return $score;
                }
            }
        }

        return 9;
    }

    /** @return array{ok: list<string>, rejected: list<string>} */
    private function pastedUrls(array|string $urls, array $allowed): array
    {
        $list = is_string($urls) ? (preg_split('/\R/u', $urls) ?: []) : $urls;
        $ok = $rejected = [];
        foreach ($list as $u) {
            $u = trim((string) $u);
            if ($u === '') {
                continue;
            }
            if (! preg_match('#^https?://#i', $u) || mb_strlen($u) > 2048 || ! SafePageFetcher::hostAllowed((string) parse_url($u, PHP_URL_HOST), $allowed)) {
                $rejected[] = mb_substr($u, 0, 200);

                continue;
            }
            $ok[] = $u;
        }

        return ['ok' => array_values(array_unique($ok)), 'rejected' => $rejected];
    }

    private function autoApprove(KnowledgeItem $item, ?User $by): bool
    {
        if ($this->conflicts->has($item)) {
            return false; // mâu thuẫn với mục khác: để người chọn
        }
        $item->update(['status' => 'approved', 'review_mode' => 'auto', 'approved_by' => $by?->id, 'approved_at' => now()]);
        $this->note($item, 'approved', $by, 'Tự nhận (tầng A)');
        try {
            (new KnowledgeGaps)->fillFrom($item);
        } catch (\Throwable) {
            // vòng học không được làm hỏng việc xây kho
        }

        return true;
    }

    private function note(KnowledgeItem $item, string $status, ?User $by, string $note): void
    {
        $row = $item->versions()->where('version', $item->version)->first();
        $data = ['title' => $item->title, 'body' => $item->body, 'entity' => $item->entity, 'field' => $item->field, 'value' => $item->value, 'value_unit' => $item->value_unit, 'status' => $status, 'changed_by' => $by?->id];
        if ($row) {
            $row->update($data + ['note' => mb_substr(trim(($row->note ? $row->note.' → ' : '').$note), 0, 200)]);
        } else {
            $item->versions()->create($data + ['version' => $item->version, 'note' => $note]);
        }
    }
}
