<?php

namespace App\Services\Agent\Trust;

/**
 * AG-S12: điều kiện lên Mức 2 (T2, tự đăng). KHÓA CỨNG: không có đường nào trả `canUpgrade = true`
 * khi chưa có văn bản chính sách của người dùng và connector hỗ trợ gỡ bài, dù số liệu đẹp đến đâu.
 * Hệ thống chỉ báo "đủ điều kiện"; không có nút bật. Muốn mở khóa phải sửa mã có review, không phải bấm trên giao diện.
 */
final class TrustGate
{
    public const MIN_SAMPLE = 20;

    public const COMPLETION_MIN = 0.80;

    public const REPAIR_MIN = 0.60;

    /** Chưa có nơi nào lưu văn bản chính sách tự đăng của người dùng. Cố ý là hằng số, không đọc từ cài đặt. */
    public static function policyDocumentOnFile(): bool
    {
        return false;
    }

    /** Chưa connector nào (WordPress/Magento) triển khai gỡ bài. */
    public static function connectorSupportsUnpublish(): bool
    {
        return false;
    }

    /**
     * state: met | unmet | nodata | locked
     *
     * @param  array<string, array{value: float|null, n: int}>  $m  kết quả TrustMetrics::compute
     * @return array{conditions: list<array<string, mixed>>, canUpgrade: bool, unmet: int}
     */
    public static function evaluate(array $m): array
    {
        $conditions = [
            self::numeric('Tỉ lệ hoàn tất không cần người', $m['completion_rate'], '≥ 80%', fn ($v) => $v >= self::COMPLETION_MIN, 'pct'),
            self::numeric('Tỉ lệ tự sửa thành công', $m['repair_success_rate'], '≥ 60%', fn ($v) => $v >= self::REPAIR_MIN, 'pct'),
            self::numeric('Nội dung giả lập lọt vào bài nháp', $m['mock_in_drafts'], '= 0', fn ($v) => $v == 0.0, 'int'),
            ['label' => 'Đã qua bộ mẫu kiểm tra tiếng Việt với model thật', 'current' => 'Chưa chạy', 'threshold' => 'đạt ngưỡng', 'state' => 'nodata', 'note' => 'Chưa có kết quả bộ mẫu lưu lại với model thật.'],
            ['label' => 'Website cho phép gỡ bài', 'current' => 'Chưa có', 'threshold' => 'bắt buộc',
                'state' => self::connectorSupportsUnpublish() ? 'met' : 'locked', 'note' => 'Connector chưa hỗ trợ gỡ bài.'],
            ['label' => 'Văn bản chính sách tự đăng của bạn', 'current' => 'Chưa có', 'threshold' => 'bắt buộc',
                'state' => self::policyDocumentOnFile() ? 'met' : 'locked', 'note' => 'Khóa cứng: cần văn bản chính sách của bạn, không có nút bật.'],
            ['label' => 'Bài mới được Google lập chỉ mục sau 4 tuần', 'current' => 'Chưa có dữ liệu', 'threshold' => 'ngưỡng do bạn chốt', 'state' => 'nodata', 'note' => 'Cần Search Console thật.'],
        ];
        $unmet = count(array_filter($conditions, fn ($c) => $c['state'] !== 'met'));
        // Hai điều kiện khóa cứng luôn phải đạt: bất kể các dòng khác.
        $canUpgrade = $unmet === 0 && self::policyDocumentOnFile() && self::connectorSupportsUnpublish();

        return ['conditions' => $conditions, 'canUpgrade' => $canUpgrade, 'unmet' => $unmet];
    }

    /** @param array{value: float|null, n: int} $metric */
    private static function numeric(string $label, array $metric, string $threshold, \Closure $ok, string $fmt): array
    {
        $row = ['label' => $label, 'threshold' => $threshold, 'note' => ''];
        if ($metric['value'] === null) {
            return $row + ['current' => 'Chưa có dữ liệu', 'state' => 'nodata'];
        }
        $cur = $fmt === 'pct' ? round($metric['value'] * 100).'%' : (string) (int) $metric['value'];
        if ($metric['n'] < self::MIN_SAMPLE) {
            return $row + ['current' => "{$cur} (mới {$metric['n']} mẫu)", 'state' => 'nodata', 'note' => 'Cần ít nhất '.self::MIN_SAMPLE.' mẫu mới kết luận.'];
        }

        return $row + ['current' => $cur, 'state' => $ok($metric['value']) ? 'met' : 'unmet'];
    }
}
