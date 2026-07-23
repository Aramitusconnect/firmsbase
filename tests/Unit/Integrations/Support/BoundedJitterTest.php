<?php

declare(strict_types=1);

namespace Tests\Unit\Integrations\Support;

use App\Services\WebhookRetryPolicyService;
use App\Support\BoundedJitter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BoundedJitterTest — Checkpoint 8
 * (agent-8e-retry-backoff-ratelimit-design.md §3;
 * agent-8h-architecture-security-review.md §1 item 5). Proves
 * App\Support\BoundedJitter as a standalone class ONLY: deterministic
 * via an injected unit-interval source (::deterministic()/::sequence()),
 * NEVER real RNG/sleep. Also proves it is NOT wired into
 * App\Services\WebhookRetryPolicyService::nextAttemptDelaySeconds()'s
 * default path — the two existing [28,35]/[58,65] tolerance-window
 * tests in IntegrationOutboxTransactionDurabilityTest remain the sole
 * source of truth for that call path, unmodified by this file.
 */
class BoundedJitterTest extends TestCase
{
    // ------------------------------------------------------------
    // Deterministic unit-interval source — no real RNG/sleep anywhere
    // ------------------------------------------------------------

    public function test_deterministic_unit_0_5_produces_exactly_zero_shift(): void
    {
        $jitter = BoundedJitter::deterministic(0.5);

        // signedUnit = (0.5 * 2) - 1 = 0.0 -> zero jitterSeconds regardless
        // of $delaySeconds/$fraction.
        $this->assertSame(100, $jitter->apply(100, 0.10));
        $this->assertSame(30, $jitter->apply(30, 0.25));
    }

    public function test_deterministic_unit_0_0_produces_the_maximum_negative_shift(): void
    {
        $jitter = BoundedJitter::deterministic(0.0);

        // signedUnit = (0.0 * 2) - 1 = -1.0 -> jitterSeconds = -round(delay * fraction).
        $this->assertSame(90, $jitter->apply(100, 0.10));
    }

    public function test_deterministic_unit_1_0_produces_the_maximum_positive_shift(): void
    {
        $jitter = BoundedJitter::deterministic(1.0);

        // signedUnit = (1.0 * 2) - 1 = 1.0 -> jitterSeconds = +round(delay * fraction).
        $this->assertSame(110, $jitter->apply(100, 0.10));
    }

    public function test_deterministic_source_is_stable_across_repeated_calls(): void
    {
        $jitter = BoundedJitter::deterministic(0.75);

        $first = $jitter->apply(200, 0.10);
        $second = $jitter->apply(200, 0.10);
        $third = $jitter->apply(200, 0.10);

        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
    }

    public function test_result_is_never_negative_even_at_the_maximum_negative_shift_on_a_small_delay(): void
    {
        $jitter = BoundedJitter::deterministic(0.0);

        // delay=1, fraction=1.0 -> a naive -1 shift would go negative;
        // apply() must clamp to zero, never a negative delay.
        $this->assertSame(0, $jitter->apply(1, 1.0));
    }

    // ------------------------------------------------------------
    // Scripted, non-repeating sequence — exhausted queue repeats the
    // final value
    // ------------------------------------------------------------

    public function test_sequence_yields_each_scripted_value_in_order(): void
    {
        $jitter = BoundedJitter::sequence(0.0, 0.5, 1.0);

        $this->assertSame(90, $jitter->apply(100, 0.10)); // 0.0 -> -10
        $this->assertSame(100, $jitter->apply(100, 0.10)); // 0.5 -> 0
        $this->assertSame(110, $jitter->apply(100, 0.10)); // 1.0 -> +10
    }

    public function test_sequence_repeats_the_final_supplied_value_once_exhausted(): void
    {
        $jitter = BoundedJitter::sequence(0.0, 1.0);

        $jitter->apply(100, 0.10); // consumes 0.0
        $jitter->apply(100, 0.10); // consumes 1.0

        // Queue exhausted — every subsequent call must repeat the LAST
        // scripted value (1.0), not fall back to real randomness.
        $this->assertSame(110, $jitter->apply(100, 0.10));
        $this->assertSame(110, $jitter->apply(100, 0.10));
    }

    // ------------------------------------------------------------
    // Bound proportionality
    // ------------------------------------------------------------

    #[DataProvider('boundedFractionProvider')]
    public function test_jitter_never_exceeds_the_configured_fraction_bound(int $delaySeconds, float $fraction): void
    {
        foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $unit) {
            $jitter = BoundedJitter::deterministic($unit);
            $result = $jitter->apply($delaySeconds, $fraction);

            $maxBound = (int) round($delaySeconds * $fraction);

            $this->assertGreaterThanOrEqual(
                max(0, $delaySeconds - $maxBound),
                $result,
                "unit={$unit} must not shift below the configured lower bound."
            );
            $this->assertLessThanOrEqual(
                $delaySeconds + $maxBound,
                $result,
                "unit={$unit} must not shift beyond the configured upper bound."
            );
        }
    }

    public static function boundedFractionProvider(): array
    {
        return [
            'small delay, default 10% fraction' => [30, 0.10],
            'large delay, default 10% fraction' => [3600, 0.10],
            'wide 50% fraction' => [200, 0.50],
        ];
    }

    // ------------------------------------------------------------
    // Never real RNG/sleep — the withRandomizer() factory exists but is
    // NEVER exercised by this test file; every test above uses only
    // deterministic()/sequence(). This test proves that guarantee
    // structurally rather than merely by convention: calling apply()
    // many times against a deterministic source must never vary.
    // ------------------------------------------------------------

    public function test_deterministic_source_never_varies_across_a_large_number_of_calls(): void
    {
        $jitter = BoundedJitter::deterministic(0.42);

        $results = [];
        for ($i = 0; $i < 50; $i++) {
            $results[] = $jitter->apply(1000, 0.10);
        }

        $this->assertCount(1, array_unique($results), 'A deterministic unit-interval source must never produce varying output — any variance would indicate a real RNG/sleep leaked in.');
    }

    // ------------------------------------------------------------
    // Isolation from WebhookRetryPolicyService's default retry path
    // (agent-8h §1 item 5): BoundedJitter is a standalone class with NO
    // call site inside nextAttemptDelaySeconds()/isExhausted(). This
    // test proves the two are fully decoupled — computing a
    // WebhookRetryPolicyService delay and then separately, optionally,
    // applying BoundedJitter on top must reproduce a value that is
    // NEVER silently produced by nextAttemptDelaySeconds() alone.
    // ------------------------------------------------------------

    public function test_bounded_jitter_composes_on_top_of_but_is_never_implicitly_applied_by_webhook_retry_policy_service(): void
    {
        $policy = new WebhookRetryPolicyService();

        // Deterministic, no-jitter delay for attempt 1 of the default
        // policy (base_delay_seconds=30, multiplier=2) -> 30.
        $plainDelay = $policy->nextAttemptDelaySeconds(1, ['max_attempts' => 5]);
        $this->assertSame(30, $plainDelay, 'nextAttemptDelaySeconds() must remain byte-identical/deterministic — untouched by BoundedJitter.');

        // Calling nextAttemptDelaySeconds() again, repeatedly, must
        // always reproduce the SAME value — proving no jitter has been
        // silently spliced into its own internal computation.
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($plainDelay, $policy->nextAttemptDelaySeconds(1, ['max_attempts' => 5]));
        }

        // BoundedJitter is a genuinely SEPARATE, opt-in composition step
        // — applying it on top is the caller's own explicit choice, not
        // something nextAttemptDelaySeconds() does on the caller's
        // behalf.
        $jittered = BoundedJitter::deterministic(1.0)->apply($plainDelay, 0.10);
        $this->assertSame(33, $jittered, 'BoundedJitter::apply() on top of the plain delay must shift it — proving it is a distinct, additive composition, never already baked into nextAttemptDelaySeconds() itself.');
    }
}
