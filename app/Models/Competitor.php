<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** AG-S24: đối thủ của MỘT website. Chỉ lưu tên miền đã chuẩn hóa (CompetitorDomain), không truy cập tên miền. */
class Competitor extends Model
{
    public const KINDS = ['truc_tiep' => 'Đối thủ trực tiếp', 'bao_chi' => 'Báo chí / tham khảo', 'khong_lien_quan' => 'Không liên quan'];

    protected $fillable = ['website_id', 'domain', 'brand_name', 'kind', 'note', 'added_by'];

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function gaps(): HasMany
    {
        return $this->hasMany(KeywordGap::class);
    }

    /** Tên miền bỏ đuôi (doithu.vn -> doithu); với doithu.com.vn -> doithu. */
    public function domainLabel(): string
    {
        $parts = explode('.', $this->domain);
        $n = count($parts);
        if ($n >= 3 && in_array($parts[$n - 2], ['com', 'net', 'org', 'edu', 'gov', 'co', 'ac'], true)) {
            return $parts[$n - 3];
        }

        return $parts[max(0, $n - 2)];
    }
}
