<?php

namespace App\Services\Media;

use InvalidArgumentException;

class MediaSecurityValidator
{
    /**
     * Danh sách MIME types ảnh raster an toàn tuyệt đối được phép lưu trữ
     */
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Giới hạn dung lượng tối đa cho 1 file ảnh (10MB)
     */
    public const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    /**
     * Kiểm tra tính an toàn của file ảnh dựa trên magic bytes và cấu trúc thực tế
     *
     * @param string $filePath Đường dẫn file cục bộ
     * @return array [ 'mime' => ..., 'size' => ..., 'width' => ..., 'height' => ..., 'sha256' => ... ]
     * @throws InvalidArgumentException
     */
    public function validateFile(string $filePath): array
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            throw new InvalidArgumentException("File không tồn tại hoặc không thể đọc: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            throw new InvalidArgumentException("File ảnh rỗng (0 bytes).");
        }

        if ($fileSize > self::MAX_FILE_SIZE_BYTES) {
            $maxMb = self::MAX_FILE_SIZE_BYTES / (1024 * 1024);
            throw new InvalidArgumentException("Dung lượng file vượt quá giới hạn cho phép ({$maxMb}MB).");
        }

        // 1. Kiểm tra MIME thực tế từ Magic Bytes bằng finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        if (! in_array($detectedMime, self::ALLOWED_MIME_TYPES, true)) {
            throw new InvalidArgumentException(
                "Định dạng file không an toàn hoặc không được hỗ trợ: '{$detectedMime}'. " .
                "Chỉ chấp nhận các định dạng ảnh: JPEG, PNG, WEBP."
            );
        }

        // 2. Kiểm tra tính toàn vẹn của cấu trúc ảnh bằng getimagesize
        $imageInfo = @getimagesize($filePath);
        if ($imageInfo === false) {
            throw new InvalidArgumentException("File bị lỗi cấu trúc hoặc không phải ảnh hợp lệ.");
        }

        [$width, $height] = $imageInfo;
        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException("Kích thước ảnh không hợp lệ (width={$width}, height={$height}).");
        }

        // 3. Tính toán SHA256 hash của nội dung
        $sha256 = hash_file('sha256', $filePath);

        return [
            'mime' => $detectedMime,
            'size' => $fileSize,
            'width' => (int) $width,
            'height' => (int) $height,
            'sha256' => $sha256,
        ];
    }
}
