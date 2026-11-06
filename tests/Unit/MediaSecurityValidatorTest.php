<?php

namespace Tests\Unit;

use App\Services\Media\MediaSecurityValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MediaSecurityValidatorTest extends TestCase
{
    protected MediaSecurityValidator $validator;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new MediaSecurityValidator();
        $this->tempDir = sys_get_temp_dir() . '/media_sec_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Cleanup temp dir
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_it_throws_when_file_does_not_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File không tồn tại hoặc không thể đọc');

        $this->validator->validateFile($this->tempDir . '/non_existent.jpg');
    }

    public function test_it_throws_when_file_is_empty(): void
    {
        $emptyFile = $this->tempDir . '/empty.jpg';
        file_put_contents($emptyFile, '');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('File ảnh rỗng');

        $this->validator->validateFile($emptyFile);
    }

    public function test_it_blocks_spoofed_php_script_disguised_as_jpg(): void
    {
        $spoofedFile = $this->tempDir . '/malicious.jpg';
        file_put_contents($spoofedFile, '<?php echo "malicious code"; ?>');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Định dạng file không an toàn');

        $this->validator->validateFile($spoofedFile);
    }

    public function test_it_validates_real_jpeg_image(): void
    {
        $realJpg = $this->tempDir . '/valid.jpg';
        $img = imagecreatetruecolor(100, 80);
        $bg = imagecolorallocate($img, 255, 0, 0);
        imagefilledrectangle($img, 0, 0, 100, 80, $bg);
        imagejpeg($img, $realJpg, 90);
        imagedestroy($img);

        $result = $this->validator->validateFile($realJpg);

        $this->assertEquals('image/jpeg', $result['mime']);
        $this->assertEquals(100, $result['width']);
        $this->assertEquals(80, $result['height']);
        $this->assertGreaterThan(0, $result['size']);
        $this->assertNotEmpty($result['sha256']);
        $this->assertEquals(64, strlen($result['sha256']));
    }

    public function test_it_validates_real_png_image(): void
    {
        $realPng = $this->tempDir . '/valid.png';
        $img = imagecreatetruecolor(50, 50);
        imagepng($img, $realPng);
        imagedestroy($img);

        $result = $this->validator->validateFile($realPng);

        $this->assertEquals('image/png', $result['mime']);
        $this->assertEquals(50, $result['width']);
        $this->assertEquals(50, $result['height']);
    }
}
