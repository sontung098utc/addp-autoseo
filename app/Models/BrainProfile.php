<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AG-B1: Hồ sơ lĩnh vực (tầng 1). Là HÀNH VI (giọng, cấu trúc, thuật ngữ, lập luận, bài mẫu) chứ không phải sự kiện.
 * Nội dung do người dùng nhập: là dữ liệu tham khảo, luôn hiển thị qua escape và vào prompt trong khung dữ liệu.
 */
class BrainProfile extends Model
{
    public const MAX_EXEMPLARS = 5;

    /** Các trường tính "độ đầy đủ" (khóa => nhãn). */
    public const FIELDS = [
        'voice.tone' => 'Tông giọng',
        'voice.formality' => 'Mức trang trọng',
        'voice.pronouns' => 'Cách xưng hô',
        'voice.do' => 'Nên',
        'voice.dont' => 'Tránh',
        'structure.article_types' => 'Khung loại bài',
        'terminology.preferred' => 'Thuật ngữ ưu tiên',
        'terminology.abbreviations' => 'Viết tắt',
        'reasoning.rules' => 'Quy tắc lập luận',
        'exemplars' => 'Bài mẫu',
        'rubric_notes' => 'Rubric riêng',
    ];

    protected $fillable = ['brain_id', 'voice', 'structure', 'terminology', 'reasoning', 'rubric_notes', 'exemplars', 'updated_by'];

    protected function casts(): array
    {
        return ['voice' => 'array', 'structure' => 'array', 'terminology' => 'array', 'reasoning' => 'array', 'exemplars' => 'array'];
    }

    public function brain(): BelongsTo
    {
        return $this->belongsTo(Brain::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return array{0:int,1:int} [số trường đã điền, tổng số trường] */
    public function completeness(): array
    {
        $filled = 0;
        foreach (array_keys(self::FIELDS) as $path) {
            if ($this->hasValue($path)) {
                $filled++;
            }
        }

        return [$filled, count(self::FIELDS)];
    }

    public function hasValue(string $path): bool
    {
        if ($path === 'rubric_notes') {
            return trim((string) $this->rubric_notes) !== '';
        }
        if ($path === 'exemplars') {
            return collect((array) $this->exemplars)->contains(fn ($e) => trim((string) ($e['text_or_url'] ?? '')) !== '');
        }
        [$col, $key] = explode('.', $path);
        $v = (array) ($this->{$col} ?? []);
        $x = $v[$key] ?? null;
        if ($path === 'structure.article_types') {
            return collect((array) $x)->contains(fn ($t) => ! empty($t['outline']) && array_filter((array) $t['outline'], fn ($o) => trim((string) $o) !== '') !== []);
        }
        if ($path === 'terminology.preferred') {
            return collect((array) $x)->contains(fn ($t) => trim((string) ($t['term'] ?? '')) !== '');
        }
        if (is_array($x)) {
            return array_filter($x, fn ($o) => trim((string) $o) !== '') !== [];
        }

        return trim((string) $x) !== '';
    }
}
