<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-B3: một nguồn trong Sổ nguồn uy tín của một Trí tuệ. Do người/chuyên gia xếp bậc và xác nhận; `suggested` chưa có hiệu lực.
 */
class BrainSource extends Model
{
    public const TIERS = [
        '1' => 'Bậc 1: Chính thức',
        '2' => 'Bậc 2: Khoa học',
        '3' => 'Bậc 3: Báo chí uy tín',
        '4' => 'Bậc 4: Khác',
        'blocked' => 'Chặn',
    ];

    public const STATUSES = ['suggested' => 'Chưa xác nhận', 'confirmed' => 'Đã xác nhận'];

    protected $fillable = ['brain_id', 'name', 'domain', 'tier', 'status', 'note', 'confirmed_by', 'confirmed_at', 'created_by'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    public function brain(): BelongsTo
    {
        return $this->belongsTo(Brain::class);
    }

    /** Chỉ nguồn đã xác nhận và đã xếp bậc mới có hiệu lực. */
    public function isEffective(): bool
    {
        return $this->status === 'confirmed' && $this->tier !== null && array_key_exists($this->tier, self::TIERS);
    }
}
