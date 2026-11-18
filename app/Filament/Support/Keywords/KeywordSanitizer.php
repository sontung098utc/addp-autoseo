<?php

namespace App\Filament\Support\Keywords;

/**
 * Cửa duy nhất chuẩn hóa từ khóa ở MỌI lối vào (CSV, nhập tay, AI đề xuất, kho). Không âm thầm đổi nghĩa:
 * chỉ gộp khoảng trắng và bỏ ký tự điều khiển/ký tự vô hình; còn thẻ HTML, chuỗi script, mẫu mã hóa kiểu {{ }} hay
 * lệnh trá hình thì TỪ CHỐI kèm lý do để người dùng thấy ở bản xem trước.
 */
final class KeywordSanitizer
{
    public const MAX_LENGTH = 255;

    private const DANGEROUS = [
        '/<\s*\/?\s*[a-z!?]/i' => 'có thẻ HTML',
        '/<\s*\/?\s*$|^\s*>|[<>]{2,}/' => 'có ký tự thẻ < >',
        '/(?:java|vb)script\s*:/i' => 'có chuỗi "javascript:"',
        '/\bdata\s*:\s*text\/html/i' => 'có chuỗi "data:text/html"',
        '/\bon[a-z]{3,20}\s*=/i' => 'có thuộc tính sự kiện (onerror=…)',
        '/\{\{|\}\}|\{%|%\}|\$\{|<\?/' => 'có cú pháp mã (mẫu/PHP)',
        '/&#?[a-z0-9]{2,8};/i' => 'có thực thể HTML (&…;)',
    ];

    /** @return array{keyword: ?string, reason: ?string} keyword null khi bị từ chối. */
    public static function check(string $raw): array
    {
        // Bỏ ký tự điều khiển, ký tự vô hình (zero-width, BOM, bidi) rồi gộp khoảng trắng.
        $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $raw) ?? $raw;
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if ($s === '') {
            return ['keyword' => null, 'reason' => 'Thiếu từ khóa.'];
        }
        if (mb_strlen($s) > self::MAX_LENGTH) {
            return ['keyword' => null, 'reason' => 'Từ khóa dài quá '.self::MAX_LENGTH.' ký tự.'];
        }
        foreach (self::DANGEROUS as $re => $why) {
            if (preg_match($re, $s)) {
                return ['keyword' => null, 'reason' => 'Từ khóa bị từ chối vì '.$why.'. Từ khóa chỉ nên là chữ thường dùng để tìm kiếm.'];
            }
        }

        return ['keyword' => $s, 'reason' => null];
    }
}
