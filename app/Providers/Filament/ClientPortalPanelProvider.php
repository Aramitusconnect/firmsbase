<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\ConfigurePanelSessionCookie;
use App\Http\Middleware\EstablishClientPortalTenantContext;
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
 * ClientPortalPanelProvider — Mission 1 (Domain & Security Boundary
 * Architecture). No Client Portal Filament panel existed anywhere in
 * this repository before this mission (confirmed by the mission's own
 * Phase A audit: no app/Filament directory, no `client` auth guard);
 * building this minimal login/access skeleton — the Client Portal's
 * actual feature surface (Documents, Messages, Appointments, Invoices,
 * Payments, Forms, Profile) remains explicitly out of scope, exactly
 * as it was already gated behind future phases before this mission
 * (see ClientPortalService's own docblock) — is the smallest change
 * that lets client.firmsvault.com become a real, independently
 * session-isolated, testable authenticated surface, matching
 * FirmPanelProvider's and AdminPanelProvider's own shape and
 * middleware ordering exactly: EstablishClientPortalTenantContext then
 * ApplyTenantDatabaseContext, guard `client` (App\Models\Client — see
 * config/auth.php and app/Models/Client.php).
 *
 * Domain-bound at CanonicalUrlService::clientPortalHost() (i.e.
 * client.firmsvault.com in production), path `''`. No legacy path
 * predates this panel, so there is nothing to redirect from — unlike
 * the Firm/Admin panels, this one was never reachable any other way.
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
            ->authGuard('client')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->discoverResources(in: app_path('Filament/Client/Resources'), for: 'App\Filament\Client\Resources')
            ->discoverPages(in: app_path('Filament/Client/Pages'), for: 'App\Filament\Client\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Client/Widgets'), for: 'App\Filament\Client\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                ConfigurePanelSessionCookie::class.':client',
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
                EstablishClientPortalTenantContext::class,
                ApplyTenantDatabaseContext::class,
            ]);
    }
}
