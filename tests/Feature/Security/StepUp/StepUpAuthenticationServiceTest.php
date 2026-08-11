<?php

namespace Tests\Feature\Security\StepUp;

use App\Services\Security\StepUpAuthenticationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StepUpAuthenticationServiceTest — Mission 1B (Extreme Security
 * Hardening), section 9.
 */
class StepUpAuthenticationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): StepUpAuthenticationService
    {
        return app(StepUpAuthenticationService::class);
    }

    public function test_no_verification_recorded_is_not_recently_verified(): void
    {
        $this->assertFalse($this->service()->hasRecentVerification('platform_admin', 5));
        $this->assertNull($this->service()->verifiedAt('platform_admin'));
    }

    public function test_marking_verified_is_recently_verified_within_the_window(): void
    {
        $this->service()->markVerified('platform_admin');

        $this->assertTrue($this->service()->hasRecentVerification('platform_admin', 5));
        $this->assertNotNull($this->service()->verifiedAt('platform_admin'));
    }

    public function test_verification_expires_after_the_window(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 10:00:00'));
        $this->service()->markVerified('platform_admin');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 10:04:59'));
        $this->assertTrue($this->service()->hasRecentVerification('platform_admin', 5));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-01 10:05:01'));
        $this->assertFalse($this->service()->hasRecentVerification('platform_admin', 5));

        CarbonImmutable::setTestNow();
    }

    public function test_verification_is_isolated_per_guard(): void
    {
        $this->service()->markVerified('platform_admin');

        $this->assertTrue($this->service()->hasRecentVerification('platform_admin', 5));
        $this->assertFalse($this->service()->hasRecentVerification('web', 5));
        $this->assertFalse($this->service()->hasRecentVerification('client', 5));
    }

    public function test_forget_clears_the_verification(): void
    {
        $this->service()->markVerified('platform_admin');
        $this->assertTrue($this->service()->hasRecentVerification('platform_admin', 5));

        $this->service()->forget('platform_admin');

        $this->assertFalse($this->service()->hasRecentVerification('platform_admin', 5));
    }
}
