<?php

namespace App\Providers;

use App\Enums\FirmUserStatus;
use App\Models\PlatformAdmin;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

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
            $firmId = $event->user instanceof User
                ? $event->user->firmUsers()->where('status', FirmUserStatus::Active->value)->value('firm_id')
                : null;

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
        });

        Event::listen(Failed::class, function (Failed $event): void {
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
        });
    }
}
