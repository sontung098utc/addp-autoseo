<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordCluster extends Model
{
    protected $fillable = ['website_id', 'name', 'intent'];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(Keyword::class, 'cluster_id');
    }
}
