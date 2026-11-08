<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AG-S20: truy vấn của Người viết không tìm thấy atom đã duyệt nào → cần bổ sung kiến thức. */
class KnowledgeGap extends Model
{
    protected $fillable = ['website_id', 'query', 'article_id', 'count', 'status', 'filled_at', 'filled_item_id'];

    protected function casts(): array
    {
        return ['filled_at' => 'datetime'];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function filledItem(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'filled_item_id');
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
