<?php

namespace Tests\Feature\Webhooks\Retry;

use App\Services\WebhookRetryPolicyService;
use Tests\TestCase;

/**
 * Correction #12: deterministic exponential backoff only, no jitter.
 * Default policy: max_attempts=5, base_delay_seconds=30, multiplier=2.
 */
class WebhookRetryPolicyServiceTest extends TestCase
{
    private WebhookRetryPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WebhookRetryPolicyService();
    }

    public function test_default_delay_math_is_deterministic_exponential_backoff(): void
    {
        $policy = WebhookRetryPolicyService::DEFAULT_RETRY_POLICY;

        $this->assertSame(30, $this->service->nextAttemptDelaySeconds(1, $policy));
        $this->assertSame(60, $this->service->nextAttemptDelaySeconds(2, $policy));
        $this->assertSame(120, $this->service->nextAttemptDelaySeconds(3, $policy));
        $this->assertSame(240, $this->service->nextAttemptDelaySeconds(4, $policy));
    }

    public function test_delay_is_deterministic_across_repeated_calls_no_jitter(): void
    {
        $policy = WebhookRetryPolicyService::DEFAULT_RETRY_POLICY;

        $first = $this->service->nextAttemptDelaySeconds(3, $policy);
        $second = $this->service->nextAttemptDelaySeconds(3, $policy);

        $this->assertSame($first, $second);
    }

    public function test_is_exhausted_at_and_beyond_max_attempts(): void
    {
        $policy = ['max_attempts' => 3, 'base_delay_seconds' => 10, 'multiplier' => 2];

        $this->assertFalse($this->service->isExhausted(1, $policy));
        $this->assertFalse($this->service->isExhausted(2, $policy));
        $this->assertTrue($this->service->isExhausted(3, $policy));
        $this->assertTrue($this->service->isExhausted(4, $policy));
    }

    public function test_custom_retry_policy_overrides_defaults(): void
    {
        $policy = ['max_attempts' => 10, 'base_delay_seconds' => 5, 'multiplier' => 3];

        $this->assertSame(5, $this->service->nextAttemptDelaySeconds(1, $policy));
        $this->assertSame(15, $this->service->nextAttemptDelaySeconds(2, $policy));
        $this->assertSame(45, $this->service->nextAttemptDelaySeconds(3, $policy));
    }
}
