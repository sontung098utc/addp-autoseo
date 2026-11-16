<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeItemVersion extends Model
{
    protected $fillable = ['item_id', 'version', 'title', 'body', 'entity', 'field', 'value', 'value_unit', 'status', 'changed_by', 'note'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'item_id');
    }
}
