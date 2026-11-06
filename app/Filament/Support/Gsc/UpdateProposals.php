<?php

namespace App\Filament\Support\Gsc;

use App\Filament\Support\Demo\DemoStore;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Đề xuất cập nhật bài (lưu tạm trong DemoStore gsc.proposals; sau này là bảng update_proposals).
 * KHÔNG tự sửa bài. Quyền tạo: approver trở lên, kiểm ở server.
 */
final class UpdateProposals
{
    public const KEY = 'gsc.proposals';

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return DemoStore::get(self::KEY, []);
    }

    /**
     * @param  array<string,mixed>  $item  mục từ PeriodComparer::compare
     * @param  list<array{rule:string,label:string,key:string,reason:string}>  $opps
     * @return array<string,mixed>
     */
    public static function create(?User $user, array $item, string $dimension, array $opps, string $periodText): array
    {
        if (! $user || ! $user->is_active || ! $user->isApprover()) {
            throw new AuthorizationException('Chỉ trưởng nhóm duyệt bài trở lên mới tạo được đề xuất cập nhật.');
        }
        $rules = array_column($opps, 'rule');
        $c = $item['cur'];
        $proposal = [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'page' => $dimension === 'page' ? $item['key'] : ($item['page'] ?? null),
            'dimension' => $dimension,
            'key' => $item['key'],
            'reasons' => array_map(fn ($o) => $o['label'].': '.$o['reason'], $opps),
            'metrics' => ['clicks' => $c['clicks'], 'impressions' => $c['impressions'], 'ctr' => $c['ctr'], 'position' => $c['position'], 'period' => $periodText],
            'suggestions' => AiSuggestion::for($item['key'], $rules),
            'created_by' => $user->id,
            'created_by_name' => $user->name,
            'created_at' => now()->toIso8601String(),
            'status' => 'Chờ duyệt',
        ];
        DemoStore::put(self::KEY, array_merge([$proposal], self::all()));

        return $proposal;
    }
}
