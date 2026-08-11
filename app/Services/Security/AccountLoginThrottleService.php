<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\RateLimiter;

/**
 * AccountLoginThrottleService — Mission 1B (Extreme Security
 * Hardening), section 13: "Layered protection: per-account throttling,
 * per-IP throttling, progressive throttling, safe lockout policy."
 *
 * Filament's own Login page already rate-limits per (component class,
 * IP) — see ThrottlesLoginsPerAccount, which now scopes that IP-based
 * bucket per panel too. That alone never limits a distributed
 * credential-stuffing attack spread across many source IPs against a
 * single target account. This service adds the missing account-level
 * layer: keyed by (guard, submitted email), independent of source IP,
 * with its own longer decay window — a slower, wider net around the
 * account itself rather than the connection.
 *
 * Time-based auto-unlock only (no permanent lock, no manual-unlock
 * requirement) — a stolen/compromised-looking account is not
 * permanently disabled by this service alone, matching section 13's
 * "safe lockout policy" requirement and this codebase's existing
 * fail-open-after-a-window convention (see EnforceSessionTimeouts,
 * StepUpAuthenticationService).
 */
class AccountLoginThrottleService
{
    public const MAX_ATTEMPTS = 10;

    public const DECAY_MINUTES = 15;

    private function key(string $guard, string $email): string
    {
        return 'login-account-throttle:'.sha1($guard.'|'.mb_strtolower(trim($email)));
    }

    public function tooManyAttempts(string $guard, string $email): bool
    {
        return RateLimiter::tooManyAttempts($this->key($guard, $email), self::MAX_ATTEMPTS);
    }

    public function hit(string $guard, string $email): void
    {
        RateLimiter::hit($this->key($guard, $email), self::DECAY_MINUTES * 60);
    }

    public function availableIn(string $guard, string $email): int
    {
        return RateLimiter::availableIn($this->key($guard, $email));
    }

    public function clear(string $guard, string $email): void
    {
        RateLimiter::clear($this->key($guard, $email));
    }
}
