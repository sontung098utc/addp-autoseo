<?php

namespace App\Services\Brain;

use App\Models\AgentRun;
use App\Models\Article;
use App\Models\Brain;
use App\Models\KnowledgeGap;
use App\Models\KnowledgeTopic;
use App\Models\User;
use App\Models\Website;
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
        $template = $sensitive ? BrainTemplates::suggestFor($field.' '.implode(' ', $covers)) : 'trong';
        $brain = (new BrainService)->createFromTemplate($field, $template, [$site], $by);

        $refs = $article ? $this->referencesOf($article) : [];
        $plan = $article ? $this->planOf($article) : [];
        $topics = $this->direction($brain, $article, $plan, $covers);
        // Hồ sơ lĩnh vực (giọng, khung loại bài, thuật ngữ, quy tắc) soạn nháp ĐÚNG lĩnh vực này, không giữ khung của mẫu khác.
        $profileDraft = (new BrainProfileDrafter(self::lightJson()))->draft($brain, $field, $covers, (string) ($plan['angle'] ?? ''), (string) ($article?->primary_keyword ?? ''), $sensitive);

        $res = ['brain' => $brain->fresh(), 'template' => $template, 'topics' => $topics, 'imported' => 0, 'items_auto' => 0, 'items_proposed' => 0, 'sources' => [], 'registered' => 0, 'seed_error' => null, 'profile' => $profileDraft['status']];
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
                $imp = app(SourceDiscoveryService::class)->import($site, array_slice($trusted, 0, self::MAX_SEED), $by, ['brain_id' => $brain->id, 'max_sources' => self::MAX_SEED, 'only_trusted' => true,
                    'field' => SourceDiscoveryService::fieldFor($field.' '.($article?->primary_keyword ?? '')), 'budget_cap_vnd' => BrainBuildService::totalBudgetVnd() * 0.2, 'purpose' => 'seed']);
                $res['imported'] = (int) $imp['imported'];
                $res['items_auto'] = (int) $imp['items_auto'];
                $res['items_proposed'] = (int) $imp['items_proposed'];
                foreach ((array) $imp['pages'] as $p) {
                    if (! empty($p['ok'])) {
                        $res['sources'][] = ['url' => (string) $p['url'], 'title' => (string) ($p['title'] ?? ''), 'domain' => (string) $p['domain']];
                    }
                }
            } catch (\Throwable $e) {
                $res['seed_error'] = mb_substr($e->getMessage(), 0, 200);
            }
        }
        KnowledgeGap::query()->where('website_id', $site->id)->where('query', mb_substr(KnowledgeSuggestions::BRAIN_PREFIX.$field, 0, 190))->update(['status' => 'filled', 'filled_at' => now()]);

        return $res;
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
