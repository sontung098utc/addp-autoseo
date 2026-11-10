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
        // AG-S21: tách kho kiến thức bằng AI. Chưa cấu hình riêng thì KnowledgeAiIngest dùng route 'summary' (cùng bậc rẻ, cùng kiểu việc trích xuất/tóm tắt).
        'knowledge' => ['Nạp kiến thức (tách mục)', 'cheap'],
        // G3: khảo sát thị trường (mắt xích Từ khóa). Chưa cấu hình riêng thì dùng route 'summary' (KeywordAiResolver).
        'keyword_suggest' => ['Đề xuất từ khóa', 'cheap'],
        'keyword_cluster' => ['Gom cụm từ khóa', 'cheap'],
        // G5: làm mới bài MỘT PHẦN (PartialRefreshInterface): sinh lại meta (rẻ) và sửa các mục có lỗi (chuẩn), thay vì viết lại cả bài bằng bậc suy luận.
        'meta_regen' => ['Sinh lại tiêu đề SEO và meta', 'cheap'],
        'section_repair' => ['Sửa các mục có lỗi', 'standard'],
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
