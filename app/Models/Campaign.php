<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = ['website_id', 'created_by', 'name', 'status', 'keywords', 'daily_limit', 'last_tick_at', 'mode', 'daily_cost_cap_vnd', 'daily_draft_cap', 'source', 'brain_ids'];

    /** Khớp mặc định ở migration để mô hình mới tạo chưa nạp lại vẫn có giá trị đúng. */
    protected $attributes = ['mode' => 'balanced', 'daily_cost_cap_vnd' => 150000, 'daily_draft_cap' => 5, 'source' => 'plan'];

    protected function casts(): array
    {
        return ['keywords' => 'array', 'brain_ids' => 'array', 'last_tick_at' => 'datetime'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function waits(): HasMany
    {
        return $this->hasMany(CampaignWait::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
