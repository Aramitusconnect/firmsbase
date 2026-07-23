<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Support;

use App\Integrations\Support\PerConnectionRateLimiter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PerConnectionRateLimiterTest — Checkpoint 8
 * (agent-8e-retry-backoff-ratelimit-design.md §7;
 * agent-8h-architecture-security-review.md §4.2). MUST prove keying is
 * firm_integration_id-ONLY — a cross-connection and cross-firm bleed
 * test is mandatory: two different connections/firms must never share
 * rate-limit state, both for multi-tenancy AND because a single firm
 * can hold multiple connections to the same provider, each with its
 * own independent budget.
 */
class PerConnectionRateLimiterTest extends TestCase
{
    private PerConnectionRateLimiter $limiter;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->limiter = new PerConnectionRateLimiter(app(RateLimiter::class));
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    // ------------------------------------------------------------
    // Basic attempt()/availableIn()/clear() behavior
    // ------------------------------------------------------------

    public function test_attempt_returns_true_while_under_budget(): void
    {
        $this->assertTrue($this->limiter->attempt(101, 3, 60));
        $this->assertTrue($this->limiter->attempt(101, 3, 60));
        $this->assertTrue($this->limiter->attempt(101, 3, 60));
    }

    public function test_attempt_returns_false_once_budget_is_exhausted(): void
    {
        $this->limiter->attempt(101, 2, 60);
        $this->limiter->attempt(101, 2, 60);

        $this->assertFalse($this->limiter->attempt(101, 2, 60), 'The third attempt within the same window must be rejected once the 2-attempt budget is exhausted.');
    }

    public function test_available_in_reports_a_positive_wait_once_exhausted(): void
    {
        $this->limiter->attempt(101, 1, 120);
        $this->limiter->attempt(101, 1, 120); // exhausts the budget

        $this->assertGreaterThan(0, $this->limiter->availableIn(101));
    }

    public function test_clear_resets_the_budget_for_that_connection(): void
    {
        $this->limiter->attempt(101, 1, 120);
        $this->assertFalse($this->limiter->attempt(101, 1, 120), 'Sanity check: budget must be exhausted before clear().');

        $this->limiter->clear(101);

        $this->assertTrue($this->limiter->attempt(101, 1, 120), 'A cleared connection must be able to attempt again immediately.');
    }

    // ------------------------------------------------------------
    // MANDATORY: cross-connection bleed test — two different
    // connections must never share rate-limit state.
    // ------------------------------------------------------------

    public function test_two_different_connections_never_share_rate_limit_state(): void
    {
        $connectionA = 201;
        $connectionB = 202;

        // Exhaust connection A's budget completely.
        $this->limiter->attempt($connectionA, 1, 120);
        $this->assertFalse($this->limiter->attempt($connectionA, 1, 120), 'Sanity check: connection A must be exhausted.');

        // Connection B, a totally different firm_integration_id, must be
        // completely unaffected — its own budget starts fresh.
        $this->assertTrue(
            $this->limiter->attempt($connectionB, 1, 120),
            'Connection B must have an independent budget — connection A\'s exhaustion must never bleed into connection B.'
        );
    }

    // ------------------------------------------------------------
    // MANDATORY: cross-firm bleed test — same reasoning, phrased at the
    // firm boundary (two connections belonging to two different firms).
    // ------------------------------------------------------------

    public function test_two_connections_belonging_to_different_firms_never_share_rate_limit_state(): void
    {
        // firm_integration_id values chosen to simulate two different
        // firms' connections — PerConnectionRateLimiter's own contract
        // (class docblock) is that it accepts ONLY firm_integration_id,
        // never a firm_id, so there is structurally no way for it to
        // even attempt firm-level keying — this test proves the
        // resulting BEHAVIOR matches that structural guarantee.
        $firmAConnection = 301;
        $firmBConnection = 302;

        $this->limiter->attempt($firmAConnection, 2, 60);
        $this->limiter->attempt($firmAConnection, 2, 60);
        $this->assertFalse($this->limiter->attempt($firmAConnection, 2, 60), 'Sanity check: firm A\'s connection must be exhausted.');

        $this->assertTrue($this->limiter->attempt($firmBConnection, 2, 60), 'Firm B\'s connection budget must be completely independent of firm A\'s.');
        $this->assertTrue($this->limiter->attempt($firmBConnection, 2, 60));
        $this->assertFalse($this->limiter->attempt($firmBConnection, 2, 60), 'Firm B\'s own budget must independently exhaust on its own terms.');

        // clear()-ing firm A must never affect firm B, and vice versa.
        $this->limiter->clear($firmAConnection);
        $this->assertTrue($this->limiter->attempt($firmAConnection, 2, 60), 'Firm A must be usable again after its own clear().');
        $this->assertFalse($this->limiter->attempt($firmBConnection, 2, 60), 'Firm B must remain exhausted — clearing firm A must not have cleared firm B.');
    }

    // ------------------------------------------------------------
    // Key format structurally excludes bare firm_id / provider-key-only
    // keying (agent-8e §7's hard requirement) — verified via the
    // Cache facade directly, proving the underlying cache key is
    // genuinely namespaced by the connection id, not any other value.
    // ------------------------------------------------------------

    public function test_the_underlying_cache_key_is_namespaced_by_firm_integration_id_only(): void
    {
        $this->limiter->attempt(401, 5, 60);

        // Illuminate\Cache\RateLimiter::attempts()/cleanRateLimiterKey()
        // uses the caller-supplied key VERBATIM (htmlentities-cleaned,
        // no internal prefix of its own) — the exact key
        // PerConnectionRateLimiter produces must be
        // "integration-ratelimit:{firmIntegrationId}", queryable
        // directly via the RateLimiter's own attempts() accessor.
        $rateLimiter = app(RateLimiter::class);

        $this->assertSame(
            1,
            $rateLimiter->attempts('integration-ratelimit:401'),
            'The rate limiter must store its hit counter under a key namespaced exactly by firm_integration_id (401), matching PerConnectionRateLimiter::key()\'s documented "integration-ratelimit:{firmIntegrationId}" format.'
        );
    }

    public function test_availability_of_one_connection_is_unaffected_by_a_wholly_unrelated_connection_id_with_a_similar_numeric_prefix(): void
    {
        // Guards against a naive string-concatenation key collision
        // (e.g. connection 40 and connection 401 producing overlapping
        // prefixes) — every key must be delimited/exact, not a loose
        // prefix match.
        $this->limiter->attempt(40, 1, 120);
        $this->assertFalse($this->limiter->attempt(40, 1, 120), 'Sanity check: connection 40 exhausted.');

        $this->assertTrue(
            $this->limiter->attempt(401, 1, 120),
            'Connection 401 must not be treated as related to connection 40 merely because "401" starts with "40".'
        );
    }
}
