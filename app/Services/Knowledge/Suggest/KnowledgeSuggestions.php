<?php

namespace App\Services\Knowledge\Suggest;

use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\KnowledgeGap;
use App\Models\Website;
use App\Services\Brain\BrainSourceRegistry;
use App\Services\Content\Quality\Rules\FactRule;
use App\Services\Knowledge\KnowledgeDedupe;
use App\Services\Knowledge\KnowledgeRetriever;
use Illuminate\Support\Facades\Cache;

/**
 * "Gợi ý cho kho" từ các bài gần đây: vòng kho học lại từ bài. Dẫn xuất khi đọc, 0 token, không bảng mới, không gọi mạng/AI.
 * Ba loại, theo thứ tự hiển thị: khoảng trống kho đang mở (gap) → số liệu chưa có căn cứ trong kho (figure) → nguồn AI nêu mà kho/Căn cứ chuẩn mực chưa có (source).
 * Chỉ GỢI Ý: không tự thêm gì vào kho. Gợi ý bị "Bỏ qua" được ẩn theo website (cache).
 * Cách so khớp nguồn chép từ luật SRC01 (SourceRule::known là private nên không gọi lại được; luật không bị sửa).
 */
final class KnowledgeSuggestions
{
    public const DAYS = 30;

    private const ORDER = ['gap' => 0, 'figure' => 1, 'source' => 2];

    /**
     * @return list<array{type: string, title: string, detail: string, article_id: ?int, article_version_id: ?int, action: string, key: string, query: ?string}>
     */
    public function forWebsite(Website $site, int $limit = 20): array
    {
        $since = now()->subDays(self::DAYS);
        $articles = Article::query()->where('website_id', $site->id)
            ->where(fn ($q) => $q->where('updated_at', '>=', $since)->orWhereHas('versions', fn ($v) => $v->where('created_at', '>=', $since)))
            ->orderByDesc('id')->limit(200)->get();
        $out = array_merge($this->gaps($site), $this->figures($articles, $since), $this->sources($site, $articles, $since));

        $hidden = array_flip(self::dismissed($site->id));
        $seen = [];
        $res = [];
        foreach ($out as $s) {
            if (isset($hidden[$s['key']]) || isset($seen[$s['key']])) {
                continue;
            }
            $seen[$s['key']] = true;
            $res[] = $s;
        }
        usort($res, fn ($a, $b) => self::ORDER[$a['type']] <=> self::ORDER[$b['type']]);

        return array_slice($res, 0, max(0, $limit));
    }

    /** @return list<string> */
    public static function dismissed(int $websiteId): array
    {
        $v = Cache::get(self::cacheKey($websiteId), []);

        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    public static function dismiss(int $websiteId, string $key): void
    {
        $all = array_values(array_unique(array_merge(self::dismissed($websiteId), [$key])));
        Cache::forever(self::cacheKey($websiteId), array_slice($all, -500));
    }

    public static function restoreAll(int $websiteId): void
    {
        Cache::forget(self::cacheKey($websiteId));
    }

    private static function cacheKey(int $websiteId): string
    {
        return 'kb_suggest_dismissed.'.$websiteId;
    }

    private static function key(string $type, string $text): string
    {
        return $type.':'.substr(sha1(KnowledgeDedupe::normalize($text)), 0, 16);
    }

    /** @return list<array<string,mixed>> */
    private function gaps(Website $site): array
    {
        $out = [];
        foreach (KnowledgeGap::query()->where('website_id', $site->id)->where('status', 'open')->orderByDesc('count')->orderByDesc('id')->limit(100)->get() as $g) {
            $q = trim((string) $g->query);
            if ($q === '') {
                continue;
            }
            $out[] = ['type' => 'gap', 'title' => 'Kho thiếu kiến thức về «'.mb_substr($q, 0, 80).'»',
                'detail' => 'Người viết đã tìm trong kho '.max(1, (int) $g->count).' lần mà chưa có mục nào đã duyệt. Có thể bổ sung từ website.',
                'article_id' => $g->article_id, 'article_version_id' => null, 'action' => 'build', 'key' => self::key('gap', $q), 'query' => mb_substr($q, 0, 120)];
        }

        return $out;
    }

    /** Số liệu: bước 'grade' mới nhất của mỗi bài, mã KB01/FACT01 trượt. */
    private function figures($articles, $since): array
    {
        $ids = $articles->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $runs = AgentRun::query()->whereIn('article_id', $ids)->where('started_at', '>=', $since)->orderByDesc('id')->get(['id', 'article_id', 'final_version_id']);
        $byArticle = [];
        foreach ($runs as $r) {
            $byArticle[$r->article_id][] = $r;
        }
        $out = [];
        foreach ($byArticle as $articleId => $list) {
            $step = AgentRunStep::query()->whereIn('agent_run_id', array_map(fn ($r) => $r->id, $list))->where('stage', 'grade')->orderByDesc('id')->first();
            if ($step === null) {
                continue;
            }
            $run = collect($list)->firstWhere('id', $step->agent_run_id);
            $kw = (string) ($articles->firstWhere('id', $articleId)?->primary_keyword ?? '');
            foreach ((array) ($step->data['failed'] ?? []) as $f) {
                $code = (string) ($f['code'] ?? '');
                if (! in_array($code, ['KB01', 'FACT01'], true)) {
                    continue;
                }
                $msg = trim((string) ($f['message'] ?? ''));
                if ($code === 'KB01' && preg_match('/cho "(.+?)"/u', $msg, $m)) {
                    $title = 'Số liệu «'.mb_substr($m[1], 0, 80).'» trong bài chưa khớp kho';
                } elseif ($code === 'KB01') {
                    $title = 'Số liệu trong bài chưa có trong kho';
                } else {
                    $title = 'Số liệu trong bài «'.mb_substr($kw, 0, 60).'» chưa nêu nguồn';
                }
                $out[] = ['type' => 'figure', 'title' => $title, 'detail' => mb_substr($msg, 0, 300).' Mở Kho trí tuệ để thêm hoặc sửa mục kiến thức.',
                    'article_id' => (int) $articleId, 'article_version_id' => $run?->final_version_id, 'action' => 'open_kb', 'key' => self::key('figure', $code.'|'.$title), 'query' => null];
            }
        }

        return $out;
    }

    /** Nguồn AI nêu trong bản mới nhất của mỗi bài mà kho/Căn cứ chuẩn mực/website không khớp. */
    private function sources(Website $site, $articles, $since): array
    {
        $ids = $articles->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $brainIds = $site->brains()->pluck('brains.id')->map(fn ($i) => (int) $i)->all();
        $registry = BrainSourceRegistry::forBrains($brainIds);
        [$names, $domains] = $this->known($site, $brainIds, $registry);

        $latest = ArticleVersion::query()->whereIn('article_id', $ids)->orderByDesc('version')->get(['id', 'article_id', 'version', 'title', 'sources_cited'])->unique('article_id');
        $out = [];
        foreach ($latest as $v) {
            $article = $articles->firstWhere('id', $v->article_id);
            foreach (FactRule::sources($v->sources_cited) as $c) {
                $hit = $registry->classify($c);
                if (($hit !== null && $hit['tier'] === 'blocked') || $this->matches($c, $names, $domains)) {
                    continue;
                }
                $name = mb_substr($c, 0, 80);
                $out[] = ['type' => 'source', 'title' => 'Nguồn «'.$name.'» được AI trích trong bài «'.mb_substr((string) ($v->title ?: $article?->primary_keyword), 0, 80).'»',
                    'detail' => 'Nguồn này chưa có trong kho hay Căn cứ chuẩn mực. AI tự nêu, chưa kiểm: xác minh nguồn có thật rồi thêm vào kho/Căn cứ chuẩn mực nếu đúng.',
                    'article_id' => $v->article_id, 'article_version_id' => $v->id, 'action' => 'open_kb', 'key' => self::key('source', $c), 'query' => null];
            }
        }

        return $out;
    }

    /** @return array{0: array<string,true>, 1: array<string,true>} tên chuẩn hóa và tên miền đã biết (như SRC01) */
    private function known(Website $site, array $brainIds, BrainSourceRegistry $registry): array
    {
        $names = $domains = [];
        $add = function (?string $name, ?string $domain = null) use (&$names, &$domains) {
            if ($name !== null && ($n = KnowledgeDedupe::normalize($name)) !== '' && mb_strlen($n) >= 4) {
                $names[$n] = true;
            }
            if ($domain !== null && ($h = BrainSourceRegistry::hostOf($domain)) !== null) {
                $domains[$h] = true;
            }
        };
        $add($site->name, $site->base_url);
        foreach ((new KnowledgeRetriever)->eligible($site, $brainIds ?: null)->limit(2000)->get() as $item) {
            $add($item->title);
            if ($item->source) {
                $add($item->source->title, $item->source->url);
            }
        }
        foreach ($registry->citable() as $s) {
            $add($s->name, $s->domain);
        }

        return [$names, $domains];
    }

    private function matches(string $cited, array $names, array $domains): bool
    {
        $hosts = [];
        if (($h = BrainSourceRegistry::hostOf($cited)) !== null) {
            $hosts[] = $h;
        }
        if (preg_match_all('~(?:https?://)?((?:[a-z0-9-]+\.)+[a-z]{2,})~iu', $cited, $m)) {
            foreach ($m[1] as $x) {
                if (($h = BrainSourceRegistry::hostOf($x)) !== null) {
                    $hosts[] = $h;
                }
            }
        }
        foreach (array_unique($hosts) as $h) {
            foreach (array_keys($domains) as $d) {
                if ($h === $d || str_ends_with($h, '.'.$d)) {
                    return true;
                }
            }
        }
        $n = KnowledgeDedupe::normalize($cited);
        if ($n === '') {
            return true; // chuỗi rỗng sau chuẩn hóa: không đáng gợi ý
        }
        foreach (array_keys($names) as $k) {
            if ($n === $k || (mb_strlen($n) >= 4 && (str_contains($k, $n) || str_contains($n, $k)))) {
                return true;
            }
        }

        return false;
    }
}
