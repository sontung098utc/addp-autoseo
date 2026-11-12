<?php

namespace App\Services\Brain;

use App\Models\Article;
use App\Models\Brain;
use App\Models\Campaign;
use App\Services\Knowledge\KnowledgeDedupe;

/**
 * AG-B1: các Trí tuệ mà MỘT bài đang dùng (theo chiến dịch nếu chiến dịch có chọn, không thì theo website, đúng thứ tự ưu tiên)
 * và rút gọn Hồ sơ lĩnh vực của chúng thành `options['domain']` cho bước dàn ý/viết/sửa.
 * "Mức dựa vào AI" ở P1 chỉ được LƯU, TRUYỀN và GHI NHẬT KÝ (cộng nâng hạng rủi ro); việc ép bằng chứng/kiểm claim là P2.
 */
final class BrainContext
{
    public const EXEMPLAR_MAX = 2;

    public const EXEMPLAR_LEN = 800;

    private const STRICT = ['free' => 0, 'numbers' => 1, 'must_cite' => 2];

    private const RANK = ['low' => 0, 'medium' => 1, 'high' => 2];

    /** @param list<Brain> $brains theo thứ tự ưu tiên */
    public function __construct(public readonly array $brains)
    {
    }

    public static function forArticle(Article $article, ?int $campaignId = null): self
    {
        $brains = collect();
        $campaign = $campaignId ? Campaign::query()->find($campaignId) : null;
        $chosen = array_values(array_filter(array_map('intval', (array) ($campaign?->brain_ids ?? []))));
        if ($chosen !== []) {
            $byId = Brain::query()->with('profile')->whereIn('id', $chosen)->where('status', 'active')->get()->keyBy('id');
            $brains = collect($chosen)->map(fn ($id) => $byId->get($id))->filter();
        }
        if ($brains->isEmpty() && $article->website_id) {
            $brains = Brain::query()->with('profile')->where('status', 'active')
                ->whereHas('websites', fn ($q) => $q->where('websites.id', $article->website_id))
                ->join('brain_website', fn ($j) => $j->on('brain_website.brain_id', '=', 'brains.id')->where('brain_website.website_id', $article->website_id))
                ->orderBy('brain_website.priority')->orderBy('brains.id')->select('brains.*')->get();
        }

        return new self($brains->values()->all());
    }

    public function isEmpty(): bool
    {
        return $this->brains === [];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_map(fn (Brain $b) => $b->id, $this->brains);
    }

    public function names(): string
    {
        return implode(', ', array_map(fn (Brain $b) => $b->name, $this->brains));
    }

    /** Mức nghiêm nhất trong các Trí tuệ: must_cite > numbers > free. */
    public function reliance(): ?string
    {
        $best = null;
        foreach ($this->brains as $b) {
            if ($best === null || self::STRICT[$b->reliance()] > self::STRICT[$best]) {
                $best = $b->reliance();
            }
        }

        return $best;
    }

    /** Sàn hạng rủi ro: risk_class cao nhất của các Trí tuệ; must_cite thì tối thiểu 'medium'. Null nếu không có Trí tuệ. */
    public function riskFloor(): ?string
    {
        if ($this->brains === []) {
            return null;
        }
        $floor = 'low';
        foreach ($this->brains as $b) {
            $c = $b->riskClass();
            if ($b->reliance() === 'must_cite' && self::RANK[$c] < self::RANK['medium']) {
                $c = 'medium';
            }
            if (self::RANK[$c] > self::RANK[$floor]) {
                $floor = $c;
            }
        }

        return $floor;
    }

    /** Nâng (không bao giờ hạ) hạng đã tính lên sàn của Trí tuệ. */
    public function raise(string $level): string
    {
        $floor = $this->riskFloor();

        return $floor !== null && self::RANK[$floor] > (self::RANK[$level] ?? 0) ? $floor : $level;
    }

    /** @return list<array{id:int,name:string,filled:int,total:int,ai_reliance:string,risk_class:string}> */
    public function summary(): array
    {
        return array_map(function (Brain $b) {
            [$f, $t] = ($b->profile ?? new \App\Models\BrainProfile())->completeness();

            return ['id' => $b->id, 'name' => $b->name, 'filled' => $f, 'total' => $t, 'ai_reliance' => $b->reliance(), 'risk_class' => $b->riskClass()];
        }, $this->brains);
    }

    /** Câu ngắn ghi vào bước: "Dùng Trí tuệ X (hồ sơ n/m trường, mức dựa vào AI: …)". */
    public function note(): string
    {
        if ($this->brains === []) {
            return '';
        }
        $parts = array_map(fn ($s) => $s['name'].' (hồ sơ '.$s['filled'].'/'.$s['total'].' trường, mức dựa vào AI: '.Brain::RELIANCE[$s['ai_reliance']].')', $this->summary());

        return ' Dùng Trí tuệ '.implode('; ', $parts).'.';
    }

    /** @return array<string,mixed> dữ kiện ghi vào data của bước */
    public function stepData(): array
    {
        return $this->brains === [] ? [] : ['brains' => $this->summary(), 'ai_reliance' => $this->reliance(), 'risk_floor' => $this->riskFloor()];
    }

    /** @return array<string,mixed> options cho adapter AI (rỗng nếu không có Trí tuệ nào có nội dung) */
    public function options(Article $article): array
    {
        $d = $this->domain($article);

        return $d === null ? [] : ['domain' => $d];
    }

    /** @return array<string,mixed>|null */
    public function domain(Article $article): ?array
    {
        if ($this->brains === []) {
            return null;
        }
        $first = fn (callable $get) => collect($this->brains)->map($get)->first(fn ($v) => trim((string) $v) !== '', '');
        $union = function (callable $get, int $cap) {
            return array_slice(array_values(array_unique(array_filter(collect($this->brains)->flatMap(fn ($b) => (array) $get($b))->all(), fn ($x) => trim((string) $x) !== ''))), 0, $cap);
        };
        $p = fn (Brain $b) => $b->profile;
        $terms = [];
        $seen = [];
        foreach ($this->brains as $b) {
            foreach ((array) ($p($b)?->terminology['preferred'] ?? []) as $t) {
                $k = KnowledgeDedupe::normalize((string) ($t['term'] ?? ''));
                if ($k !== '' && ! isset($seen[$k]) && count($terms) < 40) {
                    $seen[$k] = true;
                    $terms[] = ['term' => (string) $t['term'], 'definition' => (string) ($t['definition'] ?? ''), 'avoid' => array_values((array) ($t['avoid'] ?? []))];
                }
            }
        }
        $exemplars = [];
        foreach ($this->brains as $b) {
            foreach ((array) ($p($b)?->exemplars ?? []) as $e) {
                $txt = trim((string) ($e['text_or_url'] ?? ''));
                if ($txt !== '' && count($exemplars) < self::EXEMPLAR_MAX) {
                    $exemplars[] = ['title' => (string) ($e['title'] ?? ''), 'text' => mb_substr($txt, 0, self::EXEMPLAR_LEN)];
                }
            }
        }
        $domain = [
            'brains' => array_map(fn (Brain $b) => $b->name, $this->brains),
            'ai_reliance' => $this->reliance(),
            'language' => $this->brains[0]->language,
            'voice' => [
                'tone' => $first(fn ($b) => $p($b)?->voice['tone'] ?? ''), 'formality' => $first(fn ($b) => $p($b)?->voice['formality'] ?? ''),
                'pronouns' => $first(fn ($b) => $p($b)?->voice['pronouns'] ?? ''),
                'do' => $union(fn ($b) => $p($b)?->voice['do'] ?? [], 12), 'dont' => $union(fn ($b) => $p($b)?->voice['dont'] ?? [], 12),
            ],
            'article_type' => $this->articleType($article),
            'preferred_terms' => $terms,
            'abbreviations' => $union(fn ($b) => $p($b)?->terminology['abbreviations'] ?? [], 20),
            'reasoning_rules' => $union(fn ($b) => $p($b)?->reasoning['rules'] ?? [], 12),
            'exemplars' => $exemplars,
        ];

        return $domain;
    }

    /**
     * Khung bài hợp loại bài: chọn loại có nhiều từ của TÊN trùng với từ khóa/ý định/cụm chủ đề nhất; không khớp thì lấy loại đầu tiên có dàn ý.
     *
     * @return array{name:string,outline:list<string>,layout:list<string>,matched:bool}|null
     */
    private function articleType(Article $article): ?array
    {
        $hay = KnowledgeDedupe::normalize(implode(' ', [$article->primary_keyword, $article->search_intent, $article->topic_cluster]));
        $best = null;
        $bestScore = 0;
        $fallback = null;
        foreach ($this->brains as $b) {
            foreach ((array) ($b->profile?->structure['article_types'] ?? []) as $t) {
                $outline = array_values(array_filter((array) ($t['outline'] ?? []), fn ($o) => trim((string) $o) !== ''));
                if ($outline === []) {
                    continue;
                }
                $layout = array_values(array_filter(array_map('strval', (array) ($t['layout'] ?? [])), fn ($o) => trim($o) !== ''));
                $fallback ??= ['name' => (string) $t['name'], 'outline' => $outline, 'layout' => $layout, 'matched' => false];
                $score = 0;
                foreach (explode(' ', KnowledgeDedupe::normalize((string) $t['name'])) as $w) {
                    if (mb_strlen($w) >= 3 && str_contains($hay, $w)) {
                        $score++;
                    }
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['name' => (string) $t['name'], 'outline' => $outline, 'layout' => $layout, 'matched' => true];
                }
            }
        }

        return $best ?? $fallback;
    }
}
