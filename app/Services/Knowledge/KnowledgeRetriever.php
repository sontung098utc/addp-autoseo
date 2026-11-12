<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AG-S20: truy xuất atom ĐÃ DUYỆT và CÒN HIỆU LỰC cho Người viết (có id + phiên bản + nguồn để trích).
 * MySQL/MariaDB dùng FULLTEXT (rơi về LIKE nếu không ra gì, vì từ ngắn tiếng Việt hay bị bỏ qua); SQLite dùng LIKE.
 * Atom của website được ưu tiên hơn atom chung. proposed/outdated/rejected/đã xóa không bao giờ được trả về.
 * AG-B1: có $brainIds thì chỉ lấy atom thuộc các Trí tuệ đó (Trí tuệ đứng trước được cộng điểm nhẹ) cộng atom brain_id NULL theo website/chung như cũ;
 * $brainIds = null là hành vi cũ (không lọc theo Trí tuệ).
 */
final class KnowledgeRetriever
{
    private const STOP = ['và', 'của', 'là', 'có', 'cho', 'các', 'một', 'những', 'được', 'trong', 'với', 'khi', 'the', 'and', 'for'];

    private const SITE_BONUS = 0.25;

    /** @return list<array{id:int,version:int,title:string,body:string,source:?string,source_id:?int,score:float,scope:string}> */
    public function search(Website $website, string $query, int $limit = 5, ?array $brainIds = null, ?string $onlyType = null, ?float $minRelevance = null): array
    {
        $query = trim(mb_substr($query, 0, 600));
        $terms = $this->terms($query);
        if ($query === '' || $terms === [] || $limit < 1) {
            return [];
        }
        $rows = [];
        // Mục còn là tiếng Anh vẫn phải tìm được bằng truy vấn tiếng Việt: thêm từ khóa tiếng Anh tương đương (KnowledgeGlossary).
        $en = KnowledgeGlossary::expand($query);
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $rows = $this->fulltext($website, $en === [] ? $query : $query.' '.implode(' ', $en), $brainIds, $onlyType);
        }
        if ($rows === []) {
            $rows = $this->like($website, $terms, $query, $brainIds, $onlyType, $en);
        }
        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);
        $min = $minRelevance ?? (float) config('knowledge.min_relevance', 0.0);
        if ($min > 0 && $onlyType === null) {
            $rows = array_values(array_filter($rows, fn ($r) => $this->relevance($website, $brainIds, $terms, $query, $r) >= $min));
        }

        return $this->rerank($rows, $limit);
    }

    /** @var array<string,array<int,string>> kho chuẩn hóa theo phạm vi (website + Trí tuệ), nạp một lần mỗi đối tượng */
    private array $corpus = [];

    /**
     * Độ liên quan TUYỆT ĐỐI của một mục với truy vấn (0..1), độc lập với engine tìm kiếm (điểm FULLTEXT của MariaDB chỉ là tương đối so với mục tốt nhất nên luôn có mục đạt 1).
     * = tổng trọng số IDF (trên cả kho trong phạm vi) của các từ truy vấn có trong mục / tổng trọng số mọi từ truy vấn. Từ truy vấn không có trong kho mang trọng số cao nhất,
     * nên truy vấn lạc đề (ví dụ "đi bộ" với kho tiểu đường) chỉ khớp được các từ chung chung có trọng số thấp và ra điểm thấp. Mục tiếng Anh: lấy cao hơn theo tỉ lệ khái niệm khớp (KnowledgeGlossary).
     *
     * @param  list<string>  $terms
     * @param  array<string,mixed>  $row
     */
    private function relevance(Website $website, ?array $brainIds, array $terms, string $query, array $row): float
    {
        $key = $website->id.'|'.json_encode($brainIds);
        if (! isset($this->corpus[$key])) {
            $this->corpus[$key] = $this->searchable($website, $brainIds)->limit(5000)->get(['id', 'title', 'body'])
                ->mapWithKeys(fn ($i) => [$i->id => KnowledgeDedupe::normalize($i->title.' '.$i->body)])->all();
        }
        $corpus = $this->corpus[$key];
        $n = max(1, count($corpus));
        $w = [];
        $common = []; // từ có ở hơn 40% kho (tên bệnh, "mỗi ngày"...): không đủ để coi mục là liên quan
        foreach ($terms as $t) {
            $df = 0;
            foreach ($corpus as $text) {
                $df += str_contains($text, $t) ? 1 : 0;
            }
            $w[$t] = log(1 + $n / (1 + $df));
            $common[$t] = $n >= 5 ? $df > 0.4 * $n : $df >= $n;
        }
        $total = array_sum($w);
        if ($total <= 0) {
            return 1.0;
        }
        $text = $corpus[$row['id']] ?? KnowledgeDedupe::normalize((string) ($row['title'] ?? '').' '.(string) ($row['body'] ?? ''));
        $hit = 0.0;
        $distinct = false;
        foreach ($w as $t => $wt) {
            if (str_contains($text, $t)) {
                $hit += $wt;
                $distinct = $distinct || ! $common[$t];
            }
        }
        // Chỉ khớp từ chung chung thì không phải mục liên quan, dù điểm phủ có cao.
        $cov = $distinct || count(array_filter($common)) === count($common) ? $hit / $total : 0.0;
        $concepts = KnowledgeGlossary::concepts($query);
        if ($concepts !== []) {
            $m = 0;
            foreach ($concepts as $alts) {
                $m += count(array_filter($alts, fn ($a) => str_contains($text, $a))) > 0 ? 1 : 0;
            }
            $cov = max($cov, $m / count($concepts));
        }

        return $cov;
    }

    /**
     * Xếp hạng lại: hạ điểm mục đã bị lượt kiểm lại nghĩa (quy trình Xây kho chuyên ngành) gắn "lệch nghĩa" (x0,5) hoặc "cần xem" (x0,9), để mục đáng tin lên trước.
     * Đã thử và KHÔNG dùng (đo trên 80 câu hỏi của 4 kho): gộp mục gần trùng (trung tính, làm gộp nhầm mục khác phạm vi), đa dạng hóa theo chủ đề (MMR) và khớp cụm
     * hai từ (đều không cải thiện). Phần cải thiện chính nằm ở trọng số IDF trong like().
     *
     * @param  list<array<string,mixed>>  $rows đã sắp theo điểm giảm dần
     * @return list<array<string,mixed>>
     */
    private function rerank(array $rows, int $limit): array
    {
        foreach ($rows as &$r) {
            $tags = (array) ($r['tags'] ?? []);
            $r['score'] = round($r['score'] * (in_array('kiểm lại: lệch nghĩa', $tags, true) ? 0.5 : (in_array('kiểm lại: cần xem', $tags, true) ? 0.9 : 1.0)), 4);
        }
        unset($r);
        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['id'] <=> $b['id']);

        return array_slice($rows, 0, $limit);
    }

    /** Điều kiện "dùng được": đã duyệt, còn hiệu lực, thuộc website hoặc chung. */
    public function eligible(Website $website, ?array $brainIds = null): Builder
    {
        $today = now()->toDateString();
        $scope = fn ($q) => $q->whereNull('website_id')->orWhere('website_id', $website->id);

        return KnowledgeItem::query()->with('source')
            ->where('status', 'approved')
            ->when($brainIds === null, fn ($q) => $q->where($scope))
            ->when($brainIds !== null, fn ($q) => $q->where(fn ($q2) => $q2->whereIn('brain_id', $brainIds ?: [0])
                ->orWhere(fn ($q3) => $q3->whereNull('brain_id')->where($scope))))
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $today));
    }

    /** Như eligible() nhưng bỏ các mục QUY TẮC (quan niệm sai / không được nói): chúng không phải sự thật để trích. */
    public function searchable(Website $website, ?array $brainIds = null): Builder
    {
        return $this->eligible($website, $brainIds)->whereNotIn('type', KnowledgeItem::RULE_TYPES)->where('type', '!=', 'product');
    }

    /** Sản phẩm của công ty (mục loại 'product', đã duyệt, còn hiệu lực) khớp chủ đề; có chỗ riêng, không tranh chỗ với mục thường. @return list<array<string,mixed>> */
    public function products(Website $website, string $query, int $limit = 2, ?array $brainIds = null): array
    {
        return $this->search($website, $query, $limit, $brainIds, 'product');
    }

    /**
     * AG-B3: các QUY TẮC của chuyên gia (quan niệm sai, không được nói) đã duyệt và còn hiệu lực, áp cho MỌI bài của phạm vi này
     * (không phụ thuộc truy xuất theo chủ đề). Tối đa $limit mục, 'không được nói' trước.
     *
     * @return list<array{id:int,version:int,type:string,title:string,body:string,phrases:list<string>,source:?string}>
     */
    public function rules(Website $website, ?array $brainIds = null, int $limit = 20): array
    {
        $out = [];
        foreach ($this->eligible($website, $brainIds)->whereIn('type', KnowledgeItem::RULE_TYPES)
            ->orderByRaw("case when type='forbidden' then 0 else 1 end")->orderBy('id')->limit($limit)->get() as $i) {
            $out[] = [
                'id' => $i->id, 'version' => (int) $i->version, 'type' => $i->type, 'title' => $i->title, 'body' => $i->body,
                'phrases' => self::phrases($i), 'source' => $i->source?->title,
            ];
        }

        return $out;
    }

    /** Cụm từ cần chặn của một mục 'không được nói' (trường `value`, cách nhau '|'); mỗi cụm tối thiểu 3 ký tự để không chặn nhầm. @return list<string> */
    public static function phrases(KnowledgeItem $item): array
    {
        if ($item->type !== 'forbidden') {
            return [];
        }
        $parts = array_map('trim', explode('|', (string) $item->value));

        return array_values(array_unique(array_filter($parts, fn ($p) => mb_strlen($p) >= 3)));
    }

    /** @return list<string> */
    public function terms(string $query): array
    {
        $words = preg_split('/\s+/u', KnowledgeDedupe::normalize($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOP, true));

        return array_slice(array_values(array_unique($words)), 0, 24);
    }

    private function base(Website $website, ?array $brainIds, ?string $onlyType): Builder
    {
        return $onlyType === 'product' ? $this->eligible($website, $brainIds)->where('type', 'product') : $this->searchable($website, $brainIds);
    }

    private function fulltext(Website $website, string $query, ?array $brainIds, ?string $onlyType = null): array
    {
        try {
            $items = $this->base($website, $brainIds, $onlyType)
                ->selectRaw('knowledge_items.*, MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE) as ft_score', [$query])
                ->whereRaw('MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE)', [$query])
                ->orderByDesc('ft_score')->limit(50)->get();
        } catch (\Throwable) {
            return [];
        }
        $max = (float) ($items->max('ft_score') ?: 1);

        return $items->map(fn ($i) => $this->row($i, (float) $i->ft_score / $max, $website, $brainIds))->all();
    }

    private function like(Website $website, array $terms, string $query, ?array $brainIds, ?string $onlyType = null, array $en = []): array
    {
        // Ký tự thoát là '!' (không phải '\'): trong MariaDB/MySQL '\' là ký tự thoát của chuỗi SQL nên `escape '\'` là lỗi cú pháp.
        $esc = fn (string $t) => preg_replace('/([!%_])/', '!$1', $t);
        $items = $this->base($website, $brainIds, $onlyType)->where(function ($q) use ($terms, $esc, $en) {
            foreach (array_merge($terms, $en) as $t) {
                $q->orWhereRaw("lower(title) like ? escape '!'", ['%'.$esc($t).'%'])->orWhereRaw("lower(body) like ? escape '!'", ['%'.$esc($t).'%']);
            }
        })->limit(600)->get();
        $phrase = KnowledgeDedupe::normalize($query);
        $concepts = KnowledgeGlossary::concepts($query);
        $out = [];
        $norm = [];
        foreach ($items as $i) {
            $norm[$i->id] = [KnowledgeDedupe::normalize($i->title), KnowledgeDedupe::normalize($i->body)];
        }
        // Trọng số IDF trên tập ứng viên: từ có ở hầu hết mục (ví dụ tên bệnh) ít phân biệt hơn từ hiếm (ví dụ "đo tại nhà"), nên không để nó chi phối điểm.
        // Đo trên 80 câu hỏi của 4 kho: số câu phủ đủ khi người viết nhận 5/8/12 mục tăng từ 53/63/65 lên 59/67/70.
        $n = max(1, count($norm));
        $w = function (callable $has) use ($norm, $n): float {
            $df = 0;
            foreach ($norm as [$t, $b]) {
                $df += $has($t.' '.$b) ? 1 : 0;
            }

            return log(1 + $n / max(1, $df));
        };
        $wTerm = [];
        foreach ($terms as $t) {
            $wTerm[$t] = $w(fn ($text) => str_contains($text, $t));
        }
        $wConcept = [];
        foreach ($concepts as $ci => $alts) {
            $wConcept[$ci] = $w(fn ($text) => count(array_filter($alts, fn ($a) => str_contains($text, $a))) > 0);
        }
        foreach ($items as $i) {
            [$title, $body] = $norm[$i->id];
            $hit = 0.0;
            $max = 0.0;
            foreach ($terms as $t) {
                $hit += $wTerm[$t] * ((str_contains($title, $t) ? 2 : 0) + (str_contains($body, $t) ? 1 : 0));
                $max += $wTerm[$t] * 3;
            }
            $score = $max > 0 ? $hit / $max : 0.0;
            if ($concepts !== []) { // mục tiếng Anh: điểm theo số khái niệm được phủ, lấy cao hơn (không phạt mục tiếng Việt vì có thêm từ)
                $hitEn = 0.0;
                $maxEn = 0.0;
                foreach ($concepts as $ci => $alts) {
                    $hitEn += $wConcept[$ci] * ((count(array_filter($alts, fn ($t) => str_contains($title, $t))) > 0 ? 2 : 0) + (count(array_filter($alts, fn ($t) => str_contains($body, $t))) > 0 ? 1 : 0));
                    $maxEn += $wConcept[$ci] * 3;
                }
                $score = max($score, $maxEn > 0 ? min(1.0, $hitEn / $maxEn) : 0.0);
            }
            if ($phrase !== '' && (str_contains($title, $phrase) || str_contains($body, $phrase))) {
                $score = min(1.0, $score + 0.3);
            }
            $out[] = $this->row($i, $score, $website, $brainIds);
        }

        return $out;
    }

    private function row(KnowledgeItem $i, float $score, Website $website, ?array $brainIds = null): array
    {
        $own = $i->website_id === $website->id;
        $rank = $brainIds !== null && $i->brain_id !== null ? array_search($i->brain_id, $brainIds, true) : false;
        $score += $rank !== false ? max(0.0, 0.1 - 0.02 * $rank) : 0.0; // Trí tuệ ưu tiên cao hơn được cộng nhẹ

        return [
            'id' => $i->id, 'version' => (int) $i->version, 'title' => $i->title, 'body' => $i->body,
            'source' => $i->source?->title, 'source_id' => $i->source_id,
            'score' => round($score + ($own ? self::SITE_BONUS : 0.0), 4), 'scope' => $own ? 'website' : 'general', 'brain_id' => $i->brain_id,
            'topic_id' => $i->topic_id, 'tags' => (array) $i->tags,
        ];
    }
}
