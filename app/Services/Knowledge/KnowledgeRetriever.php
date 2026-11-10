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
    public function search(Website $website, string $query, int $limit = 5, ?array $brainIds = null): array
    {
        $query = trim(mb_substr($query, 0, 600));
        $terms = $this->terms($query);
        if ($query === '' || $terms === [] || $limit < 1) {
            return [];
        }
        $rows = [];
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $rows = $this->fulltext($website, $query, $brainIds);
        }
        if ($rows === []) {
            $rows = $this->like($website, $terms, $query, $brainIds);
        }
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

    /** @return list<string> */
    public function terms(string $query): array
    {
        $words = preg_split('/\s+/u', KnowledgeDedupe::normalize($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOP, true));

        return array_slice(array_values(array_unique($words)), 0, 24);
    }

    private function fulltext(Website $website, string $query, ?array $brainIds): array
    {
        try {
            $items = $this->eligible($website, $brainIds)
                ->selectRaw('knowledge_items.*, MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE) as ft_score', [$query])
                ->whereRaw('MATCH(title, body) AGAINST (? IN NATURAL LANGUAGE MODE)', [$query])
                ->orderByDesc('ft_score')->limit(50)->get();
        } catch (\Throwable) {
            return [];
        }
        $max = (float) ($items->max('ft_score') ?: 1);

        return $items->map(fn ($i) => $this->row($i, (float) $i->ft_score / $max, $website, $brainIds))->all();
    }

    private function like(Website $website, array $terms, string $query, ?array $brainIds): array
    {
        // Ký tự thoát là '!' (không phải '\'): trong MariaDB/MySQL '\' là ký tự thoát của chuỗi SQL nên `escape '\'` là lỗi cú pháp.
        $esc = fn (string $t) => preg_replace('/([!%_])/', '!$1', $t);
        $items = $this->eligible($website, $brainIds)->where(function ($q) use ($terms, $esc) {
            foreach ($terms as $t) {
                $q->orWhereRaw("lower(title) like ? escape '!'", ['%'.$esc($t).'%'])->orWhereRaw("lower(body) like ? escape '!'", ['%'.$esc($t).'%']);
            }
        })->limit(200)->get();
        $phrase = KnowledgeDedupe::normalize($query);
        $out = [];
        foreach ($items as $i) {
            $title = KnowledgeDedupe::normalize($i->title);
            $body = KnowledgeDedupe::normalize($i->body);
            $hit = 0.0;
            foreach ($terms as $t) {
                $hit += (str_contains($title, $t) ? 2 : 0) + (str_contains($body, $t) ? 1 : 0);
            }
            $score = $hit / (3 * count($terms));
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
        ];
    }
}
