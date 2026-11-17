<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeItem;
use Illuminate\Support\Collection;

/** AG-S20: mâu thuẫn = cùng phạm vi (cùng website hoặc có một bên là "chung") + cùng thực thể + cùng trường nhưng KHÁC giá trị. */
final class KnowledgeConflicts
{
    private static function key(?string $s): string
    {
        return KnowledgeDedupe::normalize((string) $s);
    }

    /** @return Collection<int,KnowledgeItem> các mục đang mâu thuẫn với $item (đề xuất hoặc đã duyệt) */
    public function for(KnowledgeItem $item): Collection
    {
        if (self::key($item->entity) === '' || self::key($item->field) === '' || self::key($item->value) === '') {
            return collect();
        }

        return KnowledgeItem::query()
            ->whereIn('status', ['proposed', 'approved'])
            ->when($item->exists, fn ($q) => $q->where('id', '!=', $item->id))
            // Mục của website xung đột với mục cùng website hoặc mục chung; mục chung xung đột với mọi mục.
            ->when($item->website_id !== null, fn ($q) => $q->where(fn ($q2) => $q2->whereNull('website_id')->orWhere('website_id', $item->website_id)))
            ->whereNotNull('entity')->whereNotNull('field')->whereNotNull('value')
            ->get()
            ->filter(fn (KnowledgeItem $o) => self::key($o->entity) === self::key($item->entity)
                && self::key($o->field) === self::key($item->field)
                && self::key($o->value.' '.$o->value_unit) !== self::key($item->value.' '.$item->value_unit))
            ->values();
    }

    public function has(KnowledgeItem $item): bool
    {
        return $this->for($item)->isNotEmpty();
    }

    /**
     * Mọi cặp mâu thuẫn hiện có (cho tab Cảnh báo). Mỗi cặp một lần.
     *
     * @return list<array{a:KnowledgeItem,b:KnowledgeItem}>
     */
    public function all(): array
    {
        $items = KnowledgeItem::query()->whereIn('status', ['proposed', 'approved'])
            ->whereNotNull('entity')->whereNotNull('field')->whereNotNull('value')->orderBy('id')->get();
        // Gom theo (thực thể, trường) trong bộ nhớ thay vì truy vấn lại cho từng mục (hàng trăm truy vấn/trang). Cùng luật với for():
        // hai mục mâu thuẫn khi cùng thực thể + trường, khác giá trị (kèm đơn vị), và cùng phạm vi (cùng website hoặc có một bên là "chung").
        $groups = [];
        foreach ($items as $it) {
            if (self::key($it->entity) === '' || self::key($it->field) === '' || self::key($it->value) === '') {
                continue;
            }
            $groups[self::key($it->entity)."\x1f".self::key($it->field)][] = $it;
        }
        $pairs = [];
        foreach ($groups as $g) {
            for ($x = 0, $n = count($g); $x < $n; $x++) {
                for ($y = $x + 1; $y < $n; $y++) {
                    [$a, $b] = [$g[$x], $g[$y]]; // đã sắp theo id nên a->id < b->id
                    $sameScope = $a->website_id === null || $b->website_id === null || (int) $a->website_id === (int) $b->website_id;
                    if ($sameScope && self::key($a->value.' '.$a->value_unit) !== self::key($b->value.' '.$b->value_unit)) {
                        $pairs[] = ['a' => $a, 'b' => $b];
                    }
                }
            }
        }
        usort($pairs, fn ($p, $q) => [$p['a']->id, $p['b']->id] <=> [$q['a']->id, $q['b']->id]);

        return $pairs;
    }
}
