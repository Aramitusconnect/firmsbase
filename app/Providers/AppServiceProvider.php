<?php

namespace App\Providers;

use App\Enums\FirmUserStatus;
use App\Http\Middleware\EstablishFirmTenantContextForLivewireUpdate;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerAuthenticationAuditLogging();
        $this->registerLivewireUpdateRoute();
        $this->registerFirmOwnerInvitationAcceptance();
    }

    /**
     * Platform Firm Provisioning workflow. A newly-provisioned firm
     * owner's FirmUser membership starts as FirmUserStatus::Invited —
     * User::canAccessPanel()/LoginPolicyService::canAttemptFirmLogin()
     * both require Active, so an Invited member can complete the
     * password-setup flow (a guest route, not gated by canAccessPanel())
     * but could not actually log in afterward without something
     * flipping that status. Laravel's own password broker fires this
     * standard `PasswordReset` event on every successful reset
     * (Illuminate\Auth\Passwords\PasswordBroker::reset(), which Filament's
     * built-in password-reset page uses unmodified) — reused here rather
     * than forking Filament's page or inventing a bespoke
     * "accept invitation" controller/route.
     *
     * Deliberately unconditional on "is this the first reset": an
     * ordinary later "forgot password" reset re-running this is a
     * harmless no-op (the where('status', Invited) query simply matches
     * nothing once the member is already Active).
     */
    private function registerFirmOwnerInvitationAcceptance(): void
    {
        Event::listen(PasswordReset::class, function (PasswordReset $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            $user = $event->user;

            $context = new TenantContextService;

            $invitedMemberships = $context->withUserContext(
                $user->id,
                fn () => $user->firmUsers()->where('status', FirmUserStatus::Invited->value)->get(),
            );

            foreach ($invitedMemberships as $firmUser) {
                $context->runWithFirmContext($firmUser->firm_id, function () use ($firmUser): void {
                    $firmUser->update([
                        'status' => FirmUserStatus::Active,
                        'invitation_accepted_at' => now(),
                    ]);
                });
            }
        });
    }

    /**
     * CP13 P1 (p1-livewire-fix-frozen-design.md §5). Replace Livewire's
     * default `/livewire/update` route with an identical one that also
     * carries EstablishFirmTenantContextForLivewireUpdate, so firm-panel
     * Filament actions re-establish tenant context BEFORE Livewire's
     * `ModelSynth::hydrate()` re-fetches their FORCE-RLS-protected
     * `#[Locked]` record properties. This provider's boot() runs after
     * LivewireServiceProvider::boot(), and RouteCollection keys by
     * method+URI, so this later `POST /livewire/update` registration
     * overwrites the default one (and `findUpdateRoute()` additionally
     * prefers any `*livewire.update`-named route). URI and update-URI are
     * unchanged; the middleware itself no-ops for every non-firm-panel
     * (Admin/SuperAdmin) component, so those surfaces are unaffected.
     */
    private function registerLivewireUpdateRoute(): void
    {
        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)
            ->middleware(['web', EstablishFirmTenantContextForLivewireUpdate::class])
            ->name('livewire.update'));
    }

    /**
     * Internal login/panel access wiring: records every successful and
     * failed login attempt, across both the `web` (User) and
     * `platform_admin` (PlatformAdmin) guards, into the existing
     * SecurityEvent audit log — no new audit system, no new table.
     * Fires from Laravel's own standard Login/Failed guard events
     * (dispatched by Filament's built-in Login page via Auth::attemptWhen()),
     * so this requires no custom login controller/route of its own.
     * Never logs raw credentials — only the attempted email, never the
     * password.
     */
    private function registerAuthenticationAuditLogging(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            // FirmsVault Admin Control Center MFA design proposal §5
            // (EnsurePlatformAdminMfaIsEnrolledAndVerified's step 5,
            // "reset-stamp check"): stamps the exact moment this
            // platform_admin session was authenticated, entirely
            // independent of the security_events write below (this must
            // still happen even if that write is ever skipped/fails) —
            // there is no other reliable "when did this session log in"
            // signal available to that middleware, since Laravel's own
            // SessionGuard does not track one. Deliberately session-only
            // (not persisted anywhere else): a value that vanishes with
            // the session is exactly the fail-closed behavior that
            // middleware step wants when it cannot find one.
            if ($event->guard === 'platform_admin' && request()->hasSession()) {
                request()->session()->put('platform_admin_mfa_session_authenticated_at', now()->toISOString());
            }

            // Fix #0 (Section 39A-3L Phase B6): activeFirmUser() correctly
            // bootstraps via app.current_user_id, unlike a raw firmUsers()
            // query, which returns NULL under firm_users' own FORCE RLS
            // regardless of whether a real active membership exists.
            $firmId = $event->user instanceof User
                ? $event->user->activeFirmUser()?->firm_id
                : null;

            $context = new TenantContextService;

            if ($firmId !== null) {
                $context->setDatabaseTenantContextForFirmId($firmId);
            } else {
                $context->clearDatabaseTenantContext();
            }

            try {
                SecurityEvent::create([
                    'firm_id' => $firmId,
                    'actor_type' => get_class($event->user),
                    'actor_id' => $event->user->getAuthIdentifier(),
                    'event_type' => 'login_succeeded',
                    'category' => 'authentication',
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'metadata' => ['guard' => $event->guard],
                ]);
            } finally {
                $context->clearDatabaseTenantContext();
            }
        });

        Event::listen(Failed::class, function (Failed $event): void {
            $context = new TenantContextService;
            $context->clearDatabaseTenantContext();

            try {
                SecurityEvent::create([
                    'firm_id' => null,
                    'actor_type' => $event->user !== null
                        ? get_class($event->user)
                        : ($event->guard === 'platform_admin' ? PlatformAdmin::class : User::class),
                    'actor_id' => $event->user?->getAuthIdentifier(),
                    'event_type' => 'login_failed',
                    'category' => 'authentication',
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'metadata' => [
                        'guard' => $event->guard,
                        'attempted_email' => $event->credentials['email'] ?? null,
                    ],
                ]);
            } finally {
                $context->clearDatabaseTenantContext();
            }
        });
    }
}
