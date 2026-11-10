<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\Website;
use App\Services\Brain\BrainService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * AG-B2: Hồ sơ doanh nghiệp đã DỜI vào tab của Trí tuệ Thương hiệu (BrainPage). Trang này chỉ còn giữ URL cũ /admin/ho-so-doanh-nghiep và
 * chuyển hướng: một website, hoặc ?website=ID → Trí tuệ Thương hiệu của website (tạo nếu chưa có); nhiều website → Thư viện.
 * Không hiện trên menu. Dữ liệu (brand_profiles) và luật BAN01 không đổi.
 */
class BrandProfilePage extends Page
{
    protected static ?string $slug = 'ho-so-doanh-nghiep';

    protected static ?string $title = 'Hồ sơ doanh nghiệp';

    protected static string $view = 'filament.pages.brand-profile-redirect';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $u = Auth::user();

        return $u instanceof User && $u->is_active && $u->isWriter();
    }

    public function mount(): mixed
    {
        $sites = Website::query()->orderBy('name')->get();
        $wanted = request()->integer('website');
        $site = $wanted ? $sites->firstWhere('id', $wanted) : ($sites->count() === 1 ? $sites->first() : null);
        if ($site) {
            $brain = (new BrainService())->brandBrainFor($site, Auth::user());
            if ($brain) {
                return redirect(BrainPage::getUrl(['brain' => $brain->id]).'#tab-brand');
            }
            Notification::make()->title('Website này chưa có Trí tuệ Thương hiệu')->body('Nhờ quản trị viên hoặc người duyệt tạo Trí tuệ Thương hiệu ở Kho trí tuệ.')->warning()->send();
        }

        return redirect(BrainLibraryPage::getUrl());
    }
}
