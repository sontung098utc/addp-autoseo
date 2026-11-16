<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Z2: "Khởi tạo website" đã gộp vào khối "Khởi tạo nhanh" của luồng Tạo trí tuệ doanh nghiệp (BrainBuildPage::startOnboarding).
 * Trang này chỉ còn giữ URL cũ /admin/khoi-tao-website và chuyển hướng; không hiện trên menu. Quyền (approver trở lên) và logic WebsiteOnboarding không đổi.
 */
class WebsiteOnboardingPage extends Page
{
    protected static ?string $title = 'Khởi tạo website';

    protected static ?string $slug = 'khoi-tao-website';

    protected static string $view = 'filament.pages.brand-profile-redirect';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isApprover();
    }

    public function mount(): mixed
    {
        return redirect(BrainBuildPage::getUrl().'#khoi-tao-nhanh');
    }
}
