<?php

namespace App\Filament\Support\Schedule;

/**
 * Biến lỗi CMS dạng kỹ thuật ("[ERR06] ... (HTTP 400: GET /rest/default/V1/company-blog/posts/by-key/...)") thành câu người dùng hiểu:
 * bước nào lỗi và nên làm gì. Luôn đi qua ErrorMasker trước nên không lộ secret. Không đoán nguyên nhân gốc khi chưa có nhật ký CMS.
 */
final class ErrorExplainer
{
    public static function explain(?string $error): string
    {
        $masked = ErrorMasker::mask($error);
        if ($masked === '') {
            return '';
        }

        $status = preg_match('/HTTP (\d{3})/', $masked, $m) ? (int) $m[1] : null;
        $step = null;
        if (preg_match('~\b(GET|POST|PUT|DELETE)\s+(/\S*)~', $masked, $m)) {
            $step = self::stepName($m[1], $m[2]);
        }
        if ($step === null && $status === null) {
            return $masked;
        }

        $out = $step ? "Lỗi ở bước: {$step}. " : '';
        $out .= match (true) {
            $status === 400 => 'Magento từ chối yêu cầu vì cho là sai dữ liệu (HTTP 400). Nên làm: kiểm tra "store scope" của website và module Company_BlogApi trên Magento có đúng phiên bản; sau đó bấm "Đăng lại" (hệ thống tra theo khóa xuất bản nên không đăng trùng). Chưa xác định được nguyên nhân chính xác: cần nhật ký Magento (var/log, log máy chủ web) tại thời điểm lỗi, trạng thái "Bị chặn tích hợp".',
            in_array($status, [401, 403], true) => 'CMS từ chối thông tin đăng nhập hoặc thiếu quyền. Nên làm: kiểm tra lại khóa kết nối của website.',
            $status === 404 => 'Không tìm thấy địa chỉ API trên CMS. Nên làm: kiểm tra địa chỉ website và module/plugin kết nối đã cài.',
            $status === 429 => 'CMS đang giới hạn tần suất. Nên làm: chờ vài phút rồi thử lại.',
            $status !== null && $status >= 500 => 'CMS gặp lỗi máy chủ. Nên làm: bấm "Đối soát" để kiểm tra bài đã lên chưa trước khi đăng lại.',
            default => '',
        };

        return trim($out)."\nChi tiết kỹ thuật: {$masked}";
    }

    private static function stepName(string $method, string $path): ?string
    {
        return match (true) {
            str_contains($path, '/posts/by-key/') => 'tra cứu bài đã có trên CMS theo khóa xuất bản (chống đăng trùng)',
            str_contains($path, '/company-blog/media') => 'tải ảnh lên CMS',
            (bool) preg_match('~/company-blog/posts/[^/]+/publish~', $path) => 'bật đăng bài trên CMS',
            $method === 'POST' && str_ends_with(rtrim($path, '/'), '/company-blog/posts') => 'tạo bài nháp trên CMS',
            str_contains($path, '/store/websites') => 'kiểm tra kết nối CMS',
            default => null,
        };
    }
}
