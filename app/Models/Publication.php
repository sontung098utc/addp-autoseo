<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Publication extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEEDS_RECONCILE = 'needs_reconcile';
    public const STATUS_HELD = 'held';

    public const ALL_STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_PUBLISHING,
        self::STATUS_PUBLISHED,
        self::STATUS_FAILED,
        self::STATUS_NEEDS_RECONCILE,
        self::STATUS_HELD,
    ];

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

    public function isHeld(): bool
    {
        return $this->status === self::STATUS_HELD;
    }

    public function needsReconcile(): bool
    {
        return $this->status === self::STATUS_NEEDS_RECONCILE;
    }
}
