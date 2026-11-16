<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeItem;
use Illuminate\Support\Collection;

/** AG-S20: chuẩn hóa + băm nội dung, tìm mục trùng/gần giống cùng phạm vi (cùng website hoặc cùng "chung"). Tất định, không AI. */
final class KnowledgeDedupe
{
    public function __construct(private ?int $threshold = null)
    {
    }

    public function threshold(): int
    {
        return $this->threshold ?? (int) config('knowledge.dup_threshold', 85);
    }

    public static function normalize(string $text): string
    {
        $t = mb_strtolower(strip_tags($text));
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? $t;

        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    public static function hash(string $title, string $body): string
    {
        return hash('sha256', self::normalize($body !== '' ? $body : $title));
    }

    public function similarity(string $a, string $b): float
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        // similar_text có độ phức tạp bậc ba: cắt 1500 ký tự để không treo với đoạn dài.
        similar_text(mb_substr($a, 0, 1500), mb_substr($b, 0, 1500), $pct);

        return round($pct, 1);
    }

    /** @return array{exact: Collection<int,KnowledgeItem>, similar: Collection<int,array{item:KnowledgeItem,percent:float}>} */
    public function find(KnowledgeItem $item): array
    {
        $hash = $item->content_hash ?: self::hash($item->title, $item->body);
        $peers = KnowledgeItem::query()
            ->when($item->website_id === null, fn ($q) => $q->whereNull('website_id'), fn ($q) => $q->where('website_id', $item->website_id))
            ->where('status', '!=', 'rejected')
            ->when($item->exists, fn ($q) => $q->where('id', '!=', $item->id))
            ->get();
        $exact = $peers->filter(fn ($p) => $p->content_hash === $hash)->values();
        $similar = collect();
        foreach ($peers as $p) {
            if ($exact->contains('id', $p->id)) {
                continue;
            }
            $pct = $this->similarity($item->body, $p->body);
            if ($pct >= $this->threshold()) {
                $similar->push(['item' => $p, 'percent' => $pct]);
            }
        }

        return ['exact' => $exact, 'similar' => $similar->values()];
    }

    public function isDuplicate(KnowledgeItem $item): bool
    {
        $r = $this->find($item);

        return $r['exact']->isNotEmpty() || $r['similar']->isNotEmpty();
    }
}
