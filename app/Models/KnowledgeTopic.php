<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** AG-S20: chủ đề, cây tối đa 2 cấp (cấp 2 có parent_id là cấp 1). */
class KnowledgeTopic extends Model
{
    protected $fillable = ['brain_id', 'website_id', 'parent_id', 'name'];

    public function brain(): BelongsTo
    {
        return $this->belongsTo(Brain::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class, 'topic_id');
    }

    public function fullName(): string
    {
        return $this->parent ? $this->parent->name.' › '.$this->name : $this->name;
    }
}
