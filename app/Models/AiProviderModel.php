<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một model do tài khoản AI trả về ở lần đồng bộ gần nhất (xem AiModelSync). */
class AiProviderModel extends Model
{
    protected $fillable = ['ai_provider_id', 'model_id', 'name', 'kind', 'status', 'first_seen_at', 'last_seen_at', 'new_until', 'gone_at', 'gone_until'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'new_until' => 'datetime', 'gone_at' => 'datetime', 'gone_until' => 'datetime'];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }
}
