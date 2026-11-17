<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('SEO Studio')
            ->defaultAvatarProvider(\App\Filament\Support\LocalAvatarProvider::class)
            ->favicon(asset('favicon.svg'))
            ->colors([
                // Teal #0F6B5C (Editorial Ledger); 600 = màu gốc, nút/chữ sáng dùng 600, tối dùng 400/500.
                'primary' => [
                    50 => '231, 241, 238',
                    100 => '207, 229, 223',
                    200 => '169, 220, 208',
                    300 => '110, 203, 182',
                    400 => '63, 168, 147',
                    500 => '20, 129, 110',
                    600 => '15, 107, 92',
                    700 => '11, 85, 72',
                    800 => '10, 76, 65',
                    900 => '8, 58, 50',
                    950 => '5, 36, 31',
                ],
            ])
            ->navigationGroups(['Chiến dịch', 'Lịch đăng', 'Khám phá', 'Phân tích', 'Cài đặt'])
            ->globalSearchKeyBindings(['ctrl+k', 'command+k'])
            ->unsavedChangesAlerts()
            // Biểu tượng chuông cạnh ảnh đại diện (bảng notifications); thông báo mới còn nổi thành hộp ở góc trên bên phải nhờ NotificationToaster.
            // Chuông KHÔNG tự hỏi máy chủ riêng (polling null): NotificationToaster đã hỏi theo nhịp và làm mới chuông ngay khi có thông báo mới,
            // bớt một lượt gọi liên tục ở mọi trang (máy chủ dev chỉ xử lý một yêu cầu một lúc nên các lượt gọi nền xen vào lúc chuyển trang).
            ->databaseNotifications()
            ->databaseNotificationsPolling(null)
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(\Filament\Support\Enums\MaxWidth::Full)
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                // ?v= theo ngày sửa file: trình duyệt giữ file tĩnh 7 ngày (cache-control của máy chủ) nên không có số phiên bản thì người dùng thấy bản cũ sau khi cập nhật.
                fn (): string => '<link rel="stylesheet" href="' . e(static::versionedAsset('css/seo-studio.css')) . '">'
                    . '<script src="' . e(static::versionedAsset('js/studio-ui.js')) . '"></script>'
                    // Thanh tiến độ ở mép trên khi chuyển trang/chờ máy chủ.
                    . '<script src="' . e(static::versionedAsset('js/studio-progress.js')) . '"></script>',
            )
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                function (): string {
                    $user = \Illuminate\Support\Facades\Auth::user();
                    if (! $user || ! $user->is_active) {
                        return '';
                    }

                    return view('filament.hooks.stop-banner', [
                        'stop' => \App\Filament\Support\Stop\StopBanner::data(),
                        'canOpenSchedule' => $user->isApprover(),
                    ])->render();
                },
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => \Illuminate\Support\Facades\Auth::user()?->is_active
                    ? \Illuminate\Support\Facades\Blade::render('@livewire(\'notification-toaster\')')
                    : '',
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => \Illuminate\Support\Facades\Auth::user()?->isApprover()
                    ? \Illuminate\Support\Facades\Blade::render('@livewire(\'agent-running-pill\')')
                    : '',
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([]) // Dashboard thật làm ở đợt sau
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
    /** Địa chỉ file tĩnh trong public/ kèm ?v=<ngày sửa file> để lấy bản mới sau mỗi lần cập nhật dù trình duyệt đang giữ cache. */
    public static function versionedAsset(string $path): string
    {
        return asset($path) . '?v=' . (int) @filemtime(public_path($path));
    }
}
