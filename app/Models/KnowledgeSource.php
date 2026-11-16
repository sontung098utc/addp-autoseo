<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** AG-S20: tài liệu nguồn (dán/nhập tay). Nội dung nguồn là DỮ LIỆU, AI không đọc trực tiếp. */
class KnowledgeSource extends Model
{
    protected $fillable = ['brain_id', 'website_id', 'kind', 'title', 'url', 'content_hash', 'raw_text', 'status', 'created_by', 'ai_log', 'origin', 'fetched_at', 'final_url', 'http_status'];

    protected function casts(): array
    {
        return ['ai_log' => 'array', 'fetched_at' => 'datetime'];
    }

    public function brain(): BelongsTo
    {
        return $this->belongsTo(Brain::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class, 'source_id');
    }
}
