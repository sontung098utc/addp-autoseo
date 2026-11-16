<?php

namespace App\Filament\Clusters;

use App\Models\User;
use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;

/**
 * MỘT mục menu "Kho trí tuệ" và MỘT trang chính (thư viện Trí tuệ, không có hàng tab). "Tạo trí tuệ" hỏi LOẠI (App\Services\Knowledge\BrainKinds):
 * loại doanh nghiệp đi vào trang con BrainBuildPage (luồng xây kho từ website, ẩn khỏi menu). Trang một Trí tuệ là BrainPage.
 * Quyền giữ nguyên theo từng trang: người viết vào thư viện; người duyệt vào thêm luồng tạo Trí tuệ doanh nghiệp.
 */
class KhoTriTue extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationLabel = 'Kho trí tuệ';

    protected static ?string $navigationGroup = 'Khám phá';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'kho-tri-tue';

    protected static ?string $clusterBreadcrumb = 'Kho trí tuệ';

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isWriter();
    }
}
