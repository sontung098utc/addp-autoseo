<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AG-S02: việc đang chờ người bổ sung dữ liệu; không chặn việc khác, quá hạn 24 giờ thì tự bỏ qua và ghi giới hạn. */
class CampaignWait extends Model
{
    protected $fillable = ['campaign_id', 'code', 'title', 'detail', 'status', 'expires_at', 'limit_note'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
