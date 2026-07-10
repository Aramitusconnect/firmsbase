<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\EstablishFirmTenantContext;
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
 * FirmPanelProvider — internal login/panel access wiring. The
 * firm-facing panel (path 'firm'), guarded by the default `web` guard
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
            ->path('firm')
            ->login()
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
