<?php

namespace App\Providers\Filament;

use App\Filament\Auth\Pages\PlatformAdminLogin;
use App\Filament\MultiFactor\AuditedAppAuthentication;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\ConfigurePanelSessionCookie;
use App\Http\Middleware\EnforceSessionTimeouts;
use App\Http\Middleware\EnsurePlatformAdminMfaIsEnrolledAndVerified;
use App\Http\Middleware\EstablishPanelAuthGuardDefault;
use App\Services\CanonicalUrlService;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
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
 * is the sole coarse access gate (checks is_active only; no tenant
 * context middleware is applied here, so this panel has zero standing
 * access to any firm's tenant data, by omission rather than an explicit
 * bypass check).
 *
 * MFA design proposal §3/§4/§5/§9 wiring, added on top of the above,
 * unchanged:
 *  - ->login(PlatformAdminLogin::class) — removes the "remember me"
 *    checkbox for this panel entirely (see that class's own docblock).
 *  - ->profile() — enables Filament's own built-in EditProfile page,
 *    which is also where a PlatformAdmin manages their own MFA
 *    enrollment/recovery codes/disable via
 *    getMultiFactorAuthenticationContentComponent() — no bespoke
 *    self-service MFA management UI is built here, Filament's is reused
 *    as-is. $isSimple left at its default (true).
 *  - ->multiFactorAuthentication([AuditedAppAuthentication::make()->
 *    recoverable()], isRequired: true) — TOTP is the only enabled
 *    provider (EmailAuthentication is explicitly out of scope per the
 *    design proposal's uncertainty #4), recoverable() enables the
 *    8-recovery-code mechanism, isRequired: true registers Filament's
 *    own SetUpRequiredMultiFactorAuthentication page/route (gated by
 *    EnsurePlatformAdminMfaIsEnrolledAndVerified below, not Filament's
 *    own EnsureMultiFactorAuthenticationIsEnabled, which is
 *    deliberately NOT added here — this custom middleware fully
 *    subsumes its enrollment-check responsibility as one of its five
 *    steps, so adding both would be redundant).
 *  - authMiddleware(): EnsurePlatformAdminMfaIsEnrolledAndVerified
 *    added immediately after Authenticate::class — per the design
 *    proposal's own headline finding, Filament's multi-factor wiring
 *    does not auto-apply any enrollment/verification enforcement on
 *    its own; this is the one explicit fix required to close that gap.
 *
 * Mission 1 (canonical reconstruction) moved this, FirmsVault's
 * highest-security browser zone, off the shared legacy hostname's
 * `/admin` path onto its own canonical hostname — admin.firmsvault.com
 * in production (CanonicalUrlService::adminHost()). A GET-only
 * compatibility redirect from the legacy `/admin/*` path lives in
 * routes/web.php. ConfigurePanelSessionCookie gives this panel its own
 * distinctly-named, host-only session cookie. Real TOTP MFA is
 * untouched by this change.
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
            ->login(PlatformAdminLogin::class)
            ->profile()
            ->passwordReset()
            ->authGuard('platform_admin')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            // Executive Dashboard (Phase 1 FirmsVault Admin Control
            // Center, final scope item): App\Filament\Pages\Dashboard
            // (extends Filament\Pages\Dashboard, routePath stays '/')
            // REPLACES the stock Filament\Pages\Dashboard::class that
            // used to be registered here — see that class's own
            // docblock for why overriding is Filament 4's actual
            // convention for this. discoverPages() below would ALSO
            // auto-discover it from app/Filament/Pages (it is a
            // concrete Page subclass in that directory), but it is kept
            // explicit in ->pages() too so the panel's dashboard
            // registration reads as an intentional choice, not an
            // accident of directory scanning.
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Executive Dashboard widgets live under app/Filament/Widgets
            // — discovered here for Filament's general widget registry,
            // but App\Filament\Pages\Dashboard::getWidgets() below
            // returns its own explicit, ordered list rather than relying
            // on Filament::getWidgets() (the discovery pool this call
            // feeds), so a widget merely existing in this directory does
            // NOT cause it to silently appear on the dashboard.
            //
            // The stock AccountWidget/FilamentInfoWidget registration
            // that used to be here is REMOVED per the mission's explicit
            // "remove the default promotional dashboard content /
            // documentation card / GitHub link / framework version"
            // instruction — FilamentInfoWidget was the sole source of
            // that branding (confirmed by reading
            // vendor/filament/filament/resources/views/widgets/filament-info-widget.blade.php
            // directly), and AccountWidget added no functionality this
            // panel's own ->profile()-driven EditProfile page doesn't
            // already provide.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                ConfigurePanelSessionCookie::class.':admin',
                EstablishPanelAuthGuardDefault::class.':platform_admin',
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                EnforceSessionTimeouts::class.':15,480',
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsurePlatformAdminMfaIsEnrolledAndVerified::class,
            ])
            ->multiFactorAuthentication(
                [AuditedAppAuthentication::make()->recoverable()],
                isRequired: true,
            );
    }
}
