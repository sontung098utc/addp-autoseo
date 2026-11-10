<?php

namespace App\Services\Images;

use App\Contracts\ImageGeneratorInterface;

/**
 * Bản giả lập TẤT ĐỊNH: cùng prompt cho cùng một PNG nhỏ hợp lệ, có chữ "MOCK" để nhận ra. Chỉ dùng ở local và test,
 * không bao giờ thay thế im lặng nhà cung cấp thật (ImageGeneratorResolver chỉ trả nó ở môi trường local khi chưa cấu hình gì).
 */
final class MockImageGenerator implements ImageGeneratorInterface
{
    public function generate(string $prompt, array $options = []): array
    {
        $h = md5($prompt);
        $w = 96;
        $ht = ($options['aspect'] ?? '16:9') === '16:9' ? 54 : 72;
        $im = imagecreatetruecolor($w, $ht);
        imagefill($im, 0, 0, imagecolorallocate($im, hexdec(substr($h, 0, 2)) | 0x40, hexdec(substr($h, 2, 2)) | 0x40, hexdec(substr($h, 4, 2)) | 0x40));
        imagestring($im, 3, 6, 6, 'MOCK', imagecolorallocate($im, 255, 255, 255));
        imagestring($im, 1, 6, 22, substr($h, 0, 12), imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return ['bytes' => $bytes, 'mime' => 'image/png', 'model' => 'mock-image', 'cost_vnd' => 0.0, 'prompt' => $prompt, 'revised_prompt' => null];
    }

    public function estimatedCostVnd(): float
    {
        return 0.0;
    }
}
