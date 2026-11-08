<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeGap;
use App\Models\KnowledgeItem;

/**
 * AG-S21: vòng học "khoảng trống kiến thức". Khoảng trống do Người viết ghi (KnowledgeContext) ở trạng thái 'open';
 * khi một mục ĐÃ DUYỆT mới khớp truy vấn của khoảng trống thì đánh dấu 'filled' (kèm mục đã bổ sung).
 * Khớp tất định: ≥ 60% từ khóa của truy vấn (bỏ từ ngắn/từ nối) xuất hiện trong tiêu đề + nội dung + thực thể + thẻ của mục.
 */
final class KnowledgeGaps
{
    private const MIN_RATIO = 0.6;

    public function __construct(private KnowledgeRetriever $retriever = new KnowledgeRetriever())
    {
    }

    public function matches(KnowledgeGap $gap, KnowledgeItem $item): bool
    {
        $terms = $this->retriever->terms($gap->query);
        if ($terms === []) {
            return false;
        }
        $hay = KnowledgeDedupe::normalize(implode(' ', [$item->title, $item->body, $item->entity, $item->field, implode(' ', (array) $item->tags)]));
        $hit = count(array_filter($terms, fn ($t) => str_contains($hay, $t)));

        return $hit / count($terms) >= self::MIN_RATIO;
    }

    /** Gọi khi một mục vừa được duyệt. @return int số khoảng trống được đánh dấu đã bổ sung */
    public function fillFrom(KnowledgeItem $item): int
    {
        if ($item->status !== 'approved') {
            return 0;
        }
        $n = 0;
        $gaps = KnowledgeGap::query()->where('status', 'open')
            ->when($item->website_id !== null, fn ($q) => $q->where('website_id', $item->website_id)) // mục chung bổ sung cho mọi website
            ->get();
        foreach ($gaps as $gap) {
            if ($this->matches($gap, $item)) {
                $gap->update(['status' => 'filled', 'filled_at' => now(), 'filled_item_id' => $item->id]);
                $n++;
            }
        }

        return $n;
    }
}
