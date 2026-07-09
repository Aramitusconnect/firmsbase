<?php

namespace App\Services;

use App\Enums\FirmUserStatus;
use App\Models\Firm;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * LoginPolicyService — Section 39D backend policy only. There is no
 * login route/controller/UI surface yet (confirmed by direct
 * inspection: routes/web.php has only the default welcome route,
 * routes/api.php does not exist, no Fortify/Breeze/auth scaffolding
 * exists, no middleware directory exists) — this service defines the
 * route-independent login policy rules so a future auth surface can
 * consume them safely.
 *
 * This service never processes a real login attempt, never persists a
 * lockout/failed-attempt count (no safe table/model for that exists
 * yet — every method here is a pure decision function operating on
 * caller-supplied state), never mutates a session, and never sends a
 * notification/alert. It is entirely read-only/side-effect-free.
 *
 * canAttemptFirmLogin() reuses the same tenant-membership shape as
 * FirmUser2faPolicyService (Section 39B): a User may only log into a
 * Firm's context through an ACTIVE (FirmUserStatus::Active) FirmUser
 * row — Invited/Suspended/Removed membership, or no membership at
 * all, never authorizes firm-context login.
 */
class LoginPolicyService
{
    private const MIN_PASSWORD_LENGTH = 12;

    private const MAX_FAILED_ATTEMPTS = 5;

    private const LOCKOUT_WINDOW_MINUTES = 15;

    /**
     * Deliberately independent of, and stricter than, config/session.php's
     * generic SESSION_LIFETIME (a framework-wide cookie lifetime) — this
     * is a dedicated login-security idle timeout a future auth surface
     * may apply on top of it.
     */
    private const SESSION_IDLE_TIMEOUT_MINUTES = 30;

    /**
     * Case-insensitive — matches 'password123' and 'Password123' with
     * one entry.
     */
    private const COMMON_WEAK_PASSWORDS = [
        'password', 'password123', 'password1', '12345678', '123456789',
        'qwerty123', 'letmein', 'admin123', 'changeme', 'welcome123',
    ];

    public function passwordMeetsPolicy(string $password): bool
    {
        return empty($this->passwordPolicyFailures($password));
    }

    /**
     * @return array<int, string>
     */
    public function passwordPolicyFailures(string $password): array
    {
        $failures = [];

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $failures[] = 'too_short';
        }

        if (! preg_match('/[A-Z]/', $password)) {
            $failures[] = 'missing_uppercase';
        }

        if (! preg_match('/[a-z]/', $password)) {
            $failures[] = 'missing_lowercase';
        }

        if (! preg_match('/[0-9]/', $password)) {
            $failures[] = 'missing_number';
        }

        if (! preg_match('/[^A-Za-z0-9]/', $password)) {
            $failures[] = 'missing_symbol';
        }

        if (in_array(strtolower($password), self::COMMON_WEAK_PASSWORDS, true)) {
            $failures[] = 'common_weak_value';
        }

        return $failures;
    }

    public function maxFailedAttempts(): int
    {
        return self::MAX_FAILED_ATTEMPTS;
    }

    public function lockoutWindowMinutes(): int
    {
        return self::LOCKOUT_WINDOW_MINUTES;
    }

    public function sessionIdleTimeoutMinutes(): int
    {
        return self::SESSION_IDLE_TIMEOUT_MINUTES;
    }

    /**
     * Decision only — does not read or write any cache/rate-limiter
     * state itself. The caller supplies the current failed-attempt
     * count and (optionally) when the first failure in the current
     * streak occurred; if that first failure has aged out of the
     * lockout window, the streak is considered reset and throttling
     * no longer applies.
     */
    public function shouldThrottleAttempt(int $failedAttempts, ?\DateTimeInterface $firstFailedAt = null): bool
    {
        if ($failedAttempts < self::MAX_FAILED_ATTEMPTS) {
            return false;
        }

        if ($firstFailedAt === null) {
            return true;
        }

        $windowExpiresAt = CarbonImmutable::instance($firstFailedAt)->addMinutes(self::LOCKOUT_WINDOW_MINUTES);

        return CarbonImmutable::now()->lessThan($windowExpiresAt);
    }

    /**
     * Decision only — does not read or write the session itself. A
     * null $lastActivityAt (no recorded activity at all) is treated as
     * expired/not-valid, matching this project's fail-closed default
     * elsewhere (e.g. HighRiskPlatformChangePolicyService).
     */
    public function shouldExpireSession(?\DateTimeInterface $lastActivityAt, ?\DateTimeInterface $now = null): bool
    {
        if ($lastActivityAt === null) {
            return true;
        }

        $now = $now !== null ? CarbonImmutable::instance($now) : CarbonImmutable::now();

        return CarbonImmutable::instance($lastActivityAt)->addMinutes(self::SESSION_IDLE_TIMEOUT_MINUTES)->lessThanOrEqualTo($now);
    }

    /**
     * Tenant-aware: a User may only be considered for firm-context
     * login when it holds an ACTIVE FirmUser membership on that exact
     * Firm — a user active on a different firm, or invited/suspended/
     * removed on this one, is denied.
     */
    public function canAttemptFirmLogin(User $user, Firm $firm): bool
    {
        return (new TenantContextService())->runWithFirmContext($firm, fn () => $user->firmUsers()
            ->where('firm_id', $firm->id)
            ->where('status', FirmUserStatus::Active->value)
            ->exists());
    }

    /**
     * Read-only payload assembly for a future auth surface to persist
     * through its own audit mechanism — this method itself never
     * writes to any table.
     *
     * @return array{user_id: int, user_email: string, firm_id: ?int, ip: ?string, user_agent: ?string, occurred_at: string}
     */
    public function loginAuditPayload(User $user, ?Firm $firm = null, ?string $ip = null, ?string $userAgent = null): array
    {
        return [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'firm_id' => $firm?->id,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'occurred_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * @return array{min_password_length: int, max_failed_attempts: int, lockout_window_minutes: int, session_idle_timeout_minutes: int, common_weak_passwords_blocked: int}
     */
    public function policySummary(): array
    {
        return [
            'min_password_length' => self::MIN_PASSWORD_LENGTH,
            'max_failed_attempts' => self::MAX_FAILED_ATTEMPTS,
            'lockout_window_minutes' => self::LOCKOUT_WINDOW_MINUTES,
            'session_idle_timeout_minutes' => self::SESSION_IDLE_TIMEOUT_MINUTES,
            'common_weak_passwords_blocked' => count(self::COMMON_WEAK_PASSWORDS),
        ];
    }
}
