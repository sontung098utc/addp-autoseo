<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AG-S17: gán model và kỹ năng cho một vai trò của đội AI. Không chứa khóa API. */
class AgentRoleAssignment extends Model
{
    protected $fillable = ['role_key', 'provider_id', 'model', 'fallback_provider_id', 'fallback_model', 'enabled', 'skills', 'updated_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'skills' => 'array'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }

    public function fallbackProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'fallback_provider_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
