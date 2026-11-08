<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AG-S20: truy vấn của Người viết không tìm thấy atom đã duyệt nào → cần bổ sung kiến thức. */
class KnowledgeGap extends Model
{
    protected $fillable = ['website_id', 'query', 'article_id', 'count'];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
