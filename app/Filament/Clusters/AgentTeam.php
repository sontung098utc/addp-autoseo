<?php

namespace App\Filament\Clusters;

use App\Models\User;
use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;

/**
 * AG-S01d: MỘT mục menu "Đội AI" gom mọi thứ liên quan: tổng quan luồng, chiến dịch (cha của bài viết), kế hoạch nội dung của chiến dịch, phiên chạy, kết nối AI.
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

        // Biên tập viên vào được tab Kế hoạch; mỗi tab tự kiểm quyền riêng (Chiến dịch, Phiên chạy... vẫn chỉ cho người duyệt).
        return $u instanceof User && $u->is_active && $u->isWriter();
    }
}
