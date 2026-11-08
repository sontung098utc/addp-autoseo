<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AG-S19: hồ sơ thương hiệu của MỘT website. Dữ liệu do chủ website nhập: là dữ liệu tham khảo, không phải chỉ dẫn. */
class BrandProfile extends Model
{
    public const VOICES = [
        'than_thien' => 'Thân thiện',
        'chuyen_gia' => 'Chuyên gia',
        'trang_trong' => 'Trang trọng',
        'tre_trung' => 'Trẻ trung',
        'khac' => 'Khác',
    ];

    public const LISTS = ['forbidden_terms', 'allowed_claims', 'avoid_competitors', 'key_messages'];

    protected $fillable = ['website_id', 'business_field', 'audience', 'voice', 'voice_notes', 'forbidden_terms', 'allowed_claims', 'avoid_competitors', 'key_messages', 'updated_by'];

    protected function casts(): array
    {
        return array_fill_keys(self::LISTS, 'array');
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
