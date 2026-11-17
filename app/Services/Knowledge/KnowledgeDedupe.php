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

    /** @var array<string, Collection<int,KnowledgeItem>> */
    private array $scopeCache = [];

    /** @var array<int, array{0:string,1:int,2:array<string,true>}> chuỗi đã chuẩn hóa (cắt 1500), độ dài, tập từ của từng mục ngang hàng */
    private array $normCache = [];

    private bool $cacheScopes = false;

    /** Dùng khi so NHIỀU mục liên tiếp trên dữ liệu không đổi (trang quản trị): tải mục cùng phạm vi một lần thay vì mỗi lần so lại tải hết. Không dùng khi đang thêm mục xen giữa. */
    public function withScopeCache(): self
    {
        $this->cacheScopes = true;

        return $this;
    }

    /** @return Collection<int,KnowledgeItem> */
    private function peersOf(KnowledgeItem $item): Collection
    {
        $load = fn () => KnowledgeItem::query()
            ->when($item->website_id === null, fn ($q) => $q->whereNull('website_id'), fn ($q) => $q->where('website_id', $item->website_id))
            ->where('status', '!=', 'rejected')
            ->get();
        $peers = $this->cacheScopes ? ($this->scopeCache[(string) ($item->website_id ?? 'null')] ??= $load()) : $load();

        return $item->exists ? $peers->where('id', '!=', $item->id)->values() : $peers;
    }

    /** @return array{0:string,1:int,2:array<string,true>} */
    private function prepared(string $text, ?int $id = null): array
    {
        if ($id !== null && isset($this->normCache[$id])) {
            return $this->normCache[$id];
        }
        $n = mb_substr(self::normalize($text), 0, 1500);
        $r = [$n, strlen($n), array_fill_keys(array_filter(explode(' ', $n)), true)];
        if ($id !== null && $this->cacheScopes) {
            $this->normCache[$id] = $r;
        }

        return $r;
    }

    /**
     * Độ giống theo similar_text nhưng bỏ qua các cặp CHẮC CHẮN không đạt ngưỡng, để không chạy phép tính bậc ba vô ích:
     * (1) cận trên của similar_text là 2·min(độ dài)/(tổng độ dài); (2) hai mục chia sẻ quá ít từ thì không thể giống ≥ ngưỡng.
     * Kết quả với các cặp đạt ngưỡng không đổi so với similarity().
     *
     * @param  array{0:string,1:int,2:array<string,true>}  $a
     * @param  array{0:string,1:int,2:array<string,true>}  $b
     */
    private function similarityPrepared(array $a, array $b): float
    {
        if ($a[0] === '' || $b[0] === '') {
            return 0.0;
        }
        $t = $this->threshold();
        if (200.0 * min($a[1], $b[1]) / ($a[1] + $b[1]) < $t) {
            return 0.0;
        }
        $small = count($a[2]) <= count($b[2]) ? $a[2] : $b[2];
        $big = $small === $a[2] ? $b[2] : $a[2];
        $shared = count(array_intersect_key($small, $big));
        if ($small !== [] && 2.0 * $shared / (count($a[2]) + count($b[2])) < 0.4 * $t / 100) {
            return 0.0; // tập từ gần như không giao nhau: không thể đạt ngưỡng giống
        }
        similar_text($a[0], $b[0], $pct);

        return round($pct, 1);
    }

    /** @return array{exact: Collection<int,KnowledgeItem>, similar: Collection<int,array{item:KnowledgeItem,percent:float}>} */
    public function find(KnowledgeItem $item): array
    {
        $hash = $item->content_hash ?: self::hash($item->title, $item->body);
        $peers = $this->peersOf($item);
        $exact = $peers->filter(fn ($p) => $p->content_hash === $hash)->values();
        $exactIds = $exact->pluck('id')->flip();
        $similar = collect();
        $mine = $this->prepared((string) $item->body);
        foreach ($peers as $p) {
            if ($exactIds->has($p->id)) {
                continue;
            }
            $pct = $this->similarityPrepared($mine, $this->prepared((string) $p->body, $p->id));
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
