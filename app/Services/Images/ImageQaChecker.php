<?php

namespace App\Services\Images;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;

/**
 * KIỂM ẢNH sau khi tạo, bằng LUẬT (không AI nhìn ảnh: hệ thống chưa có model đọc ảnh). Bắt các lỗi đo được: tệp thiếu/quá nhỏ, độ phân giải thấp, ảnh gần như trống,
 * hai ảnh trùng nhau, tỉ lệ sai kế hoạch, thiếu alt. Không kết luận ảnh "đúng chủ đề" hay "chữ đúng dấu": với ảnh có chữ chỉ NHẮC người duyệt xem lại các cụm chữ đã yêu cầu.
 * Ảnh giả lập (model mock) không bị chê kích thước vì vốn nhỏ.
 */
final class ImageQaChecker
{
    public const MIN_BYTES = 4096;

    public const MIN_SIDE = 640;

    /** Độ lệch chuẩn độ sáng (0–255) dưới mức này coi là gần như trống. */
    public const BLANK_STDDEV = 8.0;

    public const ASPECT_TOLERANCE = 0.25;

    /** Mã lỗi nặng (đáng cảnh báo vàng); `text_check` chỉ là nhắc. */
    public const SERIOUS = ['file_missing', 'tiny_file', 'low_res', 'nearly_blank', 'duplicate', 'aspect', 'no_alt'];

    public const LABELS = [
        'file_missing' => 'không đọc được tệp ảnh', 'tiny_file' => 'tệp quá nhỏ', 'low_res' => 'độ phân giải thấp', 'nearly_blank' => 'ảnh gần như trống',
        'duplicate' => 'trùng với ảnh khác trong bài', 'aspect' => 'tỉ lệ khác kế hoạch', 'no_alt' => 'thiếu mô tả thay thế (alt)', 'text_check' => 'có chữ trên ảnh: người duyệt cần xem đúng chính tả và dấu',
    ];

    /**
     * @param  list<array<string,mixed>>  $slots  ô ảnh theo kế hoạch
     * @param  array<string,array<string,mixed>>  $items  kết quả tạo ảnh theo mã ô
     * @return array{rows: list<array{slot:string, asset_id:int, issues:list<string>, texts:list<string>}>, serious: int, summary: string}
     */
    public static function check(array $slots, array $items): array
    {
        $rows = [];
        $seen = [];
        foreach ($slots as $slot) {
            $it = $items[$slot['id']] ?? null;
            if (! is_array($it) || ($it['status'] ?? '') !== 'ok' || ! ($a = MediaAsset::query()->find($it['asset_id'] ?? 0))) {
                continue;
            }
            $issues = [];
            $mock = str_starts_with((string) (((array) $a->metadata)['model'] ?? ''), 'mock');
            $bytes = null;
            try {
                $bytes = Storage::disk($a->disk)->exists($a->file_path) ? Storage::disk($a->disk)->get($a->file_path) : null;
            } catch (\Throwable) {
            }
            if ($bytes === null || $bytes === '') {
                $issues[] = 'file_missing';
            } else {
                if (! $mock && strlen($bytes) < self::MIN_BYTES) {
                    $issues[] = 'tiny_file';
                }
                if (! $mock && $a->width && $a->height && min($a->width, $a->height) < self::MIN_SIDE) {
                    $issues[] = 'low_res';
                }
                if (self::isNearlyBlank($bytes)) {
                    $issues[] = 'nearly_blank';
                }
            }
            $key = (string) ($a->sha256 ?: $a->id);
            if (isset($seen[$key])) {
                $issues[] = 'duplicate';
            }
            $seen[$key] = true;
            if ($a->width && $a->height && ($slot['aspect'] ?? '') !== '' && preg_match('/^(\d+):(\d+)$/', (string) $slot['aspect'], $m) && (int) $m[2] > 0) {
                $want = (int) $m[1] / (int) $m[2];
                if (abs(($a->width / $a->height) - $want) / $want > self::ASPECT_TOLERANCE) {
                    $issues[] = 'aspect';
                }
            }
            if (mb_strlen(trim((string) $a->alt_text)) < 8) {
                $issues[] = 'no_alt';
            }
            $texts = array_values(array_map('strval', (array) ($slot['overlay_text'] ?? [])));
            if ($texts !== []) {
                $issues[] = 'text_check';
            }
            $rows[] = ['slot' => (string) $slot['id'], 'asset_id' => (int) $a->id, 'issues' => $issues, 'texts' => $texts];
        }
        $serious = 0;
        $parts = [];
        foreach ($rows as $r) {
            $sev = array_values(array_intersect($r['issues'], self::SERIOUS));
            $serious += count($sev);
            $notes = array_map(fn ($c) => self::LABELS[$c].($c === 'text_check' && $r['texts'] !== [] ? ' («'.implode('», «', $r['texts']).'»)' : ''), $r['issues']);
            if ($notes !== []) {
                $parts[] = ($r['slot'] === 'cover' ? 'Ảnh bìa' : 'Ảnh '.$r['slot']).': '.implode('; ', $notes);
            }
        }

        return ['rows' => $rows, 'serious' => $serious, 'summary' => $rows === [] ? '' : ($parts === [] ? 'Kiểm ảnh tự động (độ phân giải, trống, trùng, tỉ lệ, alt): không thấy lỗi đo được. Chưa kiểm được ảnh có đúng chủ đề không (chưa có AI nhìn ảnh).' : 'Kiểm ảnh tự động: '.implode(' | ', $parts).'.')];
    }

    /** Ảnh gần như một màu (độ lệch chuẩn độ sáng thấp trên bản thu nhỏ 24×24). */
    public static function isNearlyBlank(string $bytes): bool
    {
        if (! function_exists('imagecreatefromstring')) {
            return false;
        }
        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return false;
        }
        $small = imagecreatetruecolor(24, 24);
        imagecopyresampled($small, $im, 0, 0, 0, 0, 24, 24, imagesx($im), imagesy($im));
        $vals = [];
        for ($y = 0; $y < 24; $y++) {
            for ($x = 0; $x < 24; $x++) {
                $c = imagecolorat($small, $x, $y);
                $vals[] = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
            }
        }
        imagedestroy($im);
        imagedestroy($small);
        $mean = array_sum($vals) / count($vals);
        $var = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $vals)) / count($vals);

        return sqrt($var) < self::BLANK_STDDEV;
    }
}
