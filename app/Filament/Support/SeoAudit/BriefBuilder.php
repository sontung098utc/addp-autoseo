<?php

namespace App\Filament\Support\SeoAudit;

use App\Filament\Support\Schedule\VnTime;
use Carbon\CarbonInterface;

/** Sinh brief THẬT (văn bản tiếng Việt) từ nhóm lỗi đã chọn. Brief chỉ mô tả việc cần làm, không tự sửa website. */
final class BriefBuilder
{
    /**
     * @param  array{type: string, label: string, severity: string, urls: int, items: list<array{url: string, measured: string, evidence: string}>}  $group
     * @return array{title: string, body: string}
     */
    public static function build(string $siteName, CarbonInterface $scannedAt, array $group, bool $sample = true): array
    {
        $title = "Sửa {$group['urls']} trang: {$group['label']} trên {$siteName}";
        $L = [];
        $L[] = $title;
        $L[] = str_repeat('=', min(60, mb_strlen($title)));
        $L[] = "Mức: {$group['severity']} · Loại lỗi: {$group['label']} · Số URL ảnh hưởng: {$group['urls']}";
        $L[] = 'Mô tả lỗi: '.IssueCatalog::sub($group['type']).'.';
        $L[] = 'Dữ liệu từ lần quét '.VnTime::format($scannedAt).' (giờ VN)'.($sample ? ' — DỮ LIỆU MINH HỌA, chưa phải kết quả quét thật.' : '.');
        $L[] = '';
        $L[] = 'DANH SÁCH URL VÀ BẰNG CHỨNG';
        foreach ($group['items'] as $i => $it) {
            $L[] = ($i + 1).'. '.$it['url'];
            $L[] = '   Đo được: '.$it['measured'];
            $L[] = '   Bằng chứng: '.str_replace("\n", "\n               ", $it['evidence']);
        }
        $L[] = '';
        $L[] = 'GỢI Ý CÁCH SỬA (AI đề xuất, minh họa — cần người kiểm lại)';
        foreach (IssueCatalog::fixSteps($group['type'], $group['items']) as $i => $s) {
            $L[] = ($i + 1).'. '.$s;
        }
        $L[] = '';
        $L[] = 'CÁCH KIỂM CHỨNG SAU KHI SỬA';
        $L[] = '- Mở lại từng URL ở trên và xem nguồn trang để xác nhận lỗi không còn.';
        $L[] = '- Chạy "Quét lại" ở màn Audit website (cách lần quét trước tối thiểu '.ScanGuard::COOLDOWN_HOURS.' giờ) và kiểm tra số URL của nhóm này giảm.';
        $L[] = '';
        $L[] = 'Lưu ý: brief chỉ tạo việc, không tự sửa nội dung, redirect hay cấu hình website.';

        return ['title' => $title, 'body' => implode("\n", $L)];
    }
}
