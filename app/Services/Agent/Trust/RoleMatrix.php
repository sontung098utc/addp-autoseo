<?php

namespace App\Services\Agent\Trust;

use App\Filament\Clusters\AgentTeam;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Publication;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * AG-S12: bảng "Ai được làm gì" lấy từ Policies THẬT (Gate) cho 3 vai trò, không viết tay.
 * Ba dòng không có Policy (hub Đội AI, khóa AI, Mức 2) ghi rõ nguồn kiểm.
 */
final class RoleMatrix
{
    public const ROLES = ['admin' => 'Quản trị viên', 'approver' => 'Trưởng nhóm duyệt', 'writer' => 'Biên tập viên'];

    /** @return list<array{label: string, source: string, cells: array<string, bool>}> */
    public static function rows(): array
    {
        $checks = [
            ['Xem và viết bài', 'ArticlePolicy', fn ($g) => $g->allows('viewAny', Article::class) && $g->allows('create', Article::class)],
            ['Duyệt / từ chối bài', 'ArticlePolicy::review', fn ($g) => $g->allows('review', new Article)],
            ['Duyệt phiên bản bài', 'ArticleVersionPolicy::approve', fn ($g) => $g->allows('approve', new ArticleVersion)],
            ['Xem lịch xuất bản', 'PublicationPolicy', fn ($g) => $g->allows('viewAny', Publication::class)],
            ['Xóa bài', 'ArticlePolicy::delete', fn ($g) => $g->allows('delete', new Article)],
            ['Quản lý website kết nối', 'WebsitePolicy', fn ($g) => $g->allows('viewAny', Website::class) && $g->allows('update', new Website)],
        ];
        $rows = [];
        foreach ($checks as [$label, $source, $fn]) {
            $rows[] = ['label' => $label, 'source' => $source, 'cells' => self::eachRole(fn (User $u) => (bool) $fn(Gate::forUser($u)))];
        }
        $rows[] = ['label' => 'Xem hub Đội AI (cả trang này)', 'source' => 'AgentTeam::canAccess', 'cells' => self::eachRole(fn (User $u) => self::asUser($u, fn () => AgentTeam::canAccess()))];
        $rows[] = ['label' => 'Nhập / đổi khóa AI', 'source' => 'AgentConnection (chỉ quản trị viên)', 'cells' => self::eachRole(fn (User $u) => $u->isAdmin())];
        $rows[] = ['label' => 'Nâng lên Mức 2 (tự đăng)', 'source' => 'Khóa cứng (TrustGate)', 'cells' => self::eachRole(fn () => false)];

        return $rows;
    }

    /** @return array<string, bool> */
    private static function eachRole(\Closure $fn): array
    {
        $out = [];
        foreach (array_keys(self::ROLES) as $role) {
            $out[$role] = (bool) $fn(new User(['role' => $role, 'is_active' => true]));
        }

        return $out;
    }

    private static function asUser(User $u, \Closure $fn): bool
    {
        $prev = Auth::user();
        Auth::setUser($u);
        try {
            return (bool) $fn();
        } finally {
            $prev ? Auth::setUser($prev) : Auth::forgetUser();
        }
    }
}
