<?php

namespace App\Filament\Clusters;

use App\Models\User;
use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;

/**
 * AG-S01d: MỘT mục menu "Đội AI" gom mọi thứ liên quan: tổng quan luồng, chiến dịch (cha của bài viết), phiên chạy, vai trò, kỹ năng. Kế hoạch là mục menu riêng ngay dưới.
 * Người dùng đi theo thứ tự các tab: Kết nối AI → Chiến dịch → (hệ thống tự chạy) → Phiên chạy → Duyệt bài (ngoài cụm).
 */
class AgentTeam extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'Đội AI';

    protected static ?string $navigationGroup = 'Chiến dịch';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'doi-ai';

    protected static ?string $clusterBreadcrumb = 'Đội AI';

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function canAccess(): bool
    {
        $u = Auth::user();

        // Kế hoạch đã tách thành mục menu riêng ngay dưới "Đội AI" (biên tập viên vào ở đó); các tab còn lại của cụm chỉ cho người duyệt.
        return $u instanceof User && $u->is_active && $u->isApprover();
    }
}
