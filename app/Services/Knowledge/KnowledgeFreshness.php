<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeItem;
use App\Models\KnowledgeSource;
use Illuminate\Support\Collection;

/** AG-S20: cảnh báo mục cũ — quá hiệu lực, lâu không dùng, hoặc nguồn URL đã đổi nội dung. */
final class KnowledgeFreshness
{
    public function __construct(private ?int $unusedDays = null)
    {
    }

    public function unusedDays(): int
    {
        return $this->unusedDays ?? (int) config('knowledge.unused_days', 180);
    }

    /** @return Collection<int,KnowledgeItem> đã duyệt nhưng quá valid_until */
    public function expired(): Collection
    {
        return KnowledgeItem::query()->where('status', 'approved')->whereNotNull('valid_until')->whereDate('valid_until', '<', now()->toDateString())->get();
    }

    /** @return Collection<int,KnowledgeItem> đã duyệt, chưa dùng trong N ngày (tính từ lần dùng cuối hoặc ngày duyệt) */
    public function unused(): Collection
    {
        $cut = now()->subDays($this->unusedDays());

        return KnowledgeItem::query()->where('status', 'approved')
            ->where(fn ($q) => $q->where('last_used_at', '<', $cut)->orWhere(fn ($q2) => $q2->whereNull('last_used_at')->where(fn ($q3) => $q3->where('approved_at', '<', $cut)->orWhereNull('approved_at'))))
            ->get();
    }

    /** Nguồn URL: so băm lần quét mới với băm đã lưu. Không tự quét web (chỉ so khi được đưa nội dung mới). */
    public function sourceChanged(KnowledgeSource $source, string $newText): bool
    {
        return $source->kind === 'url' && $source->content_hash !== null && $source->content_hash !== KnowledgeDedupe::hash('', $newText);
    }

    /** Chuyển mục quá hạn sang 'outdated' để AI ngừng dùng; trả số mục đã chuyển. */
    public function markExpired(): int
    {
        $n = 0;
        foreach ($this->expired() as $item) {
            $item->update(['status' => 'outdated']);
            $n++;
        }

        return $n;
    }
}
