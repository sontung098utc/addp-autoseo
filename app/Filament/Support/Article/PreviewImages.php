<?php

namespace App\Filament\Support\Article;

use Illuminate\Support\Facades\Storage;

/**
 * Khung xem trước nội dung bài chạy sandbox rỗng (chặn script), trong khung đó trình duyệt không tải được ảnh từ chính website
 * (ảnh trong bài hiện chữ alt thay vì hình). Thay vì nới sandbox, nhúng ảnh của thư viện vào HTML xem trước dưới dạng data URI.
 * Chỉ ảnh nằm trong thư mục media của ổ public (không có ".."), tối đa MAX_IMAGES ảnh, mỗi ảnh tối đa MAX_BYTES; ảnh khác giữ nguyên.
 * Chỉ dùng để XEM TRƯỚC: HTML lưu trong CSDL và HTML gửi sang CMS không đổi.
 */
final class PreviewImages
{
    public const MAX_IMAGES = 8;

    public const MAX_BYTES = 3_000_000;

    private const MIME = ['webp' => 'image/webp', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'];

    public static function inline(string $html): string
    {
        $base = rtrim((string) config('filesystems.disks.public.url', ''), '/').'/';
        if ($base === '/' || ! str_contains($html, '<img')) {
            return $html;
        }
        $left = self::MAX_IMAGES;

        return (string) preg_replace_callback('#(<img\b[^>]*?\bsrc\s*=\s*)(["\'])(.*?)\2#i', function (array $m) use ($base, &$left): string {
            $src = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);
            if ($left <= 0 || ! str_starts_with($src, $base)) {
                return $m[0];
            }
            $path = rawurldecode(strtok(substr($src, strlen($base)), '?#') ?: '');
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (! str_starts_with($path, 'media/') || str_contains($path, '..') || ! isset(self::MIME[$ext])) {
                return $m[0];
            }
            try {
                $disk = Storage::disk('public');
                if (! $disk->exists($path) || $disk->size($path) > self::MAX_BYTES) {
                    return $m[0];
                }
                $data = $disk->get($path);
            } catch (\Throwable) {
                return $m[0];
            }
            $left--;

            return $m[1].$m[2].'data:'.self::MIME[$ext].';base64,'.base64_encode((string) $data).$m[2];
        }, $html);
    }
}
