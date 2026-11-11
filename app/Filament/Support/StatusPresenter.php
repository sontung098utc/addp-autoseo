<?php

namespace App\Filament\Support;

/**
 * Nguồn duy nhất cho nhãn / màu / icon của các trạng thái (DEV2_UI_REDESIGN_PLAN §7).
 * Giá trị lạ không ném lỗi: nhãn = chính giá trị, màu 'gray'.
 */
final class StatusPresenter
{
    private const FALLBACK_ICON = 'heroicon-o-question-mark-circle';

    /** @var array<string, array{0: string, 1: string, 2: string}> [nhãn, màu Filament, icon] */
    private const ARTICLE = [
        'planned' => ['Đã lên kế hoạch', 'gray', 'heroicon-o-calendar'],
        'drafting' => ['Đang viết (AI)', 'info', 'heroicon-o-pencil-square'],
        'needs_review' => ['Chờ duyệt', 'info', 'heroicon-o-eye'],
        'rejected' => ['Cần sửa', 'warning', 'heroicon-o-arrow-uturn-left'],
        'approved' => ['Đã duyệt', 'success', 'heroicon-o-check'],
        'scheduled' => ['Đã xếp lịch', 'info', 'heroicon-o-clock'],
        'publishing' => ['Đang đăng', 'warning', 'heroicon-o-arrow-path'],
        'published' => ['Đã đăng', 'success', 'heroicon-o-check-circle'],
        'failed' => ['Lỗi', 'danger', 'heroicon-o-x-circle'],
        'needs_reconcile' => ['Chờ đối soát', 'danger', 'heroicon-o-exclamation-triangle'],
        'held' => ['Tạm giữ', 'gray', 'heroicon-o-pause-circle'],
        'paused' => ['Tạm dừng', 'gray', 'heroicon-o-pause-circle'],
        'awaiting_confirm' => ['Chờ bạn xác nhận', 'warning', 'heroicon-o-hand-raised'],
    ];

    private const WEBSITE = [
        'active' => ['Hoạt động', 'success', 'heroicon-o-check-circle'],
        'pending' => ['Chờ kiểm tra', 'gray', 'heroicon-o-clock'],
        'revoked' => ['Đã thu hồi', 'gray', 'heroicon-o-no-symbol'],
        'error' => ['Lỗi kết nối', 'warning', 'heroicon-o-exclamation-triangle'],
    ];

    /**
     * AG-U01: mức sẵn sàng của một tính năng. Dùng để KHÔNG để người dùng hiểu nhầm tính năng chưa nối là đã hoạt động.
     * [nhãn, màu, icon, gợi ý]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const READINESS = [
        'active' => ['Đang hoạt động', 'success', 'heroicon-o-check-circle', 'Chạy trên dữ liệu thật và có bằng chứng.'],
        'demo' => ['Dữ liệu minh họa', 'gray', 'heroicon-o-beaker', 'Giao diện chạy trên số mẫu, không phải dữ liệu thật.'],
        'not_connected' => ['Chưa kết nối', 'warning', 'heroicon-o-link-slash', 'Có giao diện nhưng thiếu nguồn dữ liệu hoặc tích hợp.'],
        'in_development' => ['Đang phát triển', 'info', 'heroicon-o-wrench-screwdriver', 'Có trong kế hoạch, chưa hoàn thành.'],
        'idea' => ['Ý tưởng', 'gray', 'heroicon-o-light-bulb', 'Đề xuất, chưa được duyệt hay lên lịch.'],
    ];

    /**
     * AG-U01: nguồn của một nội dung/báo cáo. Nội dung AI luôn phải cho biết là AI thật hay giả lập.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    private const SOURCE = [
        'live' => ['AI thật', 'success', 'heroicon-o-sparkles', 'Do một model AI thật tạo ra.'],
        'mock' => ['AI giả lập', 'warning', 'heroicon-o-exclamation-triangle', 'Nội dung mẫu, KHÔNG phải kết quả AI thật.'],
        'imported' => ['Nhập thủ công', 'info', 'heroicon-o-arrow-up-tray', 'Do người dùng tải lên.'],
        'local' => ['Quét cục bộ', 'gray', 'heroicon-o-server', 'Do hệ thống tự tính từ dữ liệu có sẵn, không dùng AI.'],
        'none' => ['Không dùng AI', 'gray', 'heroicon-o-minus-circle', 'Kết quả xác định, không qua model AI.'],
    ];

    /** @return array{0: string, 1: string, 2: string}|null */
    private static function find(?string $value, string $domain): ?array
    {
        $map = match ($domain) {
            'article', 'publication' => self::ARTICLE,
            'website' => self::WEBSITE,
            'readiness' => self::READINESS,
            'source' => self::SOURCE,
            default => [],
        };

        return $map[(string) $value] ?? null;
    }

    public static function label(?string $value, string $domain = 'article'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return self::find($value, $domain)[0] ?? $value;
    }

    public static function color(?string $value, string $domain = 'article'): string
    {
        return self::find($value, $domain)[1] ?? 'gray';
    }

    /** Gợi ý ngắn (tooltip) cho mức sẵn sàng / nguồn; null nếu không có. */
    public static function hint(?string $value, string $domain): ?string
    {
        $map = match ($domain) {
            'readiness' => self::READINESS,
            'source' => self::SOURCE,
            default => [],
        };

        return $map[(string) $value][3] ?? null;
    }

    public static function icon(?string $value, string $domain = 'article'): string
    {
        return self::find($value, $domain)[2] ?? self::FALLBACK_ICON;
    }
}
