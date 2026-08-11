<?php

namespace App\Filament\Auth\Concerns;

use App\Services\Security\AccountLoginThrottleService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;

/**
 * ThrottlesLoginsPerAccount — Mission 1B (Extreme Security Hardening),
 * section 13. Filament's own Login::authenticate() only rate-limits
 * per (Login page class, source IP) — see WithRateLimiting's
 * getRateLimitKey(). Every canonical panel gets its own Login
 * subclass using this trait for two reasons at once: it naturally
 * gives each panel its own IP-based bucket (static::class differs per
 * panel, closing the "Firm and Client Portal share one bucket"
 * finding from this mission's audit), and it adds the account-level
 * check this vendor page has no hook for at all — a distributed
 * attack spread across many source IPs against one target account is
 * otherwise not throttled by anything in this codebase.
 *
 * The account-level check runs before parent::authenticate() so a
 * throttled account never reaches credential validation at all; the
 * account-level *hit* is recorded separately, from the Failed/Login
 * events (see AppServiceProvider::registerAuthenticationAuditLogging),
 * so it correctly counts every failure regardless of which Login
 * subclass or IP submitted it.
 */
trait ThrottlesLoginsPerAccount
{
    public function authenticate(): ?LoginResponse
    {
        $email = $this->form->getState()['email'] ?? null;

        if (is_string($email) && $email !== '') {
            $throttle = app(AccountLoginThrottleService::class);
            $guard = Filament::getAuthGuard();

            if ($throttle->tooManyAttempts($guard, $email)) {
                $this->getRateLimitedNotification(
                    new TooManyRequestsException(static::class, 'authenticate', request()->ip(), $throttle->availableIn($guard, $email))
                )?->send();

                return null;
            }
        }

        return parent::authenticate();
    }
}
