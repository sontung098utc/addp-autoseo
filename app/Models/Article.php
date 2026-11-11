<?php

namespace App\Models;

use App\Observers\ArticleKeywordObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[ObservedBy(ArticleKeywordObserver::class)]
class Article extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'created_by',
        'primary_keyword',
        'secondary_keywords',
        'search_intent',
        'topic_cluster',
        'workflow_status',
        'kind',
        'origin',
        'origin_ref',
        'keyword_id',
        'cluster_id',
        'priority',
        'campaign_id',
        'guidance',
    ];

    protected function casts(): array
    {
        return [
            'secondary_keywords' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    /** Chiến dịch chứa bài (cha). Rỗng = bài lẻ. */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ArticleVersion::class);
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(ArticleVersion::class)->latestOfMany('version');
    }
}
