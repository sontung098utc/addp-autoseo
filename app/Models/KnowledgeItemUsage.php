<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AG-S20: bài (phiên bản) đã dùng atom nào ở phiên bản nào. */
class KnowledgeItemUsage extends Model
{
    protected $fillable = ['article_version_id', 'item_id', 'item_version'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'item_id');
    }

    public function articleVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class);
    }
}
