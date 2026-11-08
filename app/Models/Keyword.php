<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    protected $fillable = ['website_id', 'keyword', 'keyword_key', 'volume', 'difficulty', 'intent', 'source', 'cluster_id', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['volume' => 'integer', 'difficulty' => 'integer'];
    }

    /** Khóa chuẩn hóa: chữ thường + gộp khoảng trắng (cùng luật với CsvKeywordParser::key). */
    public static function keyFor(string $keyword): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $keyword)));
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(KeywordCluster::class, 'cluster_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
