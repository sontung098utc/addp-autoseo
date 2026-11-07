<?php

namespace App\Filament\Support\Stop;

use App\Models\Website;
use App\Services\Publishing\EmergencyStopService;

/**
 * Dữ liệu cho banner "Đang dừng xuất bản" hiện ở mọi trang quản trị (AG-U11a).
 * CHỈ ĐỌC trạng thái từ EmergencyStopService; không sửa dịch vụ, không đổi hành vi dừng/khôi phục.
 */
final class StopBanner
{
    /**
     * @return null|array{scope: string, reason: string, by: ?string, since: ?string, sites: list<string>}
     */
    public static function data(): ?array
    {
        $stop = app(EmergencyStopService::class);

        if ($stop->isStopped()) {
            $info = $stop->getStopInfo() ?? [];

            return [
                'scope' => 'global',
                'reason' => (string) ($info['reason'] ?? 'không ghi lý do'),
                'by' => $info['stopped_by_name'] ?? null,
                'since' => $info['stopped_at'] ?? null,
                'sites' => [],
            ];
        }

        $sites = [];
        $first = null;
        foreach (Website::query()->orderBy('name')->get(['id', 'name']) as $site) {
            if ($stop->isStopped($site->id)) {
                $sites[] = $site->name;
                $first ??= $stop->getStopInfo($site->id) ?? [];
            }
        }

        if ($sites === []) {
            return null;
        }

        return [
            'scope' => 'sites',
            'reason' => (string) ($first['reason'] ?? 'không ghi lý do'),
            'by' => $first['stopped_by_name'] ?? null,
            'since' => $first['stopped_at'] ?? null,
            'sites' => $sites,
        ];
    }
}
