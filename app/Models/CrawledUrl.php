<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawledUrl extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_id',
        'url',
        'http_status',
        'title',
        'meta_description',
        'canonical_url',
        'h1',
        'h1_count',
        'headings_summary',
        'images_count',
        'missing_alt_count',
        'internal_links_count',
        'external_links_count',
        'has_schema',
        'schema_types',
        'seo_score',
        'issues',
        'last_crawled_at',
    ];

    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'h1_count' => 'integer',
            'headings_summary' => 'array',
            'images_count' => 'integer',
            'missing_alt_count' => 'integer',
            'internal_links_count' => 'integer',
            'external_links_count' => 'integer',
            'has_schema' => 'boolean',
            'schema_types' => 'array',
            'seo_score' => 'integer',
            'issues' => 'array',
            'last_crawled_at' => 'datetime',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
