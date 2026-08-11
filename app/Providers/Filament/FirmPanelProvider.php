<?php

namespace App\Providers\Filament;

use App\Filament\Firm\Livewire\FirmTopbar;
use App\Filament\Firm\Pages\Auth\Login;
use App\Filament\Firm\Pages\Auth\RequestPasswordReset;
use App\Filament\Firm\Pages\Auth\ResetPassword;
use App\Filament\MultiFactor\AuditedFirmUserAppAuthentication;
use App\Http\Middleware\ApplyTenantDatabaseContext;
use App\Http\Middleware\ConfigurePanelSessionCookie;
use App\Http\Middleware\EnforceSessionTimeouts;
use App\Http\Middleware\EnsureFirmUserMfaComplianceOrRedirectToEnrollment;
use App\Http\Middleware\EstablishFirmTenantContext;
use App\Http\Middleware\EstablishPanelAuthGuardDefault;
use App\Services\CanonicalUrlService;
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
 *  - `->multiFactorAuthentication([AuditedFirmUserAppAuthentication::make()->
 *    recoverable()], isRequired: false)`:
 *
 *    MISSION 1C (Security Validation, Activation & Staging Proof)
 *    UPDATE: this used to be the STOCK `Filament\Auth\MultiFactor\App\
 *    AppAuthentication`, deliberately not
 *    `App\Filament\MultiFactor\AuditedAppAuthentication` — that
 *    Platform-Admin-specific subclass's `recordIfPlatformAdmin()` hook
 *    is a silent no-op for anything not `instanceof PlatformAdmin`, so
 *    reusing it here would have recorded nothing while implying (by
 *    class name alone) that firm-user MFA events are audited. Section
 *    19 of that mission built the real thing instead:
 *    `App\Filament\MultiFactor\AuditedFirmUserAppAuthentication` — a
 *    firm-scoped sibling (see its own docblock), writing real,
 *    append-only `security_events` rows via `FirmUserAuditEventRecorder`,
 *    category `firm_user_mfa`, `firm_id` populated from the acting
 *    user's own `activeFirmUser()->firm`. TOTP is the only enabled
 *    provider (matches the admin panel's own choice, same
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
 *    MISSION 1C (Security Validation, Activation & Staging Proof)
 *    UPDATE: the paragraph that used to be here claimed the real
 *    hard-enforcement boundary was `User::canAccessPanel()`, "UNCHANGED
 *    BY THIS FILE" — no longer accurate, corrected here rather than
 *    left stale. `canAccessPanel()` used to hard-`return false` (a
 *    panel-wide 403, no path to any page including one that could fix
 *    it) for a non-compliant user whose firm requires 2FA — a real
 *    lockout risk Mission 1B found and deliberately did not work
 *    around. That check has moved to
 *    `EnsureFirmUserMfaComplianceOrRedirectToEnrollment` (registered in
 *    `authMiddleware()` below, immediately after `Authenticate::class`),
 *    which redirects a non-compliant user to the profile page instead
 *    of denying them outright — the real enforcement (the same
 *    `FirmUser2faPolicyService` + `TenantContextService::
 *    runWithFirmContext()` check) is unchanged, only WHERE it lives and
 *    HOW it responds. `firm_user_2fa_mode` toggling to `Required` is
 *    still deliberately NOT exposed anywhere in the self-service UI —
 *    see `FirmSettingsPage`'s own docblock — this change only makes it
 *    SAFE to do so later (a non-compliant user now gets a working path
 *    to become compliant instead of being locked out); it does not
 *    itself flip any firm's enforcement policy.
 *
 * Mission 1 (canonical reconstruction — Domain & Security Boundary
 * Architecture) moved this panel from a path prefix on the single
 * legacy hostname (`/firm`) to its own canonical hostname —
 * CanonicalUrlService::firmAppHost(), i.e. app.firmsvault.com in
 * production — at path `''` (root of that host). A GET-only
 * compatibility redirect from the legacy `/firm/*` path is registered
 * separately (routes/web.php). ConfigurePanelSessionCookie is
 * prepended to ->middleware() so this panel gets its own distinctly-
 * named, host-only session cookie.
 */
class FirmPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('firm')
            ->domain(app(CanonicalUrlService::class)->firmAppHost())
            ->path('')
            // Login overridden with App\Filament\Firm\Pages\Auth\Login —
            // Mission 1B (Extreme Security Hardening), section 13: gives
            // this panel its own account-throttle + IP rate-limit
            // identity, distinct from the Client Portal (previously both
            // shared Filament's base Login class and its rate-limit
            // bucket).
            ->login(Login::class)
            // requestAction overridden with
            // App\Filament\Firm\Pages\Auth\RequestPasswordReset for the
            // same rate-limit-bucket-isolation reason as Login above.
            // resetAction overridden with App\Filament\Firm\Pages\Auth\ResetPassword —
            // Filament's own stock page refuses to complete a reset unless
            // canAccessPanel() already returns true, which can never be
            // true yet for a brand-new invited owner (no ACTIVE FirmUser
            // membership exists until THIS completion creates one — see
            // that class's own docblock for the full deadlock this
            // resolves).
            ->passwordReset(requestAction: RequestPasswordReset::class, resetAction: ResetPassword::class)
            ->profile()
            ->multiFactorAuthentication(
                [AuditedFirmUserAppAuthentication::make()->recoverable()],
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
                ConfigurePanelSessionCookie::class.':firm',
                EstablishPanelAuthGuardDefault::class.':web',
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
                // Mission 1C (Security Validation, Activation & Staging
                // Proof), section 5: redirects a 2FA-required-but-not-
                // yet-compliant user to the profile page instead of
                // User::canAccessPanel() hard-denying the whole panel —
                // see that middleware's own docblock for why this can't
                // be Filament's native isRequired mechanism. Placed
                // immediately after Authenticate::class (which is what
                // resolves the acting user in the first place) and
                // before tenant-context middleware, matching
                // EnsurePlatformAdminMfaIsEnrolledAndVerified's placement
                // on the Admin panel exactly.
                EnsureFirmUserMfaComplianceOrRedirectToEnrollment::class,
                EstablishFirmTenantContext::class,
                ApplyTenantDatabaseContext::class,
            ]);
    }
}
