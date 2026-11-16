<?php

namespace App\Services\Brand;

use App\Models\BrandProfile;
use App\Models\Website;

/**
 * AG-S19: làm sạch, tính độ đầy đủ và dựng bản có cấu trúc để truyền cho AI/luật. Một nơi duy nhất để các phần khác đọc hồ sơ.
 */
final class BrandProfileData
{
    public const MAX_FIELD = 300;
    public const MAX_TEXT = 1000;
    public const MAX_ITEM = 120;
    public const MAX_LIST = 30;
    public const MAX_MESSAGES = 5;

    /** Trường bắt buộc để coi là "đủ": nhãn hiển thị. */
    public const REQUIRED = ['business_field' => 'Lĩnh vực & sản phẩm', 'audience' => 'Khách hàng mục tiêu', 'voice' => 'Giọng thương hiệu', 'key_messages' => 'Thông điệp chính'];

    public static function forWebsite(?int $websiteId): ?BrandProfile
    {
        return $websiteId ? BrandProfile::query()->where('website_id', $websiteId)->first() : null;
    }

    /** Bỏ thẻ HTML, ép khoảng trắng về một dấu cách, cắt độ dài. */
    public static function text(mixed $v, int $max): string
    {
        $s = strip_tags((string) $v);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = strip_tags($s);
        $s = preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $s) ?? '';

        return mb_substr(trim($s), 0, $max);
    }

    /**
     * @return list<string> đã làm sạch, bỏ rỗng, loại trùng (không phân biệt hoa thường)
     */
    public static function list(mixed $v, int $maxItems = self::MAX_LIST): array
    {
        $out = [];
        $seen = [];
        foreach ((array) $v as $item) {
            $t = self::text(is_scalar($item) ? $item : '', self::MAX_ITEM);
            $k = mb_strtolower($t);
            if ($t === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $t;
        }

        return array_slice($out, 0, $maxItems);
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function clean(array $in): array
    {
        $voice = (string) ($in['voice'] ?? '');

        return [
            'business_field' => self::text($in['business_field'] ?? '', self::MAX_FIELD) ?: null,
            'audience' => self::text($in['audience'] ?? '', self::MAX_TEXT) ?: null,
            'voice' => array_key_exists($voice, BrandProfile::VOICES) ? $voice : null,
            'voice_notes' => self::text($in['voice_notes'] ?? '', self::MAX_TEXT) ?: null,
            'forbidden_terms' => self::list($in['forbidden_terms'] ?? []),
            'allowed_claims' => self::list($in['allowed_claims'] ?? []),
            'avoid_competitors' => self::list($in['avoid_competitors'] ?? []),
            'key_messages' => self::list($in['key_messages'] ?? [], self::MAX_MESSAGES),
        ];
    }

    /** @return array{filled:int,total:int,percent:int,missing:list<string>} */
    public static function completeness(?BrandProfile $p): array
    {
        $missing = [];
        foreach (self::REQUIRED as $k => $label) {
            $v = $p?->{$k};
            if ($v === null || $v === '' || $v === []) {
                $missing[] = $label;
            }
        }
        $total = count(self::REQUIRED);
        $filled = $total - count($missing);

        return ['filled' => $filled, 'total' => $total, 'percent' => (int) round($filled / $total * 100), 'missing' => $missing];
    }

    public static function isComplete(?BrandProfile $p): bool
    {
        return self::completeness($p)['missing'] === [];
    }

    /**
     * Bản có cấu trúc truyền cho adapter AI (options['brand']); null nếu không có hồ sơ hoặc rỗng.
     *
     * @return array<string, mixed>|null
     */
    public static function forPrompt(?BrandProfile $p): ?array
    {
        if (! $p) {
            return null;
        }
        $a = [
            'business_field' => (string) $p->business_field,
            'audience' => (string) $p->audience,
            'voice' => $p->voice ? (BrandProfile::VOICES[$p->voice] ?? '') : '',
            'voice_notes' => (string) $p->voice_notes,
            'forbidden_terms' => array_values((array) $p->forbidden_terms),
            'allowed_claims' => array_values((array) $p->allowed_claims),
            'avoid_competitors' => array_values((array) $p->avoid_competitors),
            'key_messages' => array_values((array) $p->key_messages),
        ];
        $a['fields_used'] = count(array_filter($a, fn ($v) => $v !== '' && $v !== []));

        return $a['fields_used'] > 0 ? $a : null;
    }

    /** Chủ đề (từ khóa) có vẻ nằm ngoài lĩnh vực? Heuristic: không chung từ nào (>=3 ký tự) với lĩnh vực. Chỉ để cảnh báo. */
    public static function looksOffTopic(?BrandProfile $p, string $keyword): bool
    {
        $field = trim((string) $p?->business_field);
        if ($field === '' || trim($keyword) === '') {
            return false;
        }
        $tok = fn (string $s) => array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)) ?: [], fn ($w) => mb_strlen($w) >= 3));
        $kw = $tok($keyword);
        $fw = $tok($field);
        if ($kw === [] || $fw === []) {
            return false;
        }
        // Khớp cả khi một từ chứa từ kia (số ít/nhiều, hậu tố).
        foreach ($kw as $a) {
            foreach ($fw as $b) {
                if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
                    return false;
                }
            }
        }

        return true;
    }

}
