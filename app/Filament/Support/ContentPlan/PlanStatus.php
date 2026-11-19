<?php

namespace App\Filament\Support\ContentPlan;

use App\Filament\Support\StatusPresenter;
use Carbon\CarbonImmutable;

/**
 * Trạng thái hiển thị của một bài trên Lịch tuần. Trạng thái của bài (workflow_status) có thể vẫn là "Đã xếp lịch"
 * trong khi lượt đăng đã xong/lỗi/quá giờ, nên ưu tiên trạng thái của lượt đăng mới nhất rồi mới xét quá hạn.
 */
final class PlanStatus
{
    private const TONES = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'danger', 'info' => 'info', 'gray' => 'muted'];

    /** @return array{key:string, text:string, color:string, tone:string} color theo Filament, tone theo x-studio.badge */
    public static function resolve(string $articleStatus, ?string $publicationStatus, ?CarbonImmutable $at, CarbonImmutable $now): array
    {
        $status = $articleStatus;
        if ($publicationStatus !== null && $publicationStatus !== '' && in_array($articleStatus, ['approved', 'scheduled', 'publishing', 'published', 'failed', 'needs_reconcile'], true)) {
            $status = $publicationStatus;
        }
        if (in_array($status, ['scheduled', 'planned'], true) && $at !== null && $at->lt($now)) {
            return ['key' => 'passed', 'text' => 'Đã qua', 'color' => 'gray', 'tone' => 'muted'];
        }
        $color = StatusPresenter::color($status);

        return ['key' => $status, 'text' => StatusPresenter::label($status), 'color' => $color, 'tone' => self::TONES[$color] ?? 'muted'];
    }

    /** Khoảng ngày dự kiến của một chiến dịch: "18/11/2026" hoặc "18/11 – 20/11/2026". */
    public static function rangeLabel(CarbonImmutable $from, ?CarbonImmutable $to): string
    {
        if ($to !== null && $to->format('d/m') !== $from->format('d/m')) {
            return $from->format('d/m').' – '.$to->format('d/m/Y');
        }

        return $from->format('d/m/Y');
    }
}
