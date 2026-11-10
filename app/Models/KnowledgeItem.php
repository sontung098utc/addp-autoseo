<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AG-S20: atom kiến thức — 1 ý đơn lẻ có nguồn. Chỉ status=approved (còn hiệu lực) mới được AI dùng. */
class KnowledgeItem extends Model
{
    use SoftDeletes;

    public const TYPES = ['fact' => 'Sự kiện', 'price' => 'Giá/số liệu', 'policy' => 'Chính sách', 'process' => 'Quy trình', 'faq' => 'FAQ', 'messaging' => 'Giọng/thông điệp'];

    public const STATUSES = ['proposed' => 'Đề xuất', 'approved' => 'Đã duyệt', 'outdated' => 'Cũ', 'rejected' => 'Bị loại'];

    protected $fillable = [
        'brain_id', 'website_id', 'source_id', 'topic_id', 'type', 'title', 'body', 'entity', 'field', 'value', 'value_unit', 'tags', 'status',
        'valid_from', 'valid_until', 'confidence', 'source_span', 'created_by', 'approved_by', 'approved_at', 'content_hash', 'version', 'last_used_at',
    ];

    protected function casts(): array
    {
        return ['tags' => 'array', 'valid_from' => 'date', 'valid_until' => 'date', 'approved_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function brain(): BelongsTo
    {
        return $this->belongsTo(Brain::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(KnowledgeTopic::class, 'topic_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(KnowledgeItemVersion::class, 'item_id')->orderByDesc('version');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(KnowledgeItemUsage::class, 'item_id');
    }
}
