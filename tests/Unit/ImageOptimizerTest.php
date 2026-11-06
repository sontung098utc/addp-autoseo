<?php

namespace Tests\Unit;

use App\Services\Media\ImageOptimizer;
use PHPUnit\Framework\TestCase;

class ImageOptimizerTest extends TestCase
{
    protected ImageOptimizer $optimizer;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->optimizer = new ImageOptimizer(1920, 1080, 80);
        $this->tempDir = sys_get_temp_dir() . '/optimizer_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_it_resizes_large_image_and_converts_to_webp(): void
    {
        // Tạo ảnh nguồn 2400 x 1200
        $sourcePath = $this->tempDir . '/source_large.jpg';
        $targetPath = $this->tempDir . '/optimized.webp';

        $img = imagecreatetruecolor(2400, 1200);
        $color = imagecolorallocate($img, 100, 150, 200);
        imagefilledrectangle($img, 0, 0, 2400, 1200, $color);
        imagejpeg($img, $sourcePath, 95);
        imagedestroy($img);

        $result = $this->optimizer->optimize($sourcePath, $targetPath, 'webp');

        $this->assertFileExists($targetPath);
        $this->assertEquals(1920, $result['width']);
        $this->assertEquals(960, $result['height']); // 2400x1200 tỷ lệ 2:1 -> 1920x960
        $this->assertEquals('webp', $result['format']);
        $this->assertGreaterThan(0, $result['optimized_size']);

        $info = getimagesize($targetPath);
        $this->assertEquals('image/webp', $info['mime']);
        $this->assertEquals(1920, $info[0]);
        $this->assertEquals(960, $info[1]);
    }

    public function test_it_generates_thumbnail(): void
    {
        $sourcePath = $this->tempDir . '/source.jpg';
        $thumbPath = $this->tempDir . '/thumb.webp';

        $img = imagecreatetruecolor(800, 600);
        imagejpeg($img, $sourcePath, 85);
        imagedestroy($img);

        $result = $this->optimizer->createThumbnail($sourcePath, $thumbPath, 360, 240);

        $this->assertFileExists($thumbPath);
        $this->assertLessThanOrEqual(360, $result['width']);
        $this->assertLessThanOrEqual(240, $result['height']);

        $info = getimagesize($thumbPath);
        $this->assertEquals('image/webp', $info['mime']);
    }
}
