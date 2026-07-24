<?php

namespace App\Providers;

use App\Http\Middleware\EstablishFirmTenantContextForLivewireUpdate;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\TenantContextService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
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
            // Fix #0 (Section 39A-3L Phase B6): activeFirmUser() correctly
            // bootstraps via app.current_user_id, unlike a raw firmUsers()
            // query, which returns NULL under firm_users' own FORCE RLS
            // regardless of whether a real active membership exists.
            $firmId = $event->user instanceof User
                ? $event->user->activeFirmUser()?->firm_id
                : null;

            $context = new TenantContextService();

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
            $context = new TenantContextService();
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
