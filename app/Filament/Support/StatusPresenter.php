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
    ];

    private const WEBSITE = [
        'active' => ['Hoạt động', 'success', 'heroicon-o-check-circle'],
        'pending' => ['Chờ kiểm tra', 'gray', 'heroicon-o-clock'],
        'revoked' => ['Đã thu hồi', 'gray', 'heroicon-o-no-symbol'],
        'error' => ['Lỗi kết nối', 'warning', 'heroicon-o-exclamation-triangle'],
    ];

    /** @return array{0: string, 1: string, 2: string}|null */
    private static function find(?string $value, string $domain): ?array
    {
        $map = match ($domain) {
            'article', 'publication' => self::ARTICLE,
            'website' => self::WEBSITE,
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

    public static function icon(?string $value, string $domain = 'article'): string
    {
        return self::find($value, $domain)[2] ?? self::FALLBACK_ICON;
    }
}
