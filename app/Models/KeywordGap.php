<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AG-S24: khoảng trống từ khóa (đối thủ có hạng / ta chưa có). volume NULL = chưa có dữ liệu. */
class KeywordGap extends Model
{
    public const STATUSES = ['moi' => 'Mới', 'da_them_tu_khoa' => 'Đã thêm từ khóa', 'bo_qua' => 'Bỏ qua'];

    public const SOURCES = ['csv' => 'CSV', 'gsc' => 'Search Console', 'crawl' => 'Trang đã quét', 'tay' => 'Nhập tay'];

    protected $fillable = ['website_id', 'competitor_id', 'keyword', 'competitor_rank', 'our_rank', 'volume', 'source', 'priority_score', 'status', 'keyword_id'];

    protected function casts(): array
    {
        return ['competitor_rank' => 'integer', 'our_rank' => 'integer', 'volume' => 'integer', 'priority_score' => 'integer'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(Competitor::class);
    }

    public function storedKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
