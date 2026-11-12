<?php

namespace App\Services\Images;

use App\Models\BrandProfile;
use Illuminate\Support\Str;

/**
 * Soạn prompt ảnh bằng LUẬT (0 token). Khung tiếng Anh ngắn, chủ đề lấy từ từ khóa/tiêu đề H2 đã làm sạch.
 * Quy tắc cố định chống nội dung nguy hiểm luôn được nối vào cuối: không người thật/nổi tiếng, không logo/thương hiệu, không chữ trong ảnh,
 * không hình ảnh y khoa/thuốc, không "trước-sau", không cam kết kết quả. Từ cấm và đối thủ của hồ sơ doanh nghiệp bị gỡ khỏi chủ đề.
 */
final class ImagePromptBuilder
{
    public const RULES = 'No real people, no celebrities, no faces of identifiable persons, no logos, no brand names, no text, letters or numbers in the image, no watermark. No before-and-after comparison, no medical devices, pills or clinical scenes, no implied guarantee of results.';

    private const SAFE_HEALTH = ' Lifestyle or food or nature illustration style only, calm and generic.';

    private const BEFORE_AFTER = '/(tr[uư][oơ]́?c\s*(và|va|&|-)\s*sau|before\s*(and|&|-)\s*after)/iu';

    /** $hint: gợi ý cảnh từ dàn ý (mục đích mục, ý chính), đã làm sạch như chủ đề; bỏ trống thì như trước. */
    public static function build(string $role, string $keyword, ?string $heading, ?BrandProfile $brand, bool $health = false, ?string $hint = null): string
    {
        $topic = self::clean($keyword, $brand);
        $theme = $heading !== null ? self::clean($heading, $brand) : '';
        $field = $brand && $brand->business_field ? self::clean((string) $brand->business_field, $brand) : '';

        $p = $role === 'featured'
            ? "Wide editorial cover illustration for a Vietnamese blog article about \"{$topic}\"."
            : "Editorial illustration for a section titled \"{$theme}\" in a Vietnamese blog article about \"{$topic}\".";
        if ($field !== '') {
            $p .= " Business domain: {$field}.";
        }
        $scene = $hint !== null ? self::clean($hint, $brand) : '';
        if ($scene !== '') {
            $p .= " Scene idea (from the article outline): {$scene}.";
        }
        $p .= ' Clean modern style, natural light, uncluttered composition.';
        if ($health) {
            $p .= self::SAFE_HEALTH;
        }

        return $p.' '.self::RULES;
    }

    /** Chủ đề an toàn: bỏ ký tự điều khiển/ngoặc kép/thẻ, bỏ "trước-sau", bỏ từ cấm và tên đối thủ của hồ sơ, giới hạn độ dài. */
    public static function clean(string $text, ?BrandProfile $brand): string
    {
        $t = preg_replace('/[\x00-\x1F\x7F"<>`{}\[\]\\\\]+/u', ' ', strip_tags($text)) ?? '';
        $t = preg_replace(self::BEFORE_AFTER, ' ', $t) ?? $t;
        foreach (array_merge((array) ($brand?->forbidden_terms ?? []), (array) ($brand?->avoid_competitors ?? [])) as $term) {
            $term = is_scalar($term) ? trim((string) $term) : '';
            if ($term !== '') {
                $t = preg_replace('/'.preg_quote($term, '/').'/iu', ' ', $t) ?? $t;
            }
        }

        return trim(Str::limit(preg_replace('/\s+/u', ' ', $t) ?? '', 90, ''));
    }
}
