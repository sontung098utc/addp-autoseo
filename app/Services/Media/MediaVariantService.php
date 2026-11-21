<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\Storage;

/**
 * Biến thể ảnh theo nơi dùng, sinh lười (lần đầu được hỏi) và lưu cạnh file gốc theo địa chỉ nội dung.
 * Không thay thế file_path/thumbnail_path: lỗi sinh biến thể luôn trả về đường dẫn gốc nên không ảnh hưởng đăng bài.
 */
class MediaVariantService
{
    /** tên => [rộng, cao|null]; cao null = giữ tỉ lệ, cao có giá trị = cắt giữa theo khung. */
    public const VARIANTS = [
        'thumb' => [360, 240],
        'card' => [800, null],
        'content' => [1200, null],
        'og' => [1200, 630],
    ];

    /** Đường dẫn tương đối của biến thể (đã sinh nếu chưa có); lỗi thì trả đường dẫn gốc. */
    public function path(MediaAsset $asset, string $variant): string
    {
        $orig = (string) $asset->file_path;
        if (! isset(self::VARIANTS[$variant])) {
            return $orig;
        }
        try {
            $disk = Storage::disk($asset->disk ?: 'public');
            if (! $disk->exists($orig) || ! preg_match('/^[a-f0-9]{64}$/', (string) $asset->sha256)) {
                return $orig;
            }
            $target = MediaPathBuilder::dir($asset->sha256).'/'.$variant.'.webp';
            if ($disk->exists($target)) {
                return $target;
            }
            [$w, $h] = self::VARIANTS[$variant];
            $dir = dirname($disk->path($target));
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return $orig;
            }
            if ($h !== null) {
                $this->coverCrop($disk->path($orig), $disk->path($target), $w, $h);
            } else {
                // Không phóng to ảnh nhỏ hơn khung
                $maxW = ($asset->width && $asset->width < $w) ? (int) $asset->width : $w;
                (new ImageOptimizer($maxW, 10000))->optimize($disk->path($orig), $disk->path($target), 'webp');
            }

            return $disk->exists($target) ? $target : $orig;
        } catch (\Throwable) {
            return $orig;
        }
    }

    public function url(MediaAsset $asset, string $variant): string
    {
        return Storage::disk($asset->disk ?: 'public')->url($this->path($asset, $variant));
    }

    /** Cắt giữa để đúng khung $w x $h (dùng cho og/thumb); ảnh nguồn nhỏ hơn thì được phóng vừa đủ phủ khung. */
    protected function coverCrop(string $src, string $dst, int $w, int $h): void
    {
        $im = @imagecreatefromwebp($src);
        if ($im === false) {
            throw new \RuntimeException('Không đọc được ảnh nguồn');
        }
        $sw = imagesx($im);
        $sh = imagesy($im);
        $scale = max($w / $sw, $h / $sh);
        $cw = (int) min($sw, round($w / $scale));
        $ch = (int) min($sh, round($h / $scale));
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $im, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), $w, $h, $cw, $ch);
        $ok = imagewebp($out, $dst, 82);
        imagedestroy($im);
        imagedestroy($out);
        if (! $ok) {
            throw new \RuntimeException('Không ghi được biến thể');
        }
    }
}
