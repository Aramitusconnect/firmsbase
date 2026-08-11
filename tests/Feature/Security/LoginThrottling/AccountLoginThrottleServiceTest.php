<?php

namespace Tests\Feature\Security\LoginThrottling;

use App\Services\Security\AccountLoginThrottleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AccountLoginThrottleServiceTest — Mission 1B (Extreme Security
 * Hardening), section 13.
 */
class AccountLoginThrottleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): AccountLoginThrottleService
    {
        return app(AccountLoginThrottleService::class);
    }

    public function test_no_attempts_is_not_throttled(): void
    {
        $this->assertFalse($this->service()->tooManyAttempts('web', 'user@example.com'));
    }

    public function test_becomes_throttled_after_the_max_attempts(): void
    {
        $service = $this->service();

        for ($i = 0; $i < AccountLoginThrottleService::MAX_ATTEMPTS; $i++) {
            $this->assertFalse($service->tooManyAttempts('web', 'user@example.com'));
            $service->hit('web', 'user@example.com');
        }

        $this->assertTrue($service->tooManyAttempts('web', 'user@example.com'));
    }

    public function test_throttle_is_isolated_per_guard(): void
    {
        $service = $this->service();

        for ($i = 0; $i < AccountLoginThrottleService::MAX_ATTEMPTS; $i++) {
            $service->hit('web', 'user@example.com');
        }

        $this->assertTrue($service->tooManyAttempts('web', 'user@example.com'));
        $this->assertFalse($service->tooManyAttempts('client', 'user@example.com'));
        $this->assertFalse($service->tooManyAttempts('platform_admin', 'user@example.com'));
    }

    public function test_throttle_is_isolated_per_email(): void
    {
        $service = $this->service();

        for ($i = 0; $i < AccountLoginThrottleService::MAX_ATTEMPTS; $i++) {
            $service->hit('web', 'victim@example.com');
        }

        $this->assertTrue($service->tooManyAttempts('web', 'victim@example.com'));
        $this->assertFalse($service->tooManyAttempts('web', 'someone-else@example.com'));
    }

    public function test_email_matching_is_case_insensitive(): void
    {
        $service = $this->service();

        for ($i = 0; $i < AccountLoginThrottleService::MAX_ATTEMPTS; $i++) {
            $service->hit('web', 'User@Example.com');
        }

        $this->assertTrue($service->tooManyAttempts('web', 'user@example.com'));
    }

    public function test_clear_resets_the_counter(): void
    {
        $service = $this->service();

        for ($i = 0; $i < AccountLoginThrottleService::MAX_ATTEMPTS; $i++) {
            $service->hit('web', 'user@example.com');
        }

        $this->assertTrue($service->tooManyAttempts('web', 'user@example.com'));

        $service->clear('web', 'user@example.com');

        $this->assertFalse($service->tooManyAttempts('web', 'user@example.com'));
    }
}
