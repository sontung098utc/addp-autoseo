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
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Đề xuất CHIẾN DỊCH (học seo-plan/seo-cluster): một bài trụ + các bài vệ tinh quanh một chủ đề, mỗi gợi ý có nhãn nguồn, LÝ DO (số liệu thật) và
 * "NẾU SAI THÌ THẤY GÌ". propose() chỉ tính, không ghi gì. create() chỉ chạy khi người duyệt (approver trở lên) bấm tạo: Campaign ở trạng thái
 * TẠM DỪNG (không tự chạy) + các bài "Đã lên kế hoạch". Không có số liệu lượt tìm/SERP thì nói rõ "chưa có", không điền số giả.
 *
 * Nhãn nguồn: keyword (kho), competitor, gsc (Search Console thật), serp (Mọi người cũng hỏi / tìm kiếm liên quan), autocomplete, ai (từ khóa kho do AI đề xuất).
 * Điểm 0–100: lượt tìm ≤40 (so với ứng viên có số liệu cao nhất), Search Console ≤25, độ đa nguồn ≤20 (câu hỏi người dùng thật +12), đã có trong kho +5; lệch lĩnh vực trừ 30.
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
            $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+|\s+/u', ' ', strip_tags($text)));
            $key = Keyword::keyFor($text);
            if ($text === '' || mb_strlen($text) > 190 || $key === $seedKey || preg_match(KeywordResearchService::INJECTION, $text)) {
                return;
            }
            $pool[$key] ??= ['keyword' => $text, 'sources' => [], 'volume' => null, 'volume_source' => null, 'gsc' => null, 'in_kho' => false, 'kho_id' => null, 'question' => false];
            $pool[$key]['sources'][$type] = $label;
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
            foreach ($serpData['paa'] as $q) {
                $add($q, 'serp', 'Mọi người cũng hỏi', ['question' => true]);
            }
            foreach ($serpData['related'] as $q) {
                $add($q, 'serp', 'Tìm kiếm liên quan');
            }
            foreach ($serpData['autocomplete'] as $q) {
                $add($q, 'autocomplete', self::LABEL['autocomplete']);
            }
            if ($serpData['errors'] !== []) {
                $out['notes'][] = 'Một nguồn SERP lỗi: '.implode(' | ', $serpData['errors']);
            }
        } catch (SerpUnavailable $e) {
            $out['notes'][] = 'Chưa có dữ liệu SERP nên không có câu hỏi và gợi ý tìm kiếm thật: '.$e->getMessage();
        }

        foreach (array_keys($pool) as $key) {
            if (isset($taken[$key])) {
                unset($pool[$key]); // đã có bài
            }
        }

        // Chấm điểm
        $maxVol = (int) collect($pool)->max('volume');
        $profile = \App\Services\Brand\BrandProfileData::forWebsite($site->id);
        foreach ($pool as $key => &$c) {
            $parts = [];
            $parts['volume'] = $c['volume'] !== null && $maxVol > 0 ? (int) round(40 * $c['volume'] / $maxVol) : 0;
            $parts['gsc'] = $c['gsc'] !== null ? (int) min(GscSignals::MAX_POINTS, $c['gsc']['points']) : 0;
            $parts['sources'] = min(20, 8 * max(0, count($c['sources']) - 1) + ($c['question'] ? 12 : 0) + (count($c['sources']) === 1 && ! $c['question'] ? 4 : 0));
            $parts['kho'] = $c['in_kho'] ? 5 : 0;
            $off = \App\Services\Brand\BrandProfileData::looksOffTopic($profile, $c['keyword']);
            $parts['off_topic'] = $off ? -30 : 0;
            $c['score'] = max(0, min(100, array_sum($parts)));
            $c['score_parts'] = $parts;
            $c['off_topic'] = $off;
        }
        unset($c);
        $ranked = array_values($pool);
        usort($ranked, fn ($a, $b) => [$a['off_topic'], $b['score'], $a['keyword']] <=> [$b['off_topic'], $a['score'], $b['keyword']]);
        $ranked = array_values(array_filter($ranked, fn ($c) => ! $c['off_topic']));
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
                'secondary' => [], 'serp_same_cluster' => $in,
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
