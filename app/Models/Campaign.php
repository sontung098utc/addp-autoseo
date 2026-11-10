<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    public const STATUS_PLANNING = 'planning';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_DRAFT = 'draft';

    protected $fillable = [
        'website_id',
        'created_by',
        'name',
        'description',
        'status',
        'topic_cluster',
        'target_article_count',
        'target_keywords',
        'budget_limit',
        'settings',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'target_article_count' => 'integer',
            'target_keywords' => 'array',
            'budget_limit' => 'decimal:2',
            'settings' => 'array',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
