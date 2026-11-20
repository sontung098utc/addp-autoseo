<?php

namespace App\Services\Agent\Run;

use Illuminate\Support\Str;

/**
 * PHẠM VI "Giao làm lại": các phần đội AI được phép làm lại. Danh sách TRẮNG cố định (dàn ý, bài viết, ảnh, nguồn/kho).
 *
 * Lời nhắn của người dùng và đầu ra của AI chỉ có thể CHỌN trong danh sách này; mọi giá trị khác bị bỏ, nên lời nhắn không thể điều khiển công cụ nào ngoài
 * (đăng bài, đổi quy tắc, gọi mạng...). Quy tắc xác định ĐÈ lên AI: lời nhắn nói "giữ nguyên ảnh/nguồn/dàn ý/bài viết" hoặc "CHỈ làm ảnh" thì phần đó bị loại/giữ dù AI đề xuất.
 * Không rõ thì mặc định chỉ "bài viết" (rẻ nhất). Trước đây "bài viết" luôn bị ép vào mọi lượt nên "chỉ sửa ảnh" vẫn chạy cả viết; nay "bài viết" chỉ có khi được yêu cầu,
 * hoặc khi không còn bản cũ để giữ, hoặc khi đổi dàn ý (đổi dàn ý nghĩa là phải viết lại).
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
        return (bool) preg_match('/\b(giu( nguyen| lai)?|khong (can |muon |duoc )?(doi|thay|sua|lam lai|tao( moi| lai)?|thiet ke( lai)?|tim( them)?|nap( them)?)|dung (doi|sua|dong|thay|tao|lam lai|tim|nap)|khoi (doi|lam lai|tao|tim|nap))\b[^.;,\n]{0,25}\b(?:'.$noun.')\b/u', $n)
            || (bool) preg_match('/\b(?:'.$noun.')\b[^.;,\n]{0,15}\b(giu nguyen|nhu cu|khong doi|khong can doi)\b/u', $n);
    }

    /**
     * "Giữ nguyên bài viết": chặt hơn các phần khác vì "bài" xuất hiện trong mọi lời nhắn ("Mở bài dài dòng, giữ nguyên ảnh" KHÔNG phải giữ nguyên bài).
     * Chỉ khớp khi cụm "giữ nguyên/không đổi" đứng sát (≤ 12 ký tự, không qua dấu câu) trước hoặc sau danh từ bài viết/nội dung/văn bản/bài.
     */
    private static function keepsWrite(string $n): bool
    {
        $noun = '(?:bai viet|noi dung|van ban|bai(?!\s+(?:dai|viet|nay co|can)\b))';
        $keep = '(?:giu( nguyen| lai)?|khong (?:can |muon |duoc )?(?:doi|thay|sua|viet lai|lam lai)|dung (?:doi|sua|viet lai|lam lai)|khoi (?:doi|viet lai|lam lai))';

        return (bool) preg_match('/\b'.$keep.'\b[^.;,\n]{0,12}\b'.$noun.'\b/u', $n)
            || (bool) preg_match('/\b'.$noun.'\s{0,3}(?:giu nguyen|nhu cu|khong doi|khong can doi)\b/u', $n);
    }

    private static function wants(string $n, string $verbs, string $noun): bool
    {
        return (bool) preg_match('/\b(?:'.$verbs.')\b[^.;\n]{0,25}\b(?:'.$noun.')\b/u', $n);
    }

    /**
     * Phần bị lời nhắn yêu cầu GIỮ NGUYÊN (quy tắc xác định, đè lên AI).
     *
     * @return list<string> các phần trong PARTS
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
        if (self::keepsWrite($n)) {
            $kept[] = self::WRITE;
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
        if (self::wants($n, 'doi|thay|lam( lai)?|tao( lai| moi)?|thiet ke( lai)?|ve( lai)?|sua|them|bo sung', 'anh|hinh anh|anh bia|anh minh hoa|infographic') || preg_match('/\banh bia\b[^.;\n]{0,20}\b(chua|khong|sai|xau|khong hop)\b/u', $n)) {
            $out[] = self::IMAGES;
        }
        if (self::wants($n, 'them|bo sung|tim them|tim|cap nhat|doi', 'nguon|tai lieu')) {
            $out[] = self::SOURCES;
        }
        if (self::wants($n, 'them|bo|doi|lap lai|sua|sap xep lai|thieu', 'muc|dan y|bo cuc')) {
            $out[] = self::OUTLINE;
        }
        // Câu hỗn hợp ("viết lại mở bài và thêm ảnh"): nhắc tới chữ thì vẫn viết lại; chỉ nói về ảnh/nguồn thì không ép viết.
        if ($out !== [] && preg_match('/\b(viet|mo bai|ket bai|ket luan|doan van|giong van|van phong|do dai|ngan hon|dai hon|rut gon|so lieu|trich dan|tu khoa|tieu de bai|cau chu|noi dung)\b/u', $n)) {
            $out[] = self::WRITE;
        }

        return $out;
    }

    /**
     * "CHỈ làm X": lời nhắn giới hạn rõ phạm vi ("chỉ tạo lại ảnh", "chỉ cần thêm nguồn", "chỉ sửa ảnh bìa"). Trả về đúng các phần được nêu sau chữ "chỉ",
     * hoặc null nếu không có giới hạn rõ. Chỉ tính khi cụm sau "chỉ" nêu danh từ của một phần và KHÔNG nêu bài viết/nội dung.
     *
     * @return list<string>|null
     */
    public static function exclusive(string $message): ?array
    {
        $n = self::norm($message);
        $parts = [];
        if (preg_match_all('/\bchi\b(?:\s+can|\s+muon|\s+hay)?([^.;,\n]{0,70})/u', $n, $m)) {
            foreach ($m[1] as $clause) {
                if (preg_match('/\b(bai viet|noi dung|van ban|viet lai|viet bai|giong van|doan van)\b/u', $clause)) {
                    continue;
                }
                if (preg_match('/\b(anh|hinh anh|hinh minh hoa|anh bia|infographic)\b/u', $clause)) {
                    $parts[] = self::IMAGES;
                }
                if (preg_match('/\b(nguon|tai lieu|kho|kien thuc)\b/u', $clause)) {
                    $parts[] = self::SOURCES;
                }
                if (preg_match('/\b(dan y|bo cuc|cac muc)\b/u', $clause)) {
                    $parts[] = self::OUTLINE;
                }
            }
        }
        $parts = array_values(array_unique($parts));

        return $parts === [] ? null : $parts;
    }

    /**
     * Chốt phạm vi từ lời nhắn và (nếu có) gợi ý của AI. Chỉ nhận phần thuộc danh sách trắng. Thứ tự ưu tiên của quy tắc xác định (đè lên AI):
     * 1) "chỉ làm X" → đúng X; 2) "giữ nguyên Y" → loại Y (kể cả bài viết); 3) đổi dàn ý kéo theo viết lại; 4) không rõ gì → chỉ bài viết.
     * $hasPrevious=false (bài chưa có bản nào để giữ): bắt buộc có bài viết vì không có gì để dùng lại.
     *
     * @param  array<int|string,mixed>|null  $aiParts  null = không có AI phân loại (dùng luật từ khóa)
     * @return array{parts: list<string>, kept: list<string>, defaulted: bool, source: string, exclusive: bool}
     */
    public static function resolve(string $message, ?array $aiParts = null, bool $hasPrevious = true): array
    {
        $only = self::exclusive($message);
        $proposed = $aiParts === null || $aiParts === [] ? self::requested($message) : $aiParts; // AI không nêu phần nào thì dùng luật từ khóa thay vì coi như "chỉ viết"
        $set = [];
        foreach ($only ?? $proposed as $p) {
            if (is_string($p) && in_array($p, self::PARTS, true)) {
                $set[] = $p; // giá trị ngoài danh sách trắng bị bỏ im lặng
            }
        }
        $kept = self::kept($message);
        $proposedAny = $set !== [];
        if (in_array(self::OUTLINE, $set, true)) {
            $set[] = self::WRITE; // đổi dàn ý nghĩa là phải viết lại theo dàn ý mới
        }
        if ($set === [] || ! $hasPrevious) {
            $set[] = self::WRITE;
        }
        $parts = array_values(array_filter(self::PARTS, fn ($p) => in_array($p, $set, true) && (! in_array($p, $kept, true) || ! $hasPrevious && $p === self::WRITE)));
        if ($parts === []) { // mọi phần được đề xuất đều bị giữ nguyên: chữ bị giữ thì KHÔNG làm gì (đừng tự tạo lại ảnh người dùng bảo giữ); không thì quay về phần rẻ nhất
            $parts = in_array(self::WRITE, $kept, true) ? [] : [self::WRITE];
        }

        return ['parts' => $parts, 'kept' => $kept, 'defaulted' => ! $proposedAny && $parts === [self::WRITE], 'source' => $aiParts === null ? 'rules' : 'ai', 'exclusive' => $only !== null];
    }

    /**
     * Chỉ dẫn lưu theo bài có NÊU phạm vi rõ không ("chỉ tạo ảnh", "giữ nguyên bài viết/ảnh/nguồn"). Chặt hơn requested(): chỉ dẫn là lời dặn lâu dài nên câu như
     * "thêm ảnh minh họa dễ hiểu" là hướng nội dung, không phải lệnh bỏ qua bước viết.
     */
    public static function mentionsScope(string $message): bool
    {
        return self::exclusive($message) !== null || self::kept($message) !== [];
    }

    /** Phạm vi có bỏ qua bước viết không (chỉ ảnh/nguồn, giữ nguyên chữ của bản cũ). @param list<string> $parts */
    public static function skipsWriting(array $parts): bool
    {
        return ! in_array(self::WRITE, $parts, true) && ! in_array(self::OUTLINE, $parts, true);
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
        if ($parts === []) {
            return 'không làm gì (lời nhắn yêu cầu giữ nguyên mọi phần)';
        }
        $do = array_map(fn ($p) => self::LABELS[$p], array_values(array_filter(self::PARTS, fn ($p) => in_array($p, $parts, true))));
        $keep = array_map(fn ($p) => self::LABELS[$p], array_values(array_filter([self::IMAGES, self::SOURCES], fn ($p) => ! in_array($p, $parts, true))));
        if (self::skipsWriting($parts)) { // không viết lại: nói rõ chữ của bản cũ được giữ nguyên
            return 'chỉ làm lại '.implode(' + ', $do).', giữ nguyên chữ của bài'.($keep !== [] ? ' và '.implode(' và ', $keep) : '');
        }
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
