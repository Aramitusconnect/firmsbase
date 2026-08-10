<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\ConfigurePanelSessionCookie;
use App\Http\Middleware\EstablishFirmTenantContext;
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
 * FirmPanelProvider — internal login/panel access wiring. Mission 1
 * (Domain & Security Boundary Architecture) moved this panel from a
 * path prefix on the single legacy hostname (`/firm`) to its own
 * canonical hostname — CanonicalUrlService::firmAppHost(), i.e.
 * app.firmsvault.com in production — at path `''` (root of that host).
 * A GET-only compatibility redirect from the legacy `/firm/*` path on
 * the marketing hostname to this new host is registered separately
 * (routes/web.php) — this panel itself no longer answers on the old
 * path at all, so there is exactly one authoritative registration, not
 * two competing panels.
 *
 * The firm-facing panel, guarded by the default `web` guard
 * (User model) — firm owners/admins/users all authenticate here, and
 * User::canAccessPanel() is the sole gate deciding whether a given
 * authenticated User may enter: active account, an ACTIVE FirmUser
 * membership approved by LoginPolicyService::canAttemptFirmLogin(), and
 * FirmUser2faPolicyService compliance if the firm requires 2FA.
 *
 * EstablishFirmTenantContext + ApplyTenantDatabaseContext are appended
 * to authMiddleware (in that order — the first resolves which firm the
 * request is acting as, the second bridges that resolution into
 * Postgres) so every firm-panel request runs under the correct tenant
 * context, and neither ever leaks past the request that set it.
 *
 * No custom Resources/Pages/Widgets exist yet — like AdminPanelProvider,
 * this proves login/access wiring only; building firm-facing UI is
 * explicitly out of scope for this section.
 */
class FirmPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('firm')
            ->domain(app(CanonicalUrlService::class)->firmAppHost())
            ->path('')
            ->login()
            ->passwordReset()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->discoverResources(in: app_path('Filament/Firm/Resources'), for: 'App\Filament\Firm\Resources')
            ->discoverPages(in: app_path('Filament/Firm/Pages'), for: 'App\Filament\Firm\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Firm/Widgets'), for: 'App\Filament\Firm\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                ConfigurePanelSessionCookie::class.':firm',
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
                EstablishFirmTenantContext::class,
                ApplyTenantDatabaseContext::class,
            ]);
    }
}
