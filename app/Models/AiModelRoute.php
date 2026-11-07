<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AG-S11: một loại việc dùng model nào (chính + dự phòng) và trần chi phí mỗi lần gọi. */
class AiModelRoute extends Model
{
    /** Loại việc → [nhãn, bậc gợi ý]. Orchestrator hiện dùng outline/write/repair; summary dành cho tóm tắt audit. */
    public const TASKS = [
        'outline' => ['Lập dàn ý', 'cheap'],
        'write' => ['Viết bài', 'reasoning'],
        'repair' => ['Sửa bài theo lỗi chặn', 'standard'],
        'summary' => ['Tóm tắt audit', 'cheap'],
    ];

    public const TIERS = ['cheap' => 'Rẻ', 'standard' => 'Chuẩn', 'reasoning' => 'Suy luận'];

    protected $fillable = ['task', 'tier', 'primary_provider_id', 'primary_model', 'fallback_provider_id', 'fallback_model', 'cost_cap_vnd_per_call'];

    protected function casts(): array
    {
        return ['cost_cap_vnd_per_call' => 'float'];
    }

    public function primaryProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'primary_provider_id');
    }

    public function fallbackProvider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'fallback_provider_id');
    }
}
