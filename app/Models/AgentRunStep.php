<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRunStep extends Model
{
    protected $fillable = [
        'agent_run_id', 'seq', 'stage', 'role', 'status', 'title', 'detail', 'data', 'model', 'tokens_used', 'cost_vnd', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'cost_vnd' => 'float'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }
}
