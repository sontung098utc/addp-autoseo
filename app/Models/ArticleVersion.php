<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'article_id',
        'version',
        'title',
        'slug',
        'outline',
        'html_content',
        'seo_title',
        'meta_description',
        'focus_keyword',
        'faq_schema',
        'sources_cited',
        'ai_provider',
        'ai_model',
        'prompt_version',
        'tokens_used',
        'cost_vnd',
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'outline' => 'array',
            'faq_schema' => 'array',
            'sources_cited' => 'array',
            'tokens_used' => 'integer',
            'cost_vnd' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    public function mediaAssets(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'article_version_media')
            ->withPivot(['is_featured', 'position', 'custom_alt'])
            ->withTimestamps();
    }

    public function getFeaturedMediaAssetAttribute(): ?MediaAsset
    {
        return $this->mediaAssets()->wherePivot('is_featured', true)->first()
            ?: $this->mediaAssets()->orderByPivot('position')->first();
    }
}
