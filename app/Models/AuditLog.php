<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'action',
        'actor_id',
        'actor_role',
        'article_id',
        'article_version_id',
        'website_id',
        'reason',
        'self_approved',
        'extra',
        'at_utc',
    ];

    protected function casts(): array
    {
        return [
            'self_approved' => 'boolean',
            'extra' => 'array',
            'at_utc' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function articleVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
