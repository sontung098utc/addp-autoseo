<?php

namespace App\Filament\Clusters;

use App\Models\User;
use Filament\Clusters\Cluster;
use Filament\Pages\SubNavigationPosition;
use Illuminate\Support\Facades\Auth;

/**
 * Z2: MỘT mục menu "Kho trí tuệ" gom mọi việc của kho: tab "Trí tuệ" (thư viện) và "Nạp kiến thức" (đọc website công ty, tìm nguồn trên web,
 * việc cần xem, khởi tạo nhanh). Trang một Trí tuệ (BrainPage) và hồ sơ doanh nghiệp (tab Thương hiệu) nằm trong Trí tuệ.
 * Quyền giữ nguyên theo từng trang: người viết thấy tab Trí tuệ; người duyệt thấy thêm tab Nạp kiến thức.
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
