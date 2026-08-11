<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\ConfigurePanelSessionCookie;
use App\Http\Middleware\EnforceSessionTimeouts;
use App\Http\Middleware\EstablishClientPortalTenantContext;
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
 * ClientPortalPanelProvider — Checkpoint 4 ("Plaid financial evidence
 * add-on"), Client Portal authentication foundation
 * (checkpoint4-combined-design.md §5;
 * checkpoint4-design-matter-and-client-portal.md §2.3). The panel's
 * Filament-internal identifier is `client-portal`. It was originally
 * mounted at path `portal` on the single legacy hostname (per
 * checkpoint4-combined-design.md §1.3's found-and-fixed URL-path
 * drift) — Mission 1 (canonical reconstruction — Domain & Security
 * Boundary Architecture) moved it to its own canonical hostname,
 * client.firmsvault.com in production (CanonicalUrlService::
 * clientPortalHost()), at path `''` (root of that host). The sibling
 * `plaid/exchange` route (formerly `portal/plaid/exchange`) moved with
 * it — see routes/web.php. A GET-only compatibility redirect from the
 * legacy `/portal/*` path lives in routes/web.php.
 * ConfigurePanelSessionCookie gives this panel its own distinctly-
 * named, host-only session cookie.
 *
 * `authGuard('client')` — same explicit-guard pattern
 * `AdminPanelProvider` already uses for `platform_admin`, never relies
 * on the implicit default `web` guard.
 *
 * `EstablishClientPortalTenantContext` + `ApplyTenantDatabaseContext`
 * are appended to authMiddleware (in that order — the first resolves
 * the two-hop client/firm identity the request is acting as, the
 * second bridges that resolution into Postgres), mirroring
 * `FirmPanelProvider`'s identical ordering discipline.
 * `ApplyTenantDatabaseContext` itself is reused byte-for-byte, zero
 * changes — it is already guard-agnostic (only reads
 * `TenantContextService::hasFirmContext()`).
 *
 * `->passwordReset()` is enabled here deliberately (neither
 * `FirmPanelProvider` nor `AdminPanelProvider` currently enable it) —
 * the directive explicitly requires "secure password setup/reset" for
 * the Client Portal specifically (judgment call §2.7.b of the source
 * design doc: this is a pre-existing gap on the other two panels, not
 * something newly introduced or fixed here).
 *
 * MFA is deliberately NOT added to this panel (judgment call §2.3/§2.7.c
 * of the source design doc) — no blanket product-architecture rule
 * requires it for every identity type (MFA is currently required only
 * for `platform_admin`, and only conditionally, per-firm, for `web`);
 * inventing a client-specific MFA policy is new product scope this
 * checkpoint was not authorized to add.
 *
 * Colors: same `Color::Blue` primary as the `firm` panel, per the
 * directive's "reuse FirmsVault branding and the existing design
 * system" — this codebase has no separate token file, so concretely
 * that means reusing the same `Color::Blue` call, nothing else exists
 * to copy (confirmed by the pre-construction inventory's own §1
 * finding).
 */
class ClientPortalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('client-portal')
            ->domain(app(CanonicalUrlService::class)->clientPortalHost())
            ->path('')
            ->login()
            ->passwordReset()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->discoverResources(in: app_path('Filament/ClientPortal/Resources'), for: 'App\Filament\ClientPortal\Resources')
            ->discoverPages(in: app_path('Filament/ClientPortal/Pages'), for: 'App\Filament\ClientPortal\Pages')
            ->authGuard('client')
            ->middleware([
                ConfigurePanelSessionCookie::class.':client',
                EstablishPanelAuthGuardDefault::class.':client',
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                EnforceSessionTimeouts::class.':30,1440',
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EstablishClientPortalTenantContext::class,
                ApplyTenantDatabaseContext::class,
            ]);
    }
}
