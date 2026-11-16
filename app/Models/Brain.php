<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * AG-B1: một "Trí tuệ" = một lĩnh vực tri thức (Y khoa, Giáo dục, Thương hiệu…). Mang HỒ SƠ LĨNH VỰC (hành vi: giọng, cấu trúc, thuật ngữ, lập luận)
 * và gom các mục kiến thức (atom) của lĩnh vực đó. Mẫu chỉ mang cấu trúc/quy tắc, không mang sự kiện.
 */
class Brain extends Model
{
    public const RELIANCE = [
        'free' => 'Tự do',
        'numbers' => 'Kiểm số liệu',
        'must_cite' => 'Bắt buộc nguồn',
    ];

    public const RISK = ['low' => 'Thấp', 'medium' => 'Vừa', 'high' => 'Cao'];

    protected $fillable = ['name', 'slug', 'domain_key', 'template_key', 'language', 'description', 'status', 'settings', 'created_by'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function reliance(): string
    {
        $v = (string) ($this->settings['ai_reliance'] ?? 'numbers');

        return array_key_exists($v, self::RELIANCE) ? $v : 'numbers';
    }

    public function riskClass(): string
    {
        $v = (string) ($this->settings['risk_class'] ?? 'low');

        return array_key_exists($v, self::RISK) ? $v : 'low';
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function websites(): BelongsToMany
    {
        return $this->belongsToMany(Website::class, 'brain_website')->withPivot('priority')->withTimestamps()->orderByPivot('priority');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(BrainProfile::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(KnowledgeSource::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(KnowledgeTopic::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(KnowledgeItem::class);
    }

    public function gaps(): HasMany
    {
        return $this->hasMany(KnowledgeGap::class);
    }

    /** AG-B3: Căn cứ chuẩn mực của Trí tuệ này (khác `sources()` là tài liệu nguồn của kho kiến thức). */
    public function authoritySources(): HasMany
    {
        return $this->hasMany(BrainSource::class);
    }
}
