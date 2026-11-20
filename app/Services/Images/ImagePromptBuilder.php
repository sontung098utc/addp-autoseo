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
    public const RULES = 'No real people, no celebrities, no faces of identifiable persons, no logos, no brand names, no text, letters or numbers in the image, no watermark. No before-and-after comparison, no implied guarantee of results, no graphic, bloody or distressing imagery.';

    /** Khung an toàn khi người duyệt YÊU CẦU ảnh có chữ: bỏ điều cấm chữ, nhưng chỉ cho đúng các cụm chữ đã chốt (ngắn, tiếng Việt có dấu). Mọi điều cấm khác giữ nguyên. */
    public const RULES_TEXT_HEAD = 'No real people, no celebrities, no faces of identifiable persons, no logos, no brand names, no watermark.';

    public const RULES_TAIL = 'No before-and-after comparison, no implied guarantee of results, no graphic, bloody or distressing imagery.';

    /** Người duyệt đòi chữ/nhãn/tiêu đề/số liệu trên ảnh. Chỉ khớp khi nhắc tới ảnh gần đó, để câu "viết chữ in đậm" trong bài không bị nhầm. */
    private const TEXT_REQUEST = '/(?:\b(?:anh|hinh|infographic|bia|minh hoa)\b[^.;\n]{0,40}\b(?:co|chen|ghi|kem|them|dat|hien thi|viet)\b[^.;\n]{0,20}\b(?:chu|text|tieu de|nhan|so lieu|caption|dong chu)\b|\b(?:chu|text|tieu de|nhan|so lieu)\b[^.;\n]{0,20}\b(?:tren|trong|len|o)\s+(?:anh|hinh)\b|\bco\s+(?:chu|text)\b|\binfographic\b)/u';

    private const SAFE_HEALTH = ' Friendly, calm, stylized illustration. Health themes (for example a glucose meter, a heart icon, healthy food, a walking person silhouette) are fine, kept non-graphic and without readable text.';

    private const BEFORE_AFTER = '/(tr[uư][oơ]́?c\s*(và|va|&|-)\s*sau|before\s*(and|&|-)\s*after)/iu';

    /** $hint: gợi ý cảnh từ dàn ý (mục đích mục, ý chính), đã làm sạch như chủ đề; bỏ trống thì như trước. */
    public static function build(string $role, string $keyword, ?string $heading, ?BrandProfile $brand, bool $health = false, ?string $hint = null, bool $withField = true): string
    {
        $topic = self::clean($keyword, $brand);
        $theme = $heading !== null ? self::clean($heading, $brand) : '';
        $field = $withField && $brand && $brand->business_field ? self::clean((string) $brand->business_field, $brand) : '';

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

    /** Mô tả do Họa viên thiết kế (đã qua ImageDesigner::safePrompt) + khung an toàn cố định luôn nối vào cuối. */
    public static function finish(string $designed, bool $health = false): string
    {
        return rtrim($designed).($health ? self::SAFE_HEALTH : '').' '.self::RULES;
    }

    /** Mô tả họa viên + khung an toàn; $texts không rỗng thì cho phép đúng các cụm chữ đó trong ảnh (xem RULES_TEXT_HEAD). @param list<string> $texts */
    public static function finishWithText(string $designed, bool $health, array $texts): string
    {
        return rtrim($designed).($health ? self::SAFE_HEALTH : '').' '.self::rulesWithText($texts);
    }

    /** @param list<string> $texts */
    public static function rulesWithText(array $texts): string
    {
        $texts = self::cleanTexts($texts);
        if ($texts === []) {
            return self::RULES;
        }
        $list = implode(' / ', array_map(fn ($t) => '"'.$t.'"', $texts));

        return self::RULES_TEXT_HEAD.' The ONLY text allowed in the image is exactly these Vietnamese words, rendered large, sharp, high-contrast and spelled exactly with correct diacritics: '.$list.'. Keep text short and legible; no other letters, numbers, captions or small print anywhere in the image. '.self::RULES_TAIL;
    }

    /** Thay khung "không chữ" cuối prompt bằng khung cho phép đúng các cụm chữ; prompt không có khung thì nối thêm. @param list<string> $texts */
    public static function allowText(string $prompt, array $texts): string
    {
        $texts = self::cleanTexts($texts);
        if ($texts === []) {
            return $prompt;
        }
        $base = str_contains($prompt, self::RULES) ? trim(str_replace(self::RULES, '', $prompt)) : rtrim($prompt);

        return $base.' '.self::rulesWithText($texts);
    }

    /** Cụm chữ trên ảnh: bỏ thẻ/ký tự điều khiển/ngoặc, ≤ 48 ký tự mỗi cụm, tối đa 3 cụm. @param  array<int,mixed>  $texts @return list<string> */
    public static function cleanTexts(array $texts, ?BrandProfile $brand = null): array
    {
        $out = [];
        foreach ($texts as $t) {
            if (! is_scalar($t)) {
                continue;
            }
            $c = trim(self::clean((string) $t, $brand), " .,;:-");
            $c = trim(mb_substr($c, 0, 48));
            if (mb_strlen($c) >= 2 && ! in_array($c, $out, true)) {
                $out[] = $c;
            }
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }

    /** Lời nhắn có đòi ảnh có chữ không (luật từ khóa). */
    public static function wantsText(string $message): bool
    {
        $n = ' '.Str::lower(Str::ascii(preg_replace('/\s+/u', ' ', strip_tags($message)) ?? '')).' ';
        if (preg_match('/\b(khong|dung|bo|tranh)\s+(can\s+|co\s+)?(chu|text)\b/u', $n)) {
            return false;
        }

        return (bool) preg_match(self::TEXT_REQUEST, $n);
    }

    /** Cụm chữ người duyệt tự gõ trong ngoặc kép/«»/“” (nếu có). @return list<string> */
    public static function quotedTexts(string $message): array
    {
        preg_match_all('/[«“"]([^»”"\n]{2,60})[»”"]/u', strip_tags($message), $m);

        return self::cleanTexts($m[1] ?? []);
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
