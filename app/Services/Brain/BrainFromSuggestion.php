<?php

namespace App\Services\Brain;

use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Brain;
use App\Models\KnowledgeGap;
use App\Models\KnowledgeTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\Readiness\BrainAutoAttach;
use App\Services\Knowledge\AiJson;
use App\Services\Knowledge\Build\BrainBuildService;
use App\Services\Knowledge\Discover\SourceDiscoveryService;
use App\Services\Knowledge\Discover\SourceTrust;
use App\Services\Knowledge\Suggest\KnowledgeSuggestions;

/**
 * Lập Trí tuệ từ GỢI Ý của điều phối ("nên lập Trí tuệ cho lĩnh vực X") theo đúng định hướng của bài đã gợi ý, không phải trang trống:
 *  1. tạo Trí tuệ từ mẫu hợp nhất, gắn vào website;
 *  2. ghi ĐỊNH HƯỚNG xây kho lấy từ bài (từ khóa, góc tiếp cận, các câu hỏi nền, các mục dàn ý → chủ đề của kho);
 *  3. NẠP SẴN các nguồn tin cậy đã tìm được khi viết bài đó (mục từ nguồn tin cậy có đoạn nguyên văn được tự nhận), còn nguồn thường được ghi vào
 *     Căn cứ chuẩn mực ở dạng "chưa xác nhận" để người dùng xét.
 * Nạp nguồn lỗi (chưa có AI, mạng...) không làm mất Trí tuệ đã tạo: kết quả nói rõ phần nào xong, phần nào chưa.
 */
final class BrainFromSuggestion
{
    public const MAX_SEED = 3;

    /**
     * @return array{brain: Brain, template: string, topics: int, imported: int, items_auto: int, items_proposed: int, sources: list<array{url:string,title:string,domain:string}>, registered: int, seed_error: ?string}
     */
    public function create(Website $site, string $field, ?Article $article, ?User $by): array
    {
        $field = trim(mb_substr(strip_tags($field), 0, 160));
        [$covers, $sensitive] = $article ? $this->suggestionOf($article, $field) : [[], false];
        // Mẫu sức khỏe (YMYL: bắt buộc nguồn, rủi ro cao, chủ đề bệnh/thuốc) CHỈ khi lĩnh vực thật sự nhạy cảm với sức khỏe người đọc; lĩnh vực kỹ thuật/công nghiệp dùng mẫu trống rồi dựng cây chủ đề từ định hướng của bài.
        // Mẫu chọn theo TÊN lĩnh vực, không theo các mảng bao quát: các mảng có thể nhắc tới bệnh đã có kho riêng ("Nội tiết" bao quát "Đái tháo đường") và kéo nhầm mẫu của bệnh đó.
        $template = $sensitive ? BrainTemplates::suggestFor($field) : 'trong';
        if ($template !== 'trong' && $template !== 'y_khoa_tong_quat' && $template !== 'y_khoa_duoc' && Brain::query()->where('status', 'active')->where('domain_key', $template)->exists()) {
            $template = $sensitive ? 'y_khoa_tong_quat' : 'trong'; // lĩnh vực chuyên biệt đã có Trí tuệ riêng: không nhân bản mẫu của nó thành kho thứ hai
        }
        $reused = self::overlapping($field);
        if ($reused !== null) { // đã có Trí tuệ cùng lĩnh vực (kể cả chưa gắn website): dùng lại, không lập kho trùng
            if (! $site->brains()->where('brains.id', $reused->id)->exists()) {
                BrainAutoAttach::attach($site, $reused, 'Dùng lại Trí tuệ có sẵn cùng lĩnh vực «'.$field.'» thay vì lập kho trùng', 100, $article, $by);
            }
            $brain = $reused;
        } else {
            $brain = (new BrainService)->createFromTemplate($field, $template, [$site], $by);
        }

        $refs = $article ? $this->referencesOf($article) : [];
        $plan = $article ? $this->planOf($article) : [];
        $topics = $reused !== null ? 0 : $this->direction($brain, $article, $plan, $covers); // kho có sẵn giữ nguyên định hướng và hồ sơ của nó
        // Hồ sơ lĩnh vực (giọng, khung loại bài, thuật ngữ, quy tắc) soạn nháp ĐÚNG lĩnh vực này, không giữ khung của mẫu khác.
        $profileDraft = $reused !== null ? ['status' => 'skipped'] : (new BrainProfileDrafter(self::lightJson()))->draft($brain, $field, $covers, (string) ($plan['angle'] ?? ''), (string) ($article?->primary_keyword ?? ''), $sensitive);

        $res = ['brain' => $brain->fresh(), 'template' => $template, 'topics' => $topics, 'imported' => 0, 'items_auto' => 0, 'items_proposed' => 0, 'sources' => [], 'registered' => 0, 'seed_error' => null, 'reused' => $reused !== null, 'routed' => [], 'profile' => $profileDraft['status']];
        $trusted = [];
        foreach ($refs as $r) {
            $level = SourceTrust::assess($r['url'], $brain, SourceDiscoveryService::fieldFor($field.' '.($article?->primary_keyword ?? '')))['level'];
            if ($level === 'trusted') {
                $trusted[] = $r;
            } elseif ($level === 'neutral') {
                try {
                    $host = (string) (BrainSourceRegistry::hostOf($r['url']) ?? '');
                    if ($host !== '') {
                        (new BrainSourceRegistry)->add($brain, mb_substr($r['title'] !== '' ? $r['title'] : $host, 0, 120), $host, null, 'Tìm thấy khi viết bài «'.mb_substr((string) $article?->primary_keyword, 0, 80).'»; chưa xác nhận, cần người xét.', $by);
                        $res['registered']++;
                    }
                } catch (\Throwable) {
                    // ghi nguồn gợi ý là phần thêm
                }
            }
        }
        if ($trusted !== []) {
            try {
                @set_time_limit(300);
                // Mỗi nguồn nạp vào ĐÚNG Trí tuệ của lĩnh vực nó (nguồn về bệnh đã có kho riêng thì vào kho bệnh đó), không dồn hết vào kho vừa lập.
                foreach ($this->routeSources($trusted, $brain, $field) as $g) {
                    /** @var Brain $b */
                    $b = $g['brain'];
                    if ($b->id !== $brain->id && ! $site->brains()->where('brains.id', $b->id)->exists()) {
                        BrainAutoAttach::attach($site, $b, 'Nguồn tìm được khi viết bài thuộc lĩnh vực của Trí tuệ này', 100, $article, $by);
                    }
                    $imp = app(SourceDiscoveryService::class)->import($site, array_slice($g['refs'], 0, self::MAX_SEED), $by, ['brain_id' => $b->id, 'max_sources' => self::MAX_SEED, 'only_trusted' => true,
                        'field' => SourceDiscoveryService::fieldFor($b->name.' '.($article?->primary_keyword ?? '')), 'budget_cap_vnd' => BrainBuildService::totalBudgetVnd() * 0.2, 'purpose' => 'seed']);
                    $res['imported'] += (int) $imp['imported'];
                    $res['items_auto'] += (int) $imp['items_auto'];
                    $res['items_proposed'] += (int) $imp['items_proposed'];
                    if ($b->id !== $brain->id) {
                        $res['routed'][$b->name] = (int) $imp['imported'];
                    }
                    foreach ((array) $imp['pages'] as $p) {
                        if (! empty($p['ok'])) {
                            $res['sources'][] = ['url' => (string) $p['url'], 'title' => (string) ($p['title'] ?? ''), 'domain' => (string) $p['domain']];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $res['seed_error'] = mb_substr($e->getMessage(), 0, 200);
            }
        }
        KnowledgeGap::query()->where('website_id', $site->id)->where('query', mb_substr(KnowledgeSuggestions::BRAIN_PREFIX.$field, 0, 190))->update(['status' => 'filled', 'filled_at' => now()]);

        return $res;
    }

    private const STOP = ['cho', 'cua', 'va', 'benh', 'the', 'tri', 'nguoi', 'khoe', 'suc'];

    /** @return list<string> từ không dấu, chữ thường, bỏ từ chung. */
    private static function words(string $t, int $min = 3): array
    {
        $a = preg_split('/[^a-z0-9]+/', \Illuminate\Support\Str::ascii(mb_strtolower($t))) ?: [];

        return array_values(array_unique(array_filter($a, fn ($w) => strlen($w) >= $min && ! in_array($w, self::STOP, true))));
    }

    /**
     * Trí tuệ có sẵn đã bao trọn lĩnh vực được đề xuất (kể cả chưa gắn website): hầu hết từ của tên lĩnh vực (từ 75%, tối thiểu 2 từ) nằm trong tên/lĩnh vực của Trí tuệ đó.
     * Lĩnh vực HẸP hơn kho có sẵn (vd. "Dinh dưỡng cho người tiểu đường" so với "Dinh dưỡng và chế độ ăn") thì không tính, để vẫn lập được kho chuyên biệt.
     */
    public static function overlapping(string $field): ?Brain
    {
        $a = self::words($field);
        if (count($a) < 2) {
            return null;
        }
        foreach (Brain::query()->where('status', 'active')->where('domain_key', '!=', 'thuong_hieu')->orderBy('id')->get() as $b) {
            $shared = count(array_intersect($a, self::words($b->name.' '.str_replace('_', ' ', (string) $b->domain_key))));
            if ($shared >= 2 && $shared / count($a) >= 0.75) {
                return $b;
            }
        }

        return null;
    }

    /** Từ khóa nhận diện lĩnh vực của một Trí tuệ: tên tiếng Việt không dấu và từ tương đương tiếng Anh (nguồn quốc tế thường là tiếng Anh). @return list<string> */
    private static function terms(string $name): array
    {
        $en = array_merge(...array_map(fn ($e) => self::words((string) $e, 4), \App\Services\Knowledge\KnowledgeGlossary::expand($name) ?: ['']));

        return array_values(array_unique(array_merge(self::words($name, 4), $en)));
    }

    /**
     * Chia các nguồn về đúng Trí tuệ: nguồn có nhiều dấu hiệu lĩnh vực của MỘT Trí tuệ khác (đang hoạt động, mỗi lĩnh vực một bản gốc) hơn Trí tuệ vừa lập thì vào Trí tuệ đó.
     *
     * @param  list<array{url:string,title:string}>  $refs
     * @return list<array{brain: Brain, refs: list<array{url:string,title:string}>}> nhóm đầu luôn là Trí tuệ đích (có thể rỗng)
     */
    public function routeSources(array $refs, Brain $target, string $field): array
    {
        $others = [];
        $domains = [(string) $target->domain_key => true];
        foreach (Brain::query()->where('status', 'active')->where('domain_key', '!=', 'thuong_hieu')->where('id', '!=', $target->id)->orderBy('id')->limit(60)->get() as $b) {
            if (! isset($domains[(string) $b->domain_key])) {
                $domains[(string) $b->domain_key] = true;
                $others[] = [$b, self::terms($b->name)];
            }
        }
        $targetTerms = self::terms($field.' '.$target->name);
        $groups = [$target->id => ['brain' => $target, 'refs' => []]];
        foreach ($refs as $r) {
            $text = \Illuminate\Support\Str::ascii(mb_strtolower($r['title'].' '.(string) parse_url($r['url'], PHP_URL_HOST).' '.rawurldecode((string) parse_url($r['url'], PHP_URL_PATH))));
            $score = fn (array $terms) => count(array_filter($terms, fn ($t) => str_contains($text, $t)));
            $best = null;
            $bestScore = $score($targetTerms);
            foreach ($others as [$b, $terms]) {
                $sc = $score($terms);
                if ($sc >= 1 && $sc > $bestScore) {
                    [$best, $bestScore] = [$b, $sc];
                }
            }
            $dest = $best ?? $target;
            $groups[$dest->id] ??= ['brain' => $dest, 'refs' => []];
            $groups[$dest->id]['refs'][] = $r;
        }

        return array_values(array_filter($groups, fn ($g) => $g['refs'] !== []));
    }

    /** AI nhẹ cho việc soạn nháp hồ sơ: model của vai trò Điều phối/Biên tập (vd. agy), chưa có thì không dùng AI (hồ sơ trung tính). */
    public static function lightJson(): ?AiJson
    {
        try {
            if (! \App\Services\Agent\Ai\AiModelRouter::isConfigured()) {
                return null;
            }
            $router = new \App\Services\Agent\Ai\AiModelRouter;
            $chain = [];
            foreach (['coordinator', 'summary', 'outline'] as $task) {
                foreach ([$router->target($task)?->ai, $router->fallback($task)?->ai] as $ai) {
                    if ($ai instanceof \App\Contracts\StructuredAiInterface) {
                        $chain[] = $ai;
                    }
                }
            }
            $f = new \App\Services\Ai\FailoverStructuredAi($chain);

            return $f->isEmpty() ? null : new AiJson($f);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Câu thông báo cho người dùng: đã tạo gì, đã nạp gì, còn thiếu gì. @param array<string,mixed> $r kết quả create() */
    public static function summary(array $r): string
    {
        $t = 'Mẫu dùng: '.$r['template'].'. Định hướng từ bài: '.$r['topics'].' chủ đề của kho; hồ sơ lĩnh vực '.(($r['profile'] ?? '') === 'done' ? 'đã được soạn nháp theo đúng lĩnh vực (giọng, khung loại bài, thuật ngữ)' : 'dùng bản trung tính (chưa soạn được bằng AI)').'. ';
        $t .= $r['imported'] > 0
            ? 'Đã nạp '.$r['imported'].' nguồn tin cậy tìm được khi viết bài ('.$r['items_auto'].' mục tự nhận, '.$r['items_proposed'].' mục chờ duyệt): '.implode(', ', array_map(fn ($s) => $s['title'] !== '' ? $s['title'] : $s['domain'], array_slice($r['sources'], 0, 3))).'. '
            : 'Chưa nạp nguồn nào'.($r['seed_error'] ? ' ('.$r['seed_error'].')' : ' (bài chưa có nguồn tin cậy để nạp)').'; bài kế tiếp thuộc lĩnh vực này sẽ tự nạp. ';
        if (! empty($r['reused'])) {
            $t = 'Đã dùng lại Trí tuệ có sẵn cùng lĩnh vực thay vì lập kho trùng. '.$t;
        }
        if (! empty($r['routed'])) {
            $t .= ' Nguồn thuộc lĩnh vực khác đã nạp đúng Trí tuệ của nó: '.implode('; ', array_map(fn ($n, $c) => '«'.$n.'»: '.$c.' nguồn', array_keys($r['routed']), $r['routed'])).'. ';
        }
        if ($r['registered'] > 0) {
            $t .= $r['registered'].' nguồn thường được ghi vào Căn cứ chuẩn mực ở dạng chưa xác nhận để bạn xét.';
        }

        return trim($t);
    }

    /** Các mảng kho nên bao quát và cờ "nhạy cảm sức khỏe" mà điều phối đã đề xuất cho lĩnh vực này (suy ngược từ chủ đề lên lĩnh vực). @return array{0: list<string>, 1: bool} */
    private function suggestionOf(Article $article, string $field): array
    {
        foreach (AgentRun::query()->where('article_id', $article->id)->with('steps')->orderByDesc('id')->limit(5)->get() as $run) {
            foreach ($run->steps->where('stage', 'research') as $st) {
                $sg = ((array) $st->data)['suggestion'] ?? null;
                if (is_array($sg) && mb_strtolower((string) ($sg['field'] ?? '')) === mb_strtolower($field)) {
                    return [array_values(array_filter(array_map(fn ($c) => trim(mb_substr((string) $c, 0, 60)), (array) ($sg['covers'] ?? [])))), (bool) ($sg['sensitive'] ?? false)];
                }
            }
        }

        return [[], false];
    }

    /** Tài liệu web (url, tiêu đề) mà các phiên gần nhất của bài đã tìm được. @return list<array{url:string,title:string}> */
    private function referencesOf(Article $article): array
    {
        $out = [];
        foreach (AgentRun::query()->where('article_id', $article->id)->with('steps')->orderByDesc('id')->limit(3)->get() as $run) {
            foreach ($run->steps as $st) {
                foreach ((array) (((array) $st->data)['references'] ?? []) as $r) {
                    $u = is_array($r) ? (string) ($r['url'] ?? '') : '';
                    if (preg_match('#^https?://#i', $u) && ! isset($out[$u])) {
                        $out[$u] = ['url' => $u, 'title' => (string) ($r['title'] ?? '')];
                    }
                }
            }
        }

        return array_values($out);
    }

    /** Hồ sơ nội dung của bài (góc tiếp cận, câu hỏi) từ bước tổng hợp gần nhất. @return array<string,mixed> */
    private function planOf(Article $article): array
    {
        foreach (AgentRun::query()->where('article_id', $article->id)->with('steps')->orderByDesc('id')->limit(3)->get() as $run) {
            $p = ((array) ($run->steps->firstWhere('stage', 'synthesize')?->data))['plan'] ?? null;
            if (is_array($p)) {
                return $p;
            }
        }

        return [];
    }

    /** Ghi định hướng xây kho vào Trí tuệ: mô tả, thiết lập và cây chủ đề từ dàn ý của bài. @return int số chủ đề đã tạo */
    private function direction(Brain $brain, ?Article $article, array $plan, array $covers = []): int
    {
        if (! $article) {
            return 0;
        }
        $made = 0;
        foreach (array_slice($covers, 0, 10) as $c) { // định hướng của LĨNH VỰC: các mảng kho nên bao quát (không chỉ riêng chủ đề của bài)
            if ($c !== '' && ! KnowledgeTopic::query()->where('brain_id', $brain->id)->whereNull('parent_id')->where('name', $c)->exists()) {
                KnowledgeTopic::create(['brain_id' => $brain->id, 'name' => $c]);
                $made++;
            }
        }
        $questions = array_values(array_filter(array_map('strval', array_slice((array) ($plan['questions'] ?? []), 0, 8))));
        $brain->update([
            'description' => trim(mb_substr((string) $brain->description.($covers !== [] ? ' Bao quát: '.implode('; ', array_slice($covers, 0, 8)).'.' : '').' Lập từ gợi ý khi viết bài «'.$article->primary_keyword.'»'.(($plan['angle'] ?? '') !== '' ? ': '.$plan['angle'] : '.'), 0, 700)),
            'settings' => array_merge((array) $brain->settings, ['build_direction' => ['covers' => $covers, 'article_id' => $article->id, 'keyword' => (string) $article->primary_keyword, 'angle' => (string) ($plan['angle'] ?? ''), 'questions' => $questions]]),
        ]);
        $heads = [];
        foreach ((array) ($article->versions()->orderByDesc('version')->first()?->outline ?? []) as $o) {
            $h = trim((string) (is_array($o) ? ($o['heading'] ?? $o['title'] ?? '') : $o));
            $h = trim(preg_replace('/\s+[—–-]\s+.*$/u', '', $h) ?? $h);
            if ($h !== '') {
                $heads[] = mb_substr($h, 0, 120);
            }
        }
        $names = array_values(array_unique($heads !== [] ? $heads : array_map(fn ($q) => mb_substr($q, 0, 120), $questions)));
        if ($names === []) {
            return $made;
        }
        $top = KnowledgeTopic::create(['brain_id' => $brain->id, 'name' => mb_substr('Từ bài «'.$article->primary_keyword.'»', 0, 120)]);
        foreach (array_slice($names, 0, 10) as $n) {
            KnowledgeTopic::create(['brain_id' => $brain->id, 'parent_id' => $top->id, 'name' => $n]);
        }

        return $made + min(10, count($names));
    }

    /** Soạn lại hồ sơ lĩnh vực cho một Trí tuệ đã có (dùng định hướng đã lưu trong settings.build_direction). */
    public function redraftProfile(Brain $brain): array
    {
        $dir = (array) (((array) $brain->settings)['build_direction'] ?? []);
        $sensitive = $brain->riskClass() === 'high';

        return (new BrainProfileDrafter(self::lightJson()))->draft($brain, (string) $brain->name, array_values((array) ($dir['covers'] ?? [])), (string) ($dir['angle'] ?? ''), (string) ($dir['keyword'] ?? ''), $sensitive);
    }
}
