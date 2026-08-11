<?php

namespace App\Services\Security;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * StepUpAuthenticationService — Mission 1B (Extreme Security
 * Hardening), section 9: "Implement one canonical reusable step-up-
 * authentication architecture. Do not scatter confirm-password logic
 * throughout controllers." Every protected operation (see
 * App\Filament\Support\StepUp\StepUpAuthentication) records and
 * consults this instead of re-implementing its own recent-auth check.
 *
 * Deliberately session-scoped, never DB-scoped: a verification only
 * vouches for the CURRENT browser session and never survives logout or
 * session invalidation. It is also automatically guard-isolated — each
 * canonical panel already runs on its own host-scoped session cookie
 * (Mission 1C), so there is structurally no way for, e.g., a Firm-guard
 * verification to be read as a Platform Admin-guard verification; the
 * guard namespacing here is defense in depth on top of that, not the
 * only thing preventing it.
 */
class StepUpAuthenticationService
{
    private function sessionKey(string $guard): string
    {
        return "step_up_authentication:{$guard}:verified_at";
    }

    public function markVerified(string $guard): void
    {
        session()->put($this->sessionKey($guard), CarbonImmutable::now()->toIso8601String());
    }

    public function verifiedAt(string $guard): ?CarbonImmutable
    {
        $value = session()->get($this->sessionKey($guard));

        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    public function hasRecentVerification(string $guard, int $withinMinutes): bool
    {
        $verifiedAt = $this->verifiedAt($guard);

        if ($verifiedAt === null) {
            return false;
        }

        return $verifiedAt->addMinutes($withinMinutes)->isFuture();
    }

    public function forget(string $guard): void
    {
        session()->forget($this->sessionKey($guard));
    }
}
