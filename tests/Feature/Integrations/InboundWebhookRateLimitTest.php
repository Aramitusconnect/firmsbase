<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * InboundWebhookRateLimitTest — Checkpoint 7's required IP-keyed
 * throttling (reviews/checkpoint-07/frozen-design-post-security-review.md
 * §12): `throttle:60,1` on the route. A 429 is a disclosed, acceptable
 * exception to the collapse-to-false uniformity — orthogonal to
 * content correctness.
 */
final class InboundWebhookRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Flushing the rate limiter's backing cache store is required
        // here specifically (not just for hygiene): the array cache
        // store used in the testing environment persists in-process
        // across every test in the same PHPUnit run, and the throttle
        // middleware keys on source IP — without this, hit counts from
        // unrelated Checkpoint 7 HTTP tests earlier in the same run
        // would silently accumulate against this test's own budget.
        Cache::flush();
    }

    public function test_the_61st_request_within_a_minute_from_the_same_source_returns_429(): void
    {
        $headers = [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ];
        $body = '{}';

        $lastStatus = null;

        for ($i = 1; $i <= 61; $i++) {
            $response = $this->postWebhook('test', $headers, $body);
            $lastStatus = $response->getStatusCode();

            if ($i < 61) {
                $this->assertNotSame(429, $lastStatus, "Request #{$i} must not be rate-limited yet.");
            }
        }

        $this->assertSame(429, $lastStatus, 'The 61st request within the same minute from the same source must be rate-limited.');
    }

    public function test_a_rate_limited_response_still_carries_a_json_body(): void
    {
        $headers = [
            'X-Test-Provider-Connection-Token' => Str::random(43),
            'X-Test-Provider-Signature' => 'v1='.str_repeat('a', 64),
            'X-Test-Provider-Timestamp' => (string) now()->getTimestamp(),
        ];
        $body = '{}';

        for ($i = 1; $i <= 60; $i++) {
            $this->postWebhook('test', $headers, $body);
        }

        $response = $this->postWebhook('test', $headers, $body);
        $response->assertStatus(429);
    }

    private function postWebhook(string $provider, array $headers, string $body): TestResponse
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/webhooks/integrations/{$provider}", [], [], [], $server, $body);
    }
}
