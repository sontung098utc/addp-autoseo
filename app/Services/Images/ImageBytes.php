<?php

namespace App\Services\Images;

/** Nhận dạng MIME ảnh từ byte đầu (không tin tiêu đề phản hồi). */
final class ImageBytes
{
    public static function mime(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            strlen($bytes) > 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /** Giải mã base64 và kiểm là ảnh; lỗi thì ném ImageGenerationError. @return array{0:string,1:string} [bytes, mime] */
    public static function fromBase64(mixed $b64): array
    {
        $bytes = is_string($b64) ? base64_decode($b64, true) : false;
        if ($bytes === false || $bytes === '') {
            throw new ImageGenerationError('Phản hồi không có dữ liệu ảnh.');
        }
        $mime = self::mime($bytes);
        if ($mime === null) {
            throw new ImageGenerationError('Dữ liệu trả về không phải ảnh PNG/JPEG/WebP.');
        }

        return [$bytes, $mime];
    }
}
