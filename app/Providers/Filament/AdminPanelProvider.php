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
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(\Filament\Support\Enums\MaxWidth::Full)
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<link rel="stylesheet" href="' . e(asset('css/seo-studio.css')) . '">'
                    . '<script src="' . e(asset('js/studio-ui.js')) . '"></script>',
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
}
