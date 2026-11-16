<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    protected $fillable = [
        'website_id', 'job_type', 'article_id', 'parent_run_id', 'campaign_id', 'triggered_by', 'trigger', 'status', 'trust_tier', 'risk_level', 'ai_mode',
        'outcome_reason', 'final_version_id', 'repair_rounds', 'tokens_used', 'cost_vnd', 'started_at', 'finished_at',
        'input_refs', 'output_ref', 'tokens_budget',
    ];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'cost_vnd' => 'float', 'input_refs' => 'array', 'output_ref' => 'array'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    public function finalVersion(): BelongsTo
    {
        return $this->belongsTo(ArticleVersion::class, 'final_version_id');
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AgentRunStep::class)->orderBy('seq');
    }
}
