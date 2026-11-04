<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Publication extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'article_version_id',
        'publication_key',
        'scheduled_at_utc',
        'timezone',
        'status',
        'cms_post_id',
        'published_url',
        'attempt_count',
        'last_error',
        'published_at_utc',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at_utc' => 'datetime',
            'published_at_utc' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function articleVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class);
    }
}
