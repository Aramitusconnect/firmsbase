<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ConfigurePanelSessionCookie;
use App\Services\CanonicalUrlService;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * AdminPanelProvider — the platform-admin panel. Internal login/panel
 * access wiring added authGuard('platform_admin') so this panel is
 * gated by the platform_admins identity table (PlatformAdmin model),
 * never the firm-facing `users` table — PlatformAdmin::canAccessPanel()
 * is the sole access gate (checks is_active only; no tenant context
 * middleware is applied here, so this panel has zero standing access
 * to any firm's tenant data, by omission rather than an explicit
 * bypass check).
 *
 * Mission 1 (Domain & Security Boundary Architecture) moved this,
 * FirmsVault's highest-security browser zone (section 26), off the
 * shared legacy hostname's `/admin` path onto its own canonical
 * hostname — CanonicalUrlService::adminHost(), i.e. admin.firmsvault.com
 * in production. A GET-only compatibility redirect from the legacy
 * `/admin/*` path is registered separately (routes/web.php).
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->domain(app(CanonicalUrlService::class)->adminHost())
            ->path('')
            ->login()
            ->passwordReset()
            ->authGuard('platform_admin')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                ConfigurePanelSessionCookie::class.':admin',
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
