<?php

namespace App\Services\Agent\Run;

use Illuminate\Support\Str;

/**
 * PHẠM VI "Giao làm lại": các phần đội AI được phép làm lại. Danh sách TRẮNG cố định (dàn ý, bài viết, ảnh, nguồn/kho).
 *
 * Lời nhắn của người dùng và đầu ra của AI chỉ có thể CHỌN trong danh sách này; mọi giá trị khác bị bỏ, nên lời nhắn không thể điều khiển công cụ nào ngoài
 * (đăng bài, đổi quy tắc, gọi mạng...). Quy tắc xác định ĐÈ lên AI: lời nhắn nói "giữ nguyên ảnh/nguồn/dàn ý" thì phần đó bị loại dù AI đề xuất. Không rõ thì
 * mặc định chỉ "bài viết" (rẻ nhất). "Bài viết" luôn có: làm lại nghĩa là viết lại chữ.
 */
final class RedoScope
{
    public const OUTLINE = 'outline';

    public const WRITE = 'write';

    public const IMAGES = 'images';

    public const SOURCES = 'sources';

    public const PARTS = [self::OUTLINE, self::WRITE, self::IMAGES, self::SOURCES];

    public const LABELS = [self::OUTLINE => 'dàn ý', self::WRITE => 'bài viết', self::IMAGES => 'ảnh', self::SOURCES => 'nguồn/kho'];

    /** Tỷ trọng chi phí thô của từng phần trong một lượt chạy đầy đủ (ƯỚC LƯỢNG THÔ chưa đo; tổng = 1). Viết + chấm + sửa chiếm phần lớn. */
    public const COST_SHARE = [self::WRITE => 0.40, self::OUTLINE => 0.10, self::IMAGES => 0.25, self::SOURCES => 0.25];

    /** Chuẩn hóa để so khớp: không dấu, chữ thường. */
    private static function norm(string $text): string
    {
        return ' '.Str::lower(Str::ascii(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '')).' ';
    }

    /** Từ chỉ "giữ nguyên/đừng đổi" đứng gần danh từ chỉ phần đó. */
    private static function keeps(string $n, string $noun): bool
    {
        return (bool) preg_match('/\b(giu( nguyen| lai)?|khong (can |muon |duoc )?(doi|thay|sua|lam lai|tao( moi| lai)?|thiet ke( lai)?|tim( them)?|nap( them)?)|dung (doi|sua|dong|thay|tao|lam lai|tim|nap)|khoi (doi|lam lai|tao|tim|nap))\b[^.;\n]{0,25}\b(?:'.$noun.')\b/u', $n)
            || (bool) preg_match('/\b(?:'.$noun.')\b[^.;\n]{0,15}\b(giu nguyen|nhu cu|khong doi|khong can doi)\b/u', $n);
    }

    private static function wants(string $n, string $verbs, string $noun): bool
    {
        return (bool) preg_match('/\b(?:'.$verbs.')\b[^.;\n]{0,25}\b(?:'.$noun.')\b/u', $n);
    }

    /**
     * Phần bị lời nhắn yêu cầu GIỮ NGUYÊN (quy tắc xác định, đè lên AI).
     *
     * @return list<string> các phần trong PARTS (không bao giờ gồm "bài viết")
     */
    public static function kept(string $message): array
    {
        $n = self::norm($message);
        $kept = [];
        if (self::keeps($n, 'anh|hinh anh|hinh minh hoa|anh bia')) {
            $kept[] = self::IMAGES;
        }
        if (self::keeps($n, 'nguon|tai lieu|kho|kien thuc')) {
            $kept[] = self::SOURCES;
        }
        if (self::keeps($n, 'dan y|bo cuc|cac muc')) {
            $kept[] = self::OUTLINE;
        }

        return $kept;
    }

    /**
     * Phần lời nhắn NÊU RÕ muốn làm lại (dùng khi không có AI phân loại). Chỉ là gợi ý; "giữ nguyên" vẫn thắng.
     *
     * @return list<string>
     */
    public static function requested(string $message): array
    {
        $n = self::norm($message);
        $out = [];
        if (self::wants($n, 'doi|thay|lam lai|tao lai|thiet ke lai|sua|them|bo sung', 'anh|hinh anh|anh bia|anh minh hoa') || preg_match('/\banh bia\b[^.;\n]{0,20}\b(chua|khong|sai|xau|khong hop)\b/u', $n)) {
            $out[] = self::IMAGES;
        }
        if (self::wants($n, 'them|bo sung|tim them|tim|cap nhat|doi', 'nguon|tai lieu')) {
            $out[] = self::SOURCES;
        }
        if (self::wants($n, 'them|bo|doi|lap lai|sua|sap xep lai|thieu', 'muc|dan y|bo cuc')) {
            $out[] = self::OUTLINE;
        }

        return $out;
    }

    /**
     * Chốt phạm vi từ lời nhắn và (nếu có) gợi ý của AI. Chỉ nhận phần thuộc danh sách trắng; "giữ nguyên ảnh/nguồn/dàn ý" trong lời nhắn loại phần đó.
     *
     * @param  array<int|string,mixed>|null  $aiParts  null = không có AI phân loại (dùng luật từ khóa)
     * @return array{parts: list<string>, kept: list<string>, defaulted: bool, source: string}
     */
    public static function resolve(string $message, ?array $aiParts = null): array
    {
        $proposed = $aiParts === null ? self::requested($message) : $aiParts;
        $set = [self::WRITE];
        foreach ($proposed as $p) {
            if (is_string($p) && in_array($p, self::PARTS, true)) {
                $set[] = $p; // giá trị ngoài danh sách trắng bị bỏ im lặng
            }
        }
        $kept = self::kept($message);
        $parts = array_values(array_filter(self::PARTS, fn ($p) => in_array($p, $set, true) && ! in_array($p, $kept, true)));

        return ['parts' => $parts, 'kept' => $kept, 'defaulted' => $parts === [self::WRITE], 'source' => $aiParts === null ? 'rules' : 'ai'];
    }

    /** @param list<string> $parts */
    public static function has(array $parts, string $part): bool
    {
        return in_array($part, $parts, true);
    }

    /**
     * Mô tả phạm vi bằng tiếng Việt dễ hiểu.
     *
     * @param  list<string>  $parts
     */
    public static function describe(array $parts): string
    {
        $do = array_map(fn ($p) => self::LABELS[$p], array_values(array_filter(self::PARTS, fn ($p) => in_array($p, $parts, true))));
        $keep = array_map(fn ($p) => self::LABELS[$p], array_values(array_filter([self::IMAGES, self::SOURCES], fn ($p) => ! in_array($p, $parts, true))));
        $text = $do === [self::LABELS[self::WRITE]] ? 'chỉ viết lại bài viết' : 'làm lại '.implode(' + ', $do);

        return $text.($keep !== [] ? ', giữ nguyên '.implode(' và ', $keep) : '');
    }

    /**
     * Ước tính chi phí theo phạm vi từ chi phí trung bình của một lượt chạy đầy đủ. Null nếu chưa có số liệu (không bịa).
     *
     * @param  list<string>  $parts
     */
    public static function estimate(?float $fullVnd, array $parts): ?float
    {
        if ($fullVnd === null) {
            return null;
        }
        $share = 0.0;
        foreach ($parts as $p) {
            $share += self::COST_SHARE[$p] ?? 0.0;
        }

        return round($fullVnd * min(1.0, $share), 0);
    }
}
