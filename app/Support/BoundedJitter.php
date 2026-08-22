<?php

declare(strict_types=1);

namespace App\Support;

use Random\IntervalBoundary;
use Random\Randomizer;

/**
 * BoundedJitter — deterministic, injectable jitter applied ON TOP OF an
 * already-computed backoff delay (e.g.
 * App\Services\WebhookRetryPolicyService::nextAttemptDelaySeconds(),
 * left completely unmodified by this class). Never calls
 * mt_rand()/rand() directly; the unit-interval source is always
 * caller-supplied, so every test can inject a fixed value (or a
 * scripted sequence) instead of a real RNG.
 *
 * FROZEN DESIGN (agent-8h-architecture-security-review.md §1 item 5 /
 * agent-8e-retry-backoff-ratelimit-design.md §3): this class is NEW and
 * SEPARATE from WebhookRetryPolicyService — it is NOT wired into
 * nextAttemptDelaySeconds()/isExhausted()'s default retry path in this
 * checkpoint. Doing so would break the two existing outbox backoff
 * tolerance-window tests ([28,35]/[58,65] in
 * IntegrationOutboxTransactionDurabilityTest) and
 * WebhookRetryPolicyServiceTest's own "no jitter" determinism
 * assertion. It is safe to use immediately for genuinely NEW code
 * paths that have no existing test to conflict with (e.g. a rate-limit
 * cooldown / proactive-throttle path).
 */
final class BoundedJitter
{
    /**
     * @param  \Closure(): float  $unitIntervalSource  returns a value in [0.0, 1.0)
     */
    public function __construct(private readonly \Closure $unitIntervalSource) {}

    /**
     * Production wiring: PHP 8.3's Random\Randomizer (itself built for
     * an injectable Random\Engine, defaulting to Random\Engine\Secure).
     */
    public static function withRandomizer(Randomizer $randomizer): self
    {
        return new self(static fn (): float => $randomizer->getFloat(0.0, 1.0, IntervalBoundary::ClosedOpen));
    }

    /**
     * Test wiring: always the same unit fraction. 0.5 maps to exactly
     * zero shift (see apply()'s mapping); 0.0/1.0 map to the two
     * extremes of the configured bound.
     */
    public static function deterministic(float $fixedUnit): self
    {
        return new self(static fn (): float => $fixedUnit);
    }

    /**
     * Test wiring for a scripted, non-repeating sequence — once the
     * queue is exhausted, repeats the final supplied value.
     */
    public static function sequence(float ...$units): self
    {
        $queue = $units;

        return new self(static function () use (&$queue, $units): float {
            return array_shift($queue) ?? end($units);
        });
    }

    /**
     * $fraction is the bound as a proportion of $delaySeconds (0.10 =
     * +/-10%). Result is always >= 0 (never negative, never "jitters
     * past zero into the past").
     */
    public function apply(int $delaySeconds, float $fraction = 0.10): int
    {
        $unit = ($this->unitIntervalSource)();       // [0.0, 1.0)
        $signedUnit = ($unit * 2.0) - 1.0;            // [-1.0, 1.0)
        $jitterSeconds = (int) round($delaySeconds * $fraction * $signedUnit);

        return max(0, $delaySeconds + $jitterSeconds);
    }
}
