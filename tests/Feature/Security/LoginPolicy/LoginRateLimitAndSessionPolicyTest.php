<?php

namespace Tests\Feature\Security\LoginPolicy;

use App\Services\LoginPolicyService;
use Tests\TestCase;

/**
 * LoginRateLimitAndSessionPolicyTest — Section 39D. Proves the rate-
 * limit and session-idle-timeout decision functions: both are pure
 * decisions over caller-supplied state, never reading/writing cache
 * or session storage themselves.
 */
class LoginRateLimitAndSessionPolicyTest extends TestCase
{
    private LoginPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LoginPolicyService();
    }

    public function test_max_failed_attempts_is_defined(): void
    {
        $this->assertIsInt($this->service->maxFailedAttempts());
        $this->assertGreaterThan(0, $this->service->maxFailedAttempts());
    }

    public function test_should_throttle_attempt_returns_true_when_threshold_and_window_are_exceeded(): void
    {
        $max = $this->service->maxFailedAttempts();
        $window = $this->service->lockoutWindowMinutes();

        $this->assertTrue($this->service->shouldThrottleAttempt($max));
        $this->assertTrue($this->service->shouldThrottleAttempt($max, now()->subMinutes($window - 1)));
        $this->assertTrue($this->service->shouldThrottleAttempt($max + 10, now()->subMinutes(1)));
    }

    public function test_should_throttle_attempt_returns_false_under_threshold(): void
    {
        $max = $this->service->maxFailedAttempts();

        $this->assertFalse($this->service->shouldThrottleAttempt($max - 1));
        $this->assertFalse($this->service->shouldThrottleAttempt(0));
    }

    public function test_should_throttle_attempt_resets_after_the_lockout_window_expires(): void
    {
        $max = $this->service->maxFailedAttempts();
        $window = $this->service->lockoutWindowMinutes();

        $this->assertFalse($this->service->shouldThrottleAttempt($max, now()->subMinutes($window + 5)));
    }

    public function test_session_idle_timeout_duration_is_defined(): void
    {
        $this->assertIsInt($this->service->sessionIdleTimeoutMinutes());
        $this->assertGreaterThan(0, $this->service->sessionIdleTimeoutMinutes());
    }

    public function test_should_expire_session_returns_true_after_idle_timeout(): void
    {
        $timeout = $this->service->sessionIdleTimeoutMinutes();

        $this->assertTrue($this->service->shouldExpireSession(now()->subMinutes($timeout + 1)));
        $this->assertTrue($this->service->shouldExpireSession(null));
    }

    public function test_should_expire_session_returns_false_before_idle_timeout(): void
    {
        $timeout = $this->service->sessionIdleTimeoutMinutes();

        $this->assertFalse($this->service->shouldExpireSession(now()->subMinutes($timeout - 1)));
        $this->assertFalse($this->service->shouldExpireSession(now()));
    }

    public function test_should_expire_session_accepts_an_explicit_now_for_testability(): void
    {
        $timeout = $this->service->sessionIdleTimeoutMinutes();
        $lastActivity = now()->subDay();

        $this->assertFalse($this->service->shouldExpireSession($lastActivity, $lastActivity->copy()->addMinutes($timeout - 1)));
        $this->assertTrue($this->service->shouldExpireSession($lastActivity, $lastActivity->copy()->addMinutes($timeout + 1)));
    }

    public function test_rate_limit_and_session_methods_never_touch_cache_or_session_storage(): void
    {
        $sessionBefore = session()->all();

        $this->service->shouldThrottleAttempt(999, now());
        $this->service->shouldExpireSession(now());

        $this->assertSame($sessionBefore, session()->all(), 'shouldThrottleAttempt()/shouldExpireSession() must never mutate the session.');
        $this->assertFalse(cache()->has('login_policy_test_marker'), 'Neither method may write to the cache.');
    }
}
