<?php

namespace App\Services\Knowledge\Discover;

use App\Models\Brain;
use App\Services\Brain\BrainSourceRegistry;

/**
 * Độ tin cậy NGUỒN tìm được trên web, do LUẬT CỐ ĐỊNH quyết (config/trusted_sources.php + Căn cứ chuẩn mực của Trí tuệ). AI không được tự phong "uy tín".
 * blocked: mạng xã hội, diễn đàn, chợ, affiliate (danh sách trong config) hoặc nguồn bị chặn trong Căn cứ chuẩn mực. Chặn thắng mọi thứ.
 * trusted: tên miền thuộc danh sách tin cậy (chung hoặc theo lĩnh vực) hoặc Căn cứ chuẩn mực đã xác nhận bậc 1-3.
 * neutral: còn lại. Luôn trả lý do bằng tiếng Việt để hiện cho người dùng.
 */
final class SourceTrust
{
    /**
     * @return array{level: 'trusted'|'neutral'|'blocked', reason: string, field: ?string}
     */
    public static function assess(string $url, ?Brain $brain = null, ?string $field = null): array
    {
        $host = BrainSourceRegistry::hostOf($url);
        if ($host === null) {
            return ['level' => 'blocked', 'reason' => 'Địa chỉ không hợp lệ.', 'field' => $field];
        }
        $reg = $brain ? BrainSourceRegistry::forBrains([$brain->id])->classify($host) : null;
        if ($reg !== null && $reg['tier'] === 'blocked') {
            return ['level' => 'blocked', 'reason' => 'Bị chặn trong Căn cứ chuẩn mực của Trí tuệ.', 'field' => $field];
        }
        if (self::matchAny($host, (array) config('trusted_sources.blocked', []))) {
            return ['level' => 'blocked', 'reason' => 'Mạng xã hội, diễn đàn, chợ hoặc nội dung do người dùng tạo: không dùng làm nguồn.', 'field' => $field];
        }
        if ($reg !== null && in_array($reg['tier'], ['1', '2', '3'], true)) {
            return ['level' => 'trusted', 'reason' => 'Có trong Căn cứ chuẩn mực đã xác nhận ('.$reg['source']->name.').', 'field' => $field];
        }
        if (self::matchAny($host, (array) config('trusted_sources.general', []))) {
            return ['level' => 'trusted', 'reason' => 'Tên miền cơ quan nhà nước hoặc giáo dục.', 'field' => $field];
        }
        $fields = (array) config('trusted_sources.fields', []);
        foreach ($field !== null && isset($fields[$field]) ? [$field => $fields[$field]] : $fields as $f => $list) {
            if (self::matchAny($host, (array) $list)) {
                return ['level' => 'trusted', 'reason' => 'Nằm trong danh sách nguồn tin cậy'.($f ? ' (lĩnh vực '.self::fieldLabel((string) $f).')' : '').'.', 'field' => (string) $f];
            }
        }

        return ['level' => 'neutral', 'reason' => 'Chưa có trong danh sách tin cậy: chỉ đề xuất, cần người duyệt. Thêm vào Căn cứ chuẩn mực để tin cậy.', 'field' => $field];
    }

    /** @return list<string> tên miền tin cậy của một lĩnh vực (dùng làm include_domains khi tìm). */
    public static function domainsFor(string $field): array
    {
        return array_values(array_filter((array) config('trusted_sources.fields.'.$field, []), fn ($d) => ! str_contains((string) $d, '*')));
    }

    public static function fieldLabel(string $f): string
    {
        return ['health' => 'sức khỏe', 'education' => 'giáo dục', 'finance' => 'tài chính', 'legal' => 'pháp lý'][$f] ?? $f;
    }

    /** @param list<string> $patterns "a.b" khớp a.b và tên miền con; "*.a.b" chỉ tên miền con (và chính a.b). */
    private static function matchAny(string $host, array $patterns): bool
    {
        foreach ($patterns as $p) {
            $p = strtolower(ltrim(trim((string) $p), '*.'));
            if ($p !== '' && ($host === $p || str_ends_with($host, '.'.$p))) {
                return true;
            }
        }

        return false;
    }
}
