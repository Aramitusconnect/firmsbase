<?php

namespace App\Providers\Filament;

use App\Filament\Firm\Livewire\FirmTopbar;
use App\Filament\Firm\Pages\Auth\ResetPassword;
use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\EstablishFirmTenantContext;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
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
 * Custom Resources/Pages now exist under `App\Filament\Firm\Resources`/
 * `App\Filament\Firm\Pages` (Firm Feature Manifest Tier 1 build-out) —
 * the "no custom Resources/Pages/Widgets exist yet" note above described
 * an earlier, pre-Tier-1 state of this provider and no longer applies.
 *
 * `->topbarLivewireComponent(FirmTopbar::class)` + the `TOPBAR_END`
 * `renderHook()` below together implement the panel-wide "Quick Add"
 * menu (Firm Feature Manifest Tier 1-H): `FirmTopbar` (a thin subclass
 * of Filament's own `Topbar` Livewire component — the Filament v4
 * extension point `HasTopbar::topbarLivewireComponent()` exists
 * specifically for this) hosts the two modal-backed Quick Add actions
 * (Client, Payment) so they are mountable from ANY page in this panel,
 * not just their own resource's list page; the render hook injects the
 * dropdown UI (`resources/views/filament/firm/quick-add-menu.blade.php`)
 * into that same component's rendered DOM so `wire:click="mountAction
 * (...)"` resolves against it. See `FirmTopbar`'s own docblock for why
 * this is reuse, not a reimplementation, of each item's real creation
 * flow.
 *
 * `->passwordReset()` ADDED (Platform Firm Provisioning workflow). This
 * panel had NO working password-reset flow at all before this feature —
 * a pre-existing gap ClientPortalPanelProvider's own docblock already
 * names explicitly ("neither FirmPanelProvider nor AdminPanelProvider
 * currently enable it... a pre-existing gap on the other two panels,
 * not something newly introduced or fixed here"). A newly-provisioned
 * firm owner has no password at all until they complete this flow, so
 * enabling it here is a direct, necessary dependency of that feature —
 * mirroring ClientPortalPanelProvider's exact usage. See
 * FirmOwnerInvitationNotification for the notification this unlocks a
 * real destination route for.
 *
 * `->profile()` + `->multiFactorAuthentication(...)` ADDED (Firm Feature
 * Manifest §11 "Security (firm-facing)" — closes the "2FA enforcement
 * exists with no enrollment UI" gap flagged there as an open
 * ComplianceGapRegistryService item, severity High).
 * `FirmUser2faPolicyService`/`User::canAccessPanel()` already enforce
 * `firm_settings.firm_user_2fa_mode = Required` on every login attempt
 * — that enforcement is untouched by this change, and remains the sole
 * hard-blocking boundary. What was missing was a way for a firm user to
 * actually enroll in / recover 2FA at all, which is what this section
 * adds — self-service ONLY, never forced:
 *
 *  - `->profile()` — enables Filament's own built-in EditProfile page
 *    (mirrors AdminPanelProvider's identical, already-working usage;
 *    $isSimple left at its default `true`). This is also where a firm
 *    user manages their own MFA enrollment/recovery codes/disable via
 *    `getMultiFactorAuthenticationContentComponent()` — no bespoke
 *    self-service MFA UI is built here, Filament's is reused as-is,
 *    exactly like the admin panel.
 *
 *  - `->multiFactorAuthentication([AppAuthentication::make()->
 *    recoverable()], isRequired: false)`:
 *
 *    STOCK `Filament\Auth\MultiFactor\App\AppAuthentication`, NOT
 *    `App\Filament\MultiFactor\AuditedAppAuthentication`. That subclass
 *    exists specifically to hook `PlatformAdminAuditEventRecorder`
 *    audit-trail writes onto PlatformAdmin's own MFA lifecycle (see its
 *    own docblock) — its `recordIfPlatformAdmin()` hook is a no-op for
 *    anything that is not `instanceof PlatformAdmin`, so reusing it here
 *    would silently record nothing while implying (by class name alone)
 *    that firm-user MFA events are audited. They are not, today — that
 *    would be new, separate scope (a firm-user-scoped audit event
 *    category feeding `security_events`, not a platform-admin one), not
 *    a free byproduct of reusing this class. Using the stock, honest
 *    `AppAuthentication` avoids that misleading implication. TOTP is the
 *    only enabled provider (matches the admin panel's own choice, same
 *    "EmailAuthentication out of scope" reasoning), `recoverable()`
 *    enables the same 8-recovery-code mechanism already proven safe for
 *    the admin panel — no separate/custom recovery-code system is built.
 *    `App\Models\User` implements `HasAppAuthentication`/
 *    `HasAppAuthenticationRecovery` (added alongside this change,
 *    mirroring `PlatformAdmin`'s identical implementation 1:1) — reusing
 *    the `two_factor_secret`/`two_factor_recovery_codes`/
 *    `two_factor_confirmed_at` columns `FirmUser2faPolicyService`
 *    already reads as its sole compliance signal. This is required for
 *    ANY firm user to be able to log in at all once a provider is
 *    configured here — `AppAuthentication::isEnabled()` throws unless
 *    the user implements that contract, and Filament's `Login` page
 *    calls `isEnabled()` for every login attempt regardless of
 *    `isRequired`.
 *
 *    `isRequired: false` — DELIBERATE, NOT A PLACEHOLDER. Verified
 *    directly against Filament v4 source
 *    (`vendor/filament/filament/src/Panel/Concerns/HasAuth.php` +
 *    `vendor/filament/filament/src/Pages/Concerns/HasRoutes.php`)
 *    before writing this: `isRequired` (bool|Closure) is evaluated via
 *    `Panel::isMultiFactorAuthenticationRequired()` from
 *    `HasRoutes::getRouteMiddleware(Panel $panel)`, a STATIC method
 *    called once per panel from `registerRoutes()` at PANEL ROUTE
 *    REGISTRATION time — i.e. during application boot, before Laravel's
 *    HTTP kernel even begins dispatching the request through its
 *    middleware pipeline, and therefore unconditionally BEFORE
 *    `Authenticate::class`, `EstablishFirmTenantContext::class`, and
 *    `ApplyTenantDatabaseContext::class` (this panel's own
 *    `authMiddleware`, in that order) ever run. At that point there is
 *    no authenticated user, no session, and no tenant/RLS context of
 *    any kind — `Filament::auth()->user()` is not meaningfully
 *    available, and `firm_settings` (FORCE RLS) could not be safely
 *    read even if a user were somehow known. A closure passed here
 *    could therefore NEVER genuinely reflect "this specific
 *    authenticated user's own firm's real `firm_user_2fa_mode`" — it
 *    would only ever see boot-time, user-less/context-less state, no
 *    matter how it was written. This is exactly the unsafe case flagged
 *    in this task's own risk brief ("if wiring it dynamically has any
 *    chance of being evaluated before tenant context is available"), so
 *    per that brief's own instruction, `isRequired` is set to the
 *    static, always-safe `false` rather than guessed at with a closure.
 *    Functionally, `isRequired: false` means: no firm user is ever
 *    forced through Filament's own `SetUpRequiredMultiFactorAuthentication`
 *    page (no route middleware is attached at all, since
 *    `isMultiFactorAuthenticationRequired()` returns false at
 *    registration time) — enrollment is 100% opt-in via the profile
 *    page above. A user who HAS enrolled is still challenged for a TOTP
 *    code at every login (`Filament\Auth\Pages\Login::authenticate()`
 *    calls `$provider->isEnabled($user)` per attempt, independent of
 *    `isRequired` entirely) — so enrolling still provides real login
 *    protection today, it's just never compulsory from this panel
 *    config alone.
 *
 *    THE REAL HARD-ENFORCEMENT BOUNDARY REMAINS
 *    `User::canAccessPanel()`/`FirmUser2faPolicyService`, UNCHANGED BY
 *    THIS FILE. If/when `firm_user_2fa_mode` ever becomes toggleable to
 *    `Required` (still deliberately NOT exposed anywhere in the UI as
 *    of this change — see `FirmSettingsPage`'s own docblock), it is
 *    `canAccessPanel()` — which runs on every panel request via
 *    Filament's own `Authenticate` middleware/`FilamentUser` contract,
 *    fully independent of this file's `isRequired` setting — that would
 *    actually block a non-compliant user, exactly as it already does
 *    today for any firm a platform operator hand-sets to `Required` via
 *    direct DB access. This section only makes it possible for a user
 *    to become compliant in the first place; it grants no new bypass and
 *    removes no existing check.
 */
class FirmPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('firm')
            ->path('firm')
            ->login()
            // resetAction overridden with App\Filament\Firm\Pages\Auth\ResetPassword —
            // Filament's own stock page refuses to complete a reset unless
            // canAccessPanel() already returns true, which can never be
            // true yet for a brand-new invited owner (no ACTIVE FirmUser
            // membership exists until THIS completion creates one — see
            // that class's own docblock for the full deadlock this
            // resolves).
            ->passwordReset(resetAction: ResetPassword::class)
            ->profile()
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()],
                isRequired: false,
            )
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
            // Global "Quick Add" menu (Firm Feature Manifest Tier 1-H) —
            // see FirmTopbar's own docblock for the full mechanism.
            ->topbarLivewireComponent(FirmTopbar::class)
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn (): string => view('filament.firm.quick-add-menu')->render(),
            )
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
