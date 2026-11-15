<?php

namespace App\Services\Planning;

use App\Filament\Support\Planning\DuplicateKeywordException;
use App\Filament\Support\Planning\PlannedArticleCreator;
use App\Models\Article;
use App\Models\Campaign;
use App\Models\Keyword;
use App\Models\User;
use App\Models\Website;
use App\Services\Keywords\KeywordResearchService;
use App\Services\Keywords\Serp\SerpClusterer;
use App\Services\Keywords\Serp\SerpInsights;
use App\Services\Keywords\Serp\SerpUnavailable;
use App\Services\Keywords\Trends\SuggestionHistory;
use App\Services\Keywords\Trends\TrendSignals;
use App\Services\Knowledge\KnowledgeRetriever;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Đề xuất CHIẾN DỊCH (học seo-plan/seo-cluster): một bài trụ + các bài vệ tinh quanh một chủ đề, mỗi gợi ý có nhãn nguồn, LÝ DO (số liệu thật) và
 * "NẾU SAI THÌ THẤY GÌ". propose() chỉ tính, không ghi gì. create() chỉ chạy khi người duyệt (approver trở lên) bấm tạo: Campaign ở trạng thái
 * TẠM DỪNG (không tự chạy) + các bài "Đã lên kế hoạch". Không có số liệu lượt tìm/SERP thì nói rõ "chưa có", không điền số giả.
 *
 * Nhãn nguồn: keyword (kho), competitor, gsc (Search Console thật), serp (Mọi người cũng hỏi / tìm kiếm liên quan), autocomplete, ai (từ khóa kho do AI đề xuất).
 * Điểm 0–100 (chất lượng trước, xu hướng sau): lượt tìm ≤40, kho có nguồn ≤12, xu hướng +8/−4 (chỉ khi đã có bằng chứng chất lượng), thứ hạng trong danh sách gợi ý ≤10 (chỉ là thứ tự Google liệt kê, không phải lượt tìm) (so với ứng viên có số liệu cao nhất), Search Console ≤25, độ đa nguồn ≤20 (câu hỏi người dùng thật +12), đã có trong kho +5; lệch lĩnh vực trừ 30.
 */
final class CampaignProposer
{
    public const MAX_SATELLITES = 8;

    private const STOP = ['và', 'của', 'cho', 'là', 'các', 'những', 'một', 'có', 'để', 'trong', 'với', 'được', 'ở', 'tại', 'từ', 'như', 'thế', 'nào', 'bao', 'nhiêu', 'gì', 'không', 'hay', 'hoặc', 'ra', 'sao', 'thì', 'khi', 'the', 'a', 'of'];

    private const LABEL = ['keyword' => 'Kho từ khóa', 'competitor' => 'Khoảng trống đối thủ', 'gsc' => 'Search Console', 'serp' => 'Mọi người cũng hỏi / tìm kiếm liên quan', 'autocomplete' => 'Gợi ý tự động', 'ai' => 'AI đề xuất (kho)'];

    /**
     * @param  ?array<string,mixed>  $gsc  GscSignals::forWebsite()
     * @return array<string,mixed>
     */
    public function propose(Website $site, string $seed, ?array $gsc = null, ?SerpInsights $serp = null, ?float $budgetVnd = null): array
    {
        $seed = trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', strip_tags($seed)));
        if ($seed === '' || mb_strlen($seed) > 120 || preg_match(KeywordResearchService::INJECTION, $seed)) {
            throw new InvalidArgumentException('Chủ đề của chiến dịch không hợp lệ (trống, quá 120 ký tự hoặc giống chỉ dẫn).');
        }
        $seedKey = Keyword::keyFor($seed);
        $out = ['seed' => $seed, 'notes' => [], 'pillar' => null, 'satellites' => [], 'merged' => [], 'links' => [], 'providers' => [], 'cost_vnd' => 0.0, 'serp_checked' => false, 'gsc_used' => $gsc !== null];
        $taken = Article::query()->where('website_id', $site->id)->pluck('primary_keyword')->mapWithKeys(fn ($k) => [Keyword::keyFor((string) $k) => true])->all();

        /** @var array<string,array<string,mixed>> $pool */
        $pool = [];
        $add = function (string $text, string $type, string $label, array $extra = []) use (&$pool, $seedKey) {
            $text = self::normalizeText(trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', strip_tags($text))));
            $key = Keyword::keyFor($text);
            if ($text === '' || mb_strlen($text) > 190 || $key === $seedKey || preg_match(KeywordResearchService::INJECTION, $text)) {
                return;
            }
            $pool[$key] ??= ['keyword' => $text, 'sources' => [], 'volume' => null, 'volume_source' => null, 'gsc' => null, 'in_kho' => false, 'kho_id' => null, 'question' => false, 'rank_ratio' => null, 'rank_label' => null];
            $pool[$key]['sources'][$type] = $label;
            if (isset($extra['rank_ratio'])) { // thứ hạng trong danh sách gợi ý: giữ vị trí tốt nhất nếu xuất hiện ở nhiều danh sách
                if ($pool[$key]['rank_ratio'] === null || $extra['rank_ratio'] > $pool[$key]['rank_ratio']) {
                    $pool[$key]['rank_ratio'] = $extra['rank_ratio'];
                    $pool[$key]['rank_label'] = $extra['rank_label'];
                }
                unset($extra['rank_ratio'], $extra['rank_label']);
            }
            foreach ($extra as $k => $v) {
                $pool[$key][$k] = $v;
            }
        };

        // 1) Kho từ khóa của website
        $kho = Keyword::query()->where('website_id', $site->id)->where('status', 'new')->get();
        $pillarKho = $kho->first(fn (Keyword $k) => $k->keyword_key === $seedKey);
        foreach ($kho as $k) {
            if ($k->keyword_key !== $seedKey && $this->related($seed, $k->keyword)) {
                $type = match ($k->source) { 'gsc' => 'gsc', 'ai' => 'ai', 'competitor' => 'competitor', default => 'keyword' };
                $add($k->keyword, $type, self::LABEL[$type], ['volume' => $k->volume, 'volume_source' => $k->volume_source, 'in_kho' => true, 'kho_id' => $k->id]);
                if ($type !== 'keyword') {
                    $pool[$k->keyword_key]['sources']['keyword'] = self::LABEL['keyword'];
                }
            }
        }
        // 2) Search Console thật
        if ($gsc !== null) {
            foreach ($gsc['queries'] as $key => $g) {
                if ($key !== $seedKey && $g['impressions'] > 0 && $this->related($seed, $g['keyword'])) {
                    $add($g['keyword'], 'gsc', self::LABEL['gsc'], ['gsc' => $g + ['period' => $gsc['period']]]);
                }
            }
        } else {
            $out['notes'][] = 'Chưa dùng Search Console: website chưa nối Search Console thật nên không có cơ hội từ truy vấn thật.';
        }
        // 3) SERP: câu hỏi, tìm kiếm liên quan, gợi ý tự động của chính chủ đề
        $serp ??= SerpInsights::fromConfig();
        $serpData = null;
        try {
            $serpData = $serp->fetch($seed);
            $out['providers'] = $serpData['providers'];
            $out['cost_vnd'] += (float) $serpData['cost_vnd'];
            $ranked = fn (array $list, string $what) => array_map(fn ($q, $i) => [$q, ['rank_ratio' => 1 - $i / max(1, count($list)), 'rank_label' => $what.' #'.($i + 1).'/'.count($list)]], $list, array_keys($list));
            foreach ($ranked($serpData['paa'], 'Mọi người cũng hỏi') as [$q, $x]) {
                $add($q, 'serp', 'Mọi người cũng hỏi', ['question' => true] + $x);
            }
            foreach ($ranked($serpData['related'], 'Tìm kiếm liên quan') as [$q, $x]) {
                $add($q, 'serp', 'Tìm kiếm liên quan', $x);
            }
            foreach ($ranked($serpData['autocomplete'], 'Gợi ý tự động') as [$q, $x]) {
                $add($q, 'autocomplete', self::LABEL['autocomplete'], $x);
            }
            if ($serpData['errors'] !== []) {
                $out['notes'][] = 'Một nguồn SERP lỗi: '.implode(' | ', $serpData['errors']);
            }
        } catch (SerpUnavailable $e) {
            $out['notes'][] = 'Chưa có dữ liệu SERP nên không có câu hỏi và gợi ý tìm kiếm thật: '.$e->getMessage();
        }

        // Xu hướng MIỄN PHÍ: ghi lại gợi ý hôm nay để so với lần ghi cũ (tự làm, không cần khóa)
        $trends = new TrendSignals;
        $trendOn = TrendSignals::enabled();
        $history = null;
        if ($trendOn && $serpData !== null && $serpData['autocomplete'] !== []) {
            try {
                $hist = new SuggestionHistory;
                $history = $hist->movers($seed, $serpData['autocomplete']);
                $hist->record($seed, $serpData['autocomplete']);
            } catch (\Throwable $e) {
                report($e);
            }
            if ($history !== null && ! $history['ready']) {
                $out['notes'][] = 'Xu hướng từ lịch sử gợi ý: hôm nay đã được ghi lại, cần khoảng '.SuggestionHistory::MIN_AGE_DAYS.' ngày ghi nhận mới so sánh được.';
            }
        }

        foreach (array_keys($pool) as $key) {
            if (isset($taken[$key])) {
                unset($pool[$key]); // đã có bài
            }
        }

        // Chấm điểm
        $maxVol = (int) collect($pool)->max('volume');
        $profile = \App\Services\Brand\BrandProfileData::forWebsite($site->id);
        $retriever = new KnowledgeRetriever;
        $khoOn = $this->khoHasItems($retriever, $site);
        if (! $khoOn) {
            $out['notes'][] = 'Kho kiến thức của website đang trống nên chưa kiểm được chủ đề nào đã có nguồn đáng tin để viết.';
        }
        foreach ($pool as $key => &$c) {
            $parts = [];
            $parts['volume'] = $c['volume'] !== null && $maxVol > 0 ? (int) round(40 * $c['volume'] / $maxVol) : 0;
            $parts['gsc'] = $c['gsc'] !== null ? (int) min(GscSignals::MAX_POINTS, $c['gsc']['points']) : 0;
            $parts['sources'] = min(20, 8 * max(0, count($c['sources']) - 1) + ($c['question'] ? 12 : 0) + (count($c['sources']) === 1 && ! $c['question'] ? 4 : 0));
            $parts['kho'] = $c['in_kho'] ? 5 : 0;
            $c['kho_n'] = $khoOn ? $this->khoMatches($retriever, $site, $c['keyword'], $seed) : null; // số mục kiến thức đã duyệt khớp chủ đề (chất lượng: có nguồn để viết đúng)
            $parts['kho_nguon'] = $c['kho_n'] === null ? 0 : ($c['kho_n'] >= 2 ? 12 : ($c['kho_n'] === 1 ? 6 : 0));
            $parts['rank'] = $c['rank_ratio'] !== null ? (int) round(10 * $c['rank_ratio']) : 0; // thứ hạng gợi ý, KHÔNG phải lượt tìm
            $off = \App\Services\Brand\BrandProfileData::looksOffTopic($profile, $c['keyword']);
            $parts['off_topic'] = $off ? -30 : 0;
            $c['score'] = max(0, min(100, array_sum($parts)));
            $c['score_parts'] = $parts;
            $c['off_topic'] = $off;
        }
        unset($c);
        // Xu hướng chỉ cộng/trừ khi ứng viên đã có bằng chứng chất lượng (Search Console, câu hỏi thật, trong kho, kho có nguồn): chất lượng trước, độ hot sau.
        if ($trendOn) {
            $order = array_keys($pool);
            usort($order, fn ($a, $b) => $pool[$b]['score'] <=> $pool[$a]['score']);
            foreach ($order as $i => $key) {
                $c = $pool[$key];
                $t = $trends->forKeyword($c['keyword'], $history, $i < 10); // tra Wikipedia cho 10 ứng viên đầu để tiết kiệm lượt gọi
                $q = $c['gsc'] !== null || $c['question'] || $c['in_kho'] || ($c['kho_n'] ?? 0) > 0;
                $pts = $q ? ($t['direction'] === 'up' ? 8 : ($t['direction'] === 'down' ? -4 : 0)) : 0;
                $pool[$key]['trend'] = $t + ['scored' => $q];
                $pool[$key]['score_parts']['trend'] = $pts;
                $pool[$key]['score'] = max(0, min(100, $c['score'] + $pts));
            }
        }
        $ranked = array_values($pool);
        usort($ranked, fn ($a, $b) => [$a['off_topic'], $b['score'], $a['keyword']] <=> [$b['off_topic'], $a['score'], $b['keyword']]);
        $ranked = array_values(array_filter($ranked, fn ($c) => ! $c['off_topic']));
        $ranked = $this->groupFacets($ranked, $seed);
        $picked = array_slice($ranked, 0, self::MAX_SATELLITES);
        $rest = array_slice($ranked, self::MAX_SATELLITES);

        // Kiểm tra SERP: vệ tinh nào Google xếp gần như cùng kết quả với trụ (7+ URL chung) thì nên gộp vào bài trụ
        $groupOf = [];
        if ($picked !== [] && $serpData !== null && $serpData['organic'] !== []) {
            try {
                $res = (new SerpClusterer)->cluster(array_merge([$seed], array_column($picked, 'keyword')), $serp, $budgetVnd ?? (float) config('keywords.serp.cluster_budget_vnd', 40000), $seed);
                $out['cost_vnd'] += $res['cost_vnd'];
                $out['serp_checked'] = true;
                foreach ($res['groups'] as $g) {
                    foreach ($g['keywords'] as $k) {
                        $groupOf[Keyword::keyFor($k)] = $g;
                    }
                }
                $seedGroup = $groupOf[$seedKey] ?? null;
                if ($seedGroup !== null && $seedGroup['pillar'] === $seed) {
                    $mergeKeys = array_flip(array_map(fn ($m) => Keyword::keyFor($m['keyword']), $seedGroup['same_post']));
                    $shared = array_column($seedGroup['same_post'], 'shared', 'keyword');
                    $keep = [];
                    foreach ($picked as $c) {
                        if (isset($mergeKeys[Keyword::keyFor($c['keyword'])])) {
                            $out['merged'][] = ['keyword' => $c['keyword'], 'into' => $seed, 'shared' => (int) ($shared[$c['keyword']] ?? 0), 'why' => 'Top 10 trùng '.(int) ($shared[$c['keyword']] ?? 0).'/10 URL với bài trụ: Google coi là cùng một nhu cầu, viết riêng dễ tự cạnh tranh.'];
                        } else {
                            $keep[] = $c;
                        }
                    }
                    $picked = $keep;
                }
                if ($res['unprocessed'] !== []) {
                    $out['notes'][] = count($res['unprocessed']).' từ khóa chưa tra được SERP nên chưa kiểm tra trùng với bài trụ.';
                }
            } catch (SerpUnavailable $e) {
                $out['notes'][] = 'Không kiểm tra được trùng top 10: '.$e->getMessage();
            }
        } else {
            $out['notes'][] = 'Chưa kiểm tra trùng top 10 với bài trụ (cần DataForSEO để có kết quả tự nhiên).';
        }

        // Trụ
        $pillarSources = [['type' => 'keyword', 'label' => $pillarKho ? 'Kho từ khóa' : 'Chủ đề bạn chọn']];
        $pillarVol = $pillarKho?->volume;
        $out['pillar'] = [
            'keyword' => $seed, 'role' => 'pillar', 'secondary' => array_slice(array_values(array_unique(array_merge(array_column($out['merged'], 'keyword'), array_column($rest, 'keyword')))), 0, 5), // từ khóa bị gộp (cùng nhu cầu theo SERP) và ứng viên dư thành từ khóa phụ của bài trụ
            'why' => 'Bài trụ bao quát chủ đề «'.$seed.'», các vệ tinh dẫn về đây.'.($pillarVol !== null ? ' Lượt tìm '.number_format($pillarVol, 0, ',', '.').' ('.($pillarKho->volume_source ?? 'kho').').' : ' Chưa có số liệu lượt tìm cho chủ đề này.'),
            'if_wrong' => 'Nếu top 10 của «'.$seed.'» phần lớn là trang bán hàng hoặc khác ý định (không phải bài hướng dẫn), bài trụ nên đổi hướng hoặc chọn từ khóa trụ khác.',
            'sources' => $pillarSources, 'volume' => $pillarVol, 'score' => null,
            'trend' => $trendOn ? $trends->forKeyword($seed, $history, true) : null,
            'kho_matches' => $khoOn ? $this->khoMatches($retriever, $site, $seed, $seed) : null,
        ];
        $seedGroupKeys = isset($groupOf[$seedKey]) ? array_flip(array_map(fn ($k) => Keyword::keyFor($k), $groupOf[$seedKey]['keywords'])) : [];
        foreach ($picked as $c) {
            $in = isset($seedGroupKeys[Keyword::keyFor($c['keyword'])]);
            $sources = [];
            foreach ($c['sources'] as $type => $label) {
                $sources[] = ['type' => $type, 'label' => $label];
            }
            $out['satellites'][] = [
                'keyword' => $c['keyword'], 'role' => 'spoke', 'score' => $c['score'], 'score_parts' => $c['score_parts'], 'volume' => $c['volume'], 'sources' => $sources, 'kho_id' => $c['kho_id'],
                'secondary' => array_slice($c['also'] ?? [], 0, 4), 'facet' => $c['facet'] ?? null, 'serp_same_cluster' => $in, 'headline' => $this->headline($c), 'trend' => $c['trend'] ?? null, 'kho_matches' => $c['kho_n'] ?? null,
                'why' => $this->why($c, $seed, $out['serp_checked'], $in),
                'if_wrong' => $this->ifWrong($c, $seed, $out['serp_checked'], $in),
            ];
        }
        $out['links'] = $this->links($out['pillar']['keyword'], $out['satellites'], $groupOf);

        return $out;
    }

    /**
     * Người duyệt (approver trở lên) tạo chiến dịch. Chiến dịch ở trạng thái TẠM DỪNG, các bài ở "Đã lên kế hoạch"; chưa gọi AI viết.
     *
     * @param  array<string,mixed>  $proposal  kết quả propose() (dữ liệu từ giao diện được kiểm lại, chỉ nhận từ khóa hợp lệ trong đề xuất)
     * @param  ?list<string>  $pick  vệ tinh được chọn (null = tất cả); bài trụ luôn có
     * @return array{campaign_id:int, created:int, skipped:list<string>}
     */
    public function create(User $actor, Website $site, array $proposal, ?array $pick = null, ?string $name = null): array
    {
        if (! $actor->is_active || ! $actor->isApprover()) {
            throw new InvalidArgumentException('Chỉ trưởng nhóm duyệt bài trở lên mới tạo được chiến dịch.');
        }
        if ($site->status !== 'active') {
            throw new InvalidArgumentException('Website đích chưa ở trạng thái Hoạt động.');
        }
        $pillar = $proposal['pillar'] ?? null;
        if (! is_array($pillar) || trim((string) ($pillar['keyword'] ?? '')) === '') {
            throw new InvalidArgumentException('Đề xuất chiến dịch không có bài trụ.');
        }
        $want = $pick === null ? null : array_flip(array_map(fn ($k) => Keyword::keyFor((string) $k), $pick));
        $items = [['keyword' => (string) $pillar['keyword'], 'role' => 'pillar', 'secondary' => (array) ($pillar['secondary'] ?? []), 'score' => 100, 'kho_id' => null]];
        foreach ((array) ($proposal['satellites'] ?? []) as $s) {
            if (is_array($s) && ($want === null || isset($want[Keyword::keyFor((string) ($s['keyword'] ?? ''))]))) {
                $items[] = ['keyword' => (string) $s['keyword'], 'role' => 'spoke', 'secondary' => (array) ($s['secondary'] ?? []), 'score' => (int) ($s['score'] ?? 0), 'kho_id' => $s['kho_id'] ?? null];
            }
        }
        $seed = trim((string) ($proposal['seed'] ?? $pillar['keyword']));

        return DB::transaction(function () use ($actor, $site, $items, $seed, $name, $proposal) {
            $skipped = [];
            $articles = [];
            $creator = new PlannedArticleCreator;
            $profile = \App\Services\Brand\BrandProfileData::forWebsite($site->id);
            foreach ($items as $it) {
                $kw = trim((string) preg_replace('/\s+/u', ' ', $it['keyword']));
                if ($kw === '' || mb_strlen($kw) > 190 || preg_match(KeywordResearchService::INJECTION, $kw)) {
                    $skipped[] = "{$kw}: không hợp lệ";

                    continue;
                }
                if (\App\Services\Brand\BrandProfileData::looksOffTopic($profile, $kw)) {
                    $skipped[] = "{$kw}: có vẻ lệch lĩnh vực trong hồ sơ doanh nghiệp";

                    continue;
                }
                $stored = $it['kho_id'] ? Keyword::query()->where('website_id', $site->id)->find($it['kho_id']) : Keyword::query()->where('website_id', $site->id)->where('keyword_key', Keyword::keyFor($kw))->first();
                $secondary = array_slice(array_values(array_filter(array_map(fn ($s) => trim((string) $s), $it['secondary']), fn ($s) => $s !== '' && mb_strlen($s) <= 120 && ! preg_match(KeywordResearchService::INJECTION, $s))), 0, 6);
                try {
                    $a = $creator->create($actor, $site, $kw, $seed, 'informational', $secondary, $stored, 'plan');
                } catch (DuplicateKeywordException) {
                    $skipped[] = "{$kw}: đã có bài";

                    continue;
                }
                $articles[] = [$a, $it];
            }
            if ($articles === []) {
                throw new InvalidArgumentException('Không tạo được bài nào: '.implode('; ', $skipped));
            }
            $labels = [];
            foreach ((array) ($proposal['satellites'] ?? []) as $sat) {
                foreach (is_array($sat) ? (array) ($sat['sources'] ?? []) : [] as $src) {
                    $labels[] = mb_substr((string) ($src['label'] ?? ''), 0, 60);
                }
            }
            $labels = array_values(array_unique(array_filter($labels)));
            $c = Campaign::create([
                'website_id' => $site->id, 'created_by' => $actor->id, 'name' => mb_substr(trim((string) $name) !== '' ? trim((string) $name) : 'Chiến dịch: '.$seed, 0, 120),
                'status' => Campaign::STATUS_PAUSED, 'source' => 'plan', 'topic_cluster' => mb_substr($seed, 0, 120), 'target_article_count' => count($articles),
                'description' => 'Tạo từ đề xuất chiến dịch đã duyệt: 1 bài trụ + '.(count($articles) - 1).' bài vệ tinh. Nguồn gợi ý: '.($labels !== [] ? implode(', ', $labels) : 'chủ đề do người duyệt chọn').'.',
                'settings' => ['proposal' => ['seed' => $seed, 'links' => array_slice((array) ($proposal['links'] ?? []), 0, 60), 'notes' => array_slice((array) ($proposal['notes'] ?? []), 0, 10)]],
            ]);
            foreach ($articles as [$a, $it]) {
                $a->update(['campaign_id' => $c->id, 'priority' => max(0, min(100, $it['role'] === 'pillar' ? 100 : (int) $it['score']))]);
            }

            return ['campaign_id' => $c->id, 'created' => count($articles), 'skipped' => $skipped];
        });
    }

    /** Chữ viết tắt thường gặp trong gợi ý tìm kiếm, viết đủ để dùng làm từ khóa bài (chỉ thay cả từ). */
    public static function normalizeText(string $t): string
    {
        $map = ['ko' => 'không', 'đc' => 'được', 'hok' => 'không']; // chỉ các từ không thể nhầm (không đụng 'k', 'dc', 'j': vd. vitamin K, Washington DC)

        return trim(implode(' ', array_map(fn ($w) => $map[mb_strtolower($w)] ?? $w, preg_split('/\s+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [])));
    }

    /** Khía cạnh của ứng viên: hai từ đầu sau khi bỏ từ của chủ đề và từ nối đứng đầu; null nếu không còn gì. */
    private function facet(string $kw, array $seedWords): ?string
    {
        $w = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', Keyword::keyFor($kw), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn ($x) => ! in_array($x, $seedWords, true)));
        while ($w !== [] && in_array($w[0], self::STOP, true)) {
            array_shift($w);
        }

        return count($w) >= 2 ? $w[0].' '.$w[1] : ($w === [] ? null : $w[0]);
    }

    /**
     * Ứng viên cùng khía cạnh (vd. "thai kỳ": thai kỳ / thai kỳ có nguy hiểm không / thai kỳ nên ăn gì) là MỘT vệ tinh: giữ cụm ngắn nhất làm bài,
     * các cụm còn lại thành từ khóa phụ của bài đó (khớp bước dùng từ khóa phụ khi viết). Không bịa nhóm: chỉ gộp khi khía cạnh trùng hệt.
     *
     * @param  list<array<string,mixed>>  $ranked  đã xếp theo điểm
     * @return list<array<string,mixed>>
     */
    private function groupFacets(array $ranked, string $seed): array
    {
        $seedWords = $this->wordsOf($seed);
        $byFacet = [];
        foreach ($ranked as $i => $c) {
            if (($f = $this->facet($c['keyword'], $seedWords)) !== null) {
                $byFacet[$f][] = $i;
            }
        }
        $drop = [];
        foreach ($byFacet as $f => $idx) {
            if (count($idx) < 2) {
                continue;
            }
            usort($idx, fn ($a, $b) => [count($this->wordsOf($ranked[$a]['keyword'])), $ranked[$b]['score'], $a] <=> [count($this->wordsOf($ranked[$b]['keyword'])), $ranked[$a]['score'], $b]);
            $rep = array_shift($idx);
            $ranked[$rep]['facet'] = $f;
            $ranked[$rep]['also'] = array_map(fn ($j) => $ranked[$j]['keyword'], $idx);
            $ranked[$rep]['score'] = min(100, max($ranked[$rep]['score'], ...array_map(fn ($j) => $ranked[$j]['score'], $idx)));
            foreach ($idx as $j) {
                $drop[$j] = true;
            }
        }
        $out = array_values(array_filter($ranked, fn ($k) => ! isset($drop[$k]), ARRAY_FILTER_USE_KEY));
        usort($out, fn ($a, $b) => [$b['score'], $a['keyword']] <=> [$a['score'], $b['keyword']]);

        return $out;
    }

    /** @return list<string> */
    private function wordsOf(string $t): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', Keyword::keyFor($t), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Ứng viên liên quan chủ đề: có ít nhất nửa số từ chính của chủ đề (bỏ từ nối) hoặc chứa nguyên chủ đề. */
    private function related(string $seed, string $kw): bool
    {
        $s = $this->tokens($seed);
        $k = $this->tokens($kw);
        if ($s === []) {
            return str_contains(Keyword::keyFor($kw), Keyword::keyFor($seed));
        }

        return count(array_intersect($s, $k)) >= max(1, (int) ceil(count($s) / 2));
    }

    /** @return list<string> */
    private function tokens(string $t): array
    {
        $w = preg_split('/[^\p{L}\p{N}]+/u', Keyword::keyFor($t), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($w, fn ($x) => mb_strlen($x) >= 2 && ! in_array($x, self::STOP, true))));
    }

    private function khoHasItems(KnowledgeRetriever $r, Website $site): bool
    {
        try {
            return $r->searchable($site)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Số mục kiến thức đã duyệt khớp CHÍNH cụm này. Ngoài việc truy xuất theo từ khóa, mục phải chứa các từ riêng của cụm (ngoài từ của chủ đề):
     * kho có "đường huyết cao" thì chưa đủ để coi "đường huyết thấp" có nguồn.
     */
    private function khoMatches(KnowledgeRetriever $r, Website $site, string $kw, string $seed): int
    {
        try {
            $own = array_values(array_diff($this->wordsOf($kw), $this->wordsOf($seed), self::STOP));
            $own = array_values(array_filter($own, fn ($x) => mb_strlen($x) >= 2));
            $need = $own === [] ? 0 : (int) ceil(count($own) * 0.6);
            $n = 0;
            foreach ($r->search($site, $kw, 5) as $row) {
                $text = Keyword::keyFor((string) $row['title'].' '.(string) $row['body']);
                if (count(array_filter($own, fn ($w) => str_contains($text, $w))) >= $need) {
                    $n++;
                }
            }

            return $n;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Một dòng tóm tắt bằng số liệu có thật của ứng viên (cho thẻ gọn); thiếu số liệu thì nói thẳng. */
    private function headline(array $c): string
    {
        $h = [];
        if ($c['rank_label'] !== null) {
            $h[] = $c['rank_label'].' của Google';
        }
        if ($c['gsc'] !== null) {
            $h[] = 'GSC vị trí '.number_format($c['gsc']['position'], 1, ',', '.').' · '.number_format($c['gsc']['impressions'], 0, ',', '.').' hiển thị';
        }
        if ($c['volume'] !== null) {
            $h[] = number_format($c['volume'], 0, ',', '.').' lượt tìm/tháng';
        }
        if (($c['trend']['direction'] ?? 'unknown') === 'up') {
            $h[] = '↑ đang tăng';
        } elseif (($c['trend']['direction'] ?? 'unknown') === 'down') {
            $h[] = '↓ đang giảm';
        }
        if ($h === [] && $c['in_kho']) {
            $h[] = 'Có trong kho từ khóa';
        }

        return $h === [] ? 'Chưa có số liệu' : implode(' · ', $h);
    }

    private function why(array $c, string $seed, bool $serpChecked, bool $sameCluster): string
    {
        $w = [];
        if ($c['question']) {
            $w[] = 'người dùng hỏi câu này trên Google khi tìm «'.$seed.'» (Mọi người cũng hỏi)';
        }
        if (isset($c['sources']['autocomplete'])) {
            $w[] = 'là gợi ý tự động của Google quanh «'.$seed.'»';
        }
        if (isset($c['sources']['serp']) && ! $c['question']) {
            $w[] = 'nằm trong "Tìm kiếm liên quan" của «'.$seed.'»';
        }
        if ($c['gsc'] !== null) {
            $g = $c['gsc'];
            $w[] = 'Search Console ('.$g['period'].'): vị trí '.number_format($g['position'], 1, ',', '.').', '.number_format($g['impressions'], 0, ',', '.').' lượt hiển thị, '.number_format($g['clicks'], 0, ',', '.').' lượt nhấp'.($g['reasons'] !== [] ? ' ('.$g['reasons'][0].')' : '');
        }
        if ($c['in_kho']) {
            $w[] = 'đã có trong kho từ khóa'.($c['volume'] !== null ? ', lượt tìm '.number_format($c['volume'], 0, ',', '.').($c['volume_source'] ? ' ('.$c['volume_source'].')' : '') : ', chưa có số liệu lượt tìm');
        }
        if ($serpChecked) {
            $w[] = $sameCluster ? 'top 10 trùng 4–6 URL với bài trụ nên cùng cụm chủ đề' : 'top 10 trùng ít với bài trụ (dưới 4 URL) nên đứng riêng được';
        }
        if ($c['rank_label'] !== null) {
            $w[] = 'đứng '.mb_strtolower($c['rank_label']).' trong danh sách của Google (thứ hạng gợi ý, không phải lượt tìm)';
        }
        if (! empty($c['also'])) {
            $w[] = 'gộp thêm '.count($c['also']).' cụm cùng khía cạnh «'.$c['facet'].'» làm từ khóa phụ: '.implode('; ', array_slice($c['also'], 0, 4));
        }
        foreach (($c['trend']['evidence'] ?? []) as $e) {
            $w[] = mb_strtolower(mb_substr($e['text'], 0, 1)).mb_substr($e['text'], 1).($c['trend']['scored'] ? '' : ' (chưa cộng điểm vì chưa có bằng chứng chất lượng khác)');
        }
        if (($c['kho_n'] ?? null) !== null) {
            $w[] = $c['kho_n'] > 0 ? 'kho kiến thức có '.$c['kho_n'].' mục đã duyệt khớp để viết' : 'kho kiến thức CHƯA có nguồn khớp: nên bổ sung nguồn đáng tin trước khi viết';
        }
        if ($c['volume'] === null && $c['gsc'] === null) {
            $w[] = 'chưa có số liệu lượt tìm nên điểm chỉ dựa vào nguồn gợi ý';
        }

        return ucfirst(implode('; ', $w)).'.';
    }

    private function ifWrong(array $c, string $seed, bool $serpChecked, bool $sameCluster): string
    {
        if ($c['gsc'] !== null) {
            return 'Nếu sau 4–6 tuần xuất bản mà vị trí và lượt nhấp của truy vấn này trong Search Console không cải thiện, hãy xem lại tiêu đề, mở bài và liên kết nội bộ thay vì viết thêm bài mới.';
        }
        if ($c['question']) {
            return 'Nếu bài trụ đã trả lời đủ câu hỏi này bằng một mục riêng, hoặc top 10 của câu hỏi trùng 7+ URL với bài trụ, thì gộp vào bài trụ, không viết bài riêng.';
        }
        if (! $serpChecked) {
            return 'Chưa kiểm tra trùng top 10: nếu tìm câu này thấy gần như cùng kết quả với bài trụ thì nên gộp vào bài trụ.';
        }

        return $sameCluster ? 'Nếu bài này đọc lại gần như lặp bài trụ thì viết thành một mục trong bài trụ.' : 'Nếu top 10 của câu này hóa ra gần như cùng kết quả với bài trụ thì gộp vào bài trụ.';
    }

    /**
     * Liên kết nội bộ (seo-cluster): vệ tinh ↔ trụ bắt buộc hai chiều; vệ tinh cùng cụm SERP liên kết với nhau (tối đa 2 mỗi bài).
     *
     * @return list<array{from:string,to:string,type:string}>
     */
    private function links(string $pillar, array $sats, array $groupOf): array
    {
        $links = [];
        foreach ($sats as $s) {
            $links[] = ['from' => $s['keyword'], 'to' => $pillar, 'type' => 'bat_buoc'];
            $links[] = ['from' => $pillar, 'to' => $s['keyword'], 'type' => 'bat_buoc'];
        }
        $same = array_values(array_filter($sats, fn ($s) => $s['serp_same_cluster']));
        foreach ($same as $i => $s) {
            foreach (array_slice($same, $i + 1, 2) as $t) {
                $links[] = ['from' => $s['keyword'], 'to' => $t['keyword'], 'type' => 'cung_cum'];
                $links[] = ['from' => $t['keyword'], 'to' => $s['keyword'], 'type' => 'cung_cum'];
            }
        }

        return $links;
    }
}
