<?php

namespace Tests\Unit\Support;

use Tests\TestCase;

/**
 * Chặn tái phạm #33: trong app/Filament không được đưa $e->getMessage() thẳng vào thông báo/giao diện;
 * phải đi qua App\Support\UserFacingError::from($e). Ngoại lệ có chú thích nằm trong ALLOW (tệp:số-dòng-không-cần, chỉ theo tệp+lý do).
 */
class NoRawExceptionInNotificationTest extends TestCase
{
    /** @var array<string,string> tệp => lý do dòng getMessage() được phép */
    private const ALLOW = [
        'Support/SeoAudit/ScanGuard.php' => 'chỉ so khớp tiền tố thông điệp, không hiển thị',
        'Support/Keywords/KeywordService.php' => 'SerpUnavailable là lỗi miền, cắt 190 ký tự để ghi nhật ký chạy',
        'Support/AiUsage/UsageThresholds.php' => 'chỉ nằm trong chú thích PHPDoc',
        'Support/Gsc/GoogleSearchConsoleSource.php' => 'GscFailed do mã dự án dựng, đã qua UserFacingError ở nơi tạo',
    ];

    public function test_khong_getmessage_tho_trong_filament(): void
    {
        $root = app_path('Filament');
        $bad = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            if (isset(self::ALLOW[$rel])) {
                continue;
            }
            foreach (file($f->getPathname()) as $i => $line) {
                if (str_contains($line, 'getMessage()') && ! str_starts_with(trim($line), '*') && ! str_starts_with(trim($line), '//')) {
                    $bad[] = $rel.':'.($i + 1);
                }
            }
        }
        $this->assertSame([], $bad, "getMessage() thô trong app/Filament; dùng App\\Support\\UserFacingError::from(\$e):\n".implode("\n", $bad));
    }
}
