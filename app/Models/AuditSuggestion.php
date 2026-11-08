<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditSuggestion extends Model
{
    protected $fillable = ['website_id', 'seo_audit_id', 'crawled_url_id', 'url', 'article_id', 'issue_codes', 'severity', 'priority', 'status', 'article_task_id', 'decided_by', 'decided_at'];

    protected function casts(): array
    {
        return ['issue_codes' => 'array', 'priority' => 'integer', 'decided_at' => 'datetime'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_task_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isArticle(): bool
    {
        return $this->article_id !== null;
    }
}
