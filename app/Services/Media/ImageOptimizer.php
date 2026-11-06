<?php

namespace App\Services\Media;

use RuntimeException;

class ImageOptimizer
{
    /**
     * Kích thước ảnh tối đa (chiều rộng tối đa 1920px, chiều cao tối đa 1080px)
     */
    protected int $maxWidth;
    protected int $maxHeight;
    protected int $quality;

    public function __construct(int $maxWidth = 1920, int $maxHeight = 1080, int $quality = 82)
    {
        $this->maxWidth = $maxWidth;
        $this->maxHeight = $maxHeight;
        $this->quality = $quality;
    }

    /**
     * Tối ưu hóa và nén ảnh (resize nếu vượt quá kích thước và nén WebP)
     *
     * @param string $sourcePath Đường dẫn file nguồn
     * @param string $targetPath Đường dẫn lưu file sau khi tối ưu
     * @param string $outputFormat Định dạng xuất ('webp', 'jpeg', 'png')
     * @return array [ 'width' => ..., 'height' => ..., 'size' => ..., 'compression_ratio' => ... ]
     */
    public function optimize(string $sourcePath, string $targetPath, string $outputFormat = 'webp'): array
    {
        $originalSize = filesize($sourcePath);
        $imageResource = $this->createImageResource($sourcePath);

        $srcWidth = imagesx($imageResource);
        $srcHeight = imagesy($imageResource);

        // Tính toán kích thước mới nếu vượt quá giới hạn
        [$newWidth, $newHeight] = $this->calculateDimensions($srcWidth, $srcHeight, $this->maxWidth, $this->maxHeight);

        $resizedResource = $this->resizeImage($imageResource, $srcWidth, $srcHeight, $newWidth, $newHeight);

        // Đảm bảo thư mục đích tồn tại
        $targetDir = dirname($targetPath);
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        // Xuất ảnh theo định dạng yêu cầu
        $this->saveImage($resizedResource, $targetPath, $outputFormat, $this->quality);

        imagedestroy($imageResource);
        if ($resizedResource !== $imageResource) {
            imagedestroy($resizedResource);
        }

        clearstatcache(true, $targetPath);
        $newSize = filesize($targetPath);
        $compressionRatio = $originalSize > 0
            ? round((($originalSize - $newSize) / $originalSize) * 100, 2)
            : 0;

        return [
            'width' => $newWidth,
            'height' => $newHeight,
            'original_size' => $originalSize,
            'optimized_size' => $newSize,
            'compression_ratio' => $compressionRatio,
            'format' => $outputFormat,
        ];
    }

    /**
     * Sinh ảnh thumbnail kích thước nhỏ phục vụ hiển thị quản trị và responsive
     *
     * @param string $sourcePath
     * @param string $thumbPath
     * @param int $thumbWidth
     * @param int $thumbHeight
     * @return array
     */
    public function createThumbnail(string $sourcePath, string $thumbPath, int $thumbWidth = 360, int $thumbHeight = 240): array
    {
        $imageResource = $this->createImageResource($sourcePath);

        $srcWidth = imagesx($imageResource);
        $srcHeight = imagesy($imageResource);

        [$newWidth, $newHeight] = $this->calculateDimensions($srcWidth, $srcHeight, $thumbWidth, $thumbHeight);

        $thumbResource = $this->resizeImage($imageResource, $srcWidth, $srcHeight, $newWidth, $newHeight);

        $thumbDir = dirname($thumbPath);
        if (! is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        $this->saveImage($thumbResource, $thumbPath, 'webp', 80);

        imagedestroy($imageResource);
        imagedestroy($thumbResource);

        clearstatcache(true, $thumbPath);

        return [
            'width' => $newWidth,
            'height' => $newHeight,
            'size' => filesize($thumbPath),
            'path' => $thumbPath,
        ];
    }

    /**
     * Tạo resource ảnh từ file nguồn bằng GD
     */
    protected function createImageResource(string $sourcePath)
    {
        $info = @getimagesize($sourcePath);
        if (! $info) {
            throw new RuntimeException("Không thể đọc thông tin ảnh từ: {$sourcePath}");
        }

        $mime = $info['mime'];

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => @imagecreatefromwebp($sourcePath),
            default => throw new RuntimeException("Định dạng GD không hỗ trợ: {$mime}"),
        };

        if (! $image) {
            throw new RuntimeException("Không thể tạo GD resource từ ảnh: {$sourcePath}");
        }

        return $image;
    }

    /**
     * Tính toán kích thước mới bảo toàn tỉ lệ khung hình (Aspect Ratio)
     */
    protected function calculateDimensions(int $width, int $height, int $maxW, int $maxH): array
    {
        if ($width <= $maxW && $height <= $maxH) {
            return [$width, $height];
        }

        $ratio = min($maxW / $width, $maxH / $height);
        $newW = (int) max(1, round($width * $ratio));
        $newH = (int) max(1, round($height * $ratio));

        return [$newW, $newH];
    }

    /**
     * Resize ảnh và giữ kênh Alpha (Transparency) cho PNG/WebP
     */
    protected function resizeImage($srcImage, int $srcW, int $srcH, int $targetW, int $targetH)
    {
        if ($srcW === $targetW && $srcH === $targetH) {
            return $srcImage;
        }

        $targetImage = imagecreatetruecolor($targetW, $targetH);

        // Giữ kênh trong suốt
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
        $transparent = imagecolorallocatealpha($targetImage, 255, 255, 255, 127);
        imagefilledrectangle($targetImage, 0, 0, $targetW, $targetH, $transparent);

        imagecopyresampled(
            $targetImage,
            $srcImage,
            0, 0, 0, 0,
            $targetW,
            $targetH,
            $srcW,
            $srcH
        );

        return $targetImage;
    }

    /**
     * Lưu GD image resource thành file theo format
     */
    protected function saveImage($imageResource, string $targetPath, string $format, int $quality): void
    {
        $success = match (strtolower($format)) {
            'webp' => imagewebp($imageResource, $targetPath, $quality),
            'jpeg', 'jpg' => imagejpeg($imageResource, $targetPath, $quality),
            'png' => imagepng($imageResource, $targetPath, (int) round((100 - $quality) / 10)),
            default => imagewebp($imageResource, $targetPath, $quality),
        };

        if (! $success) {
            throw new RuntimeException("Không thể lưu ảnh sang file: {$targetPath}");
        }
    }
}
