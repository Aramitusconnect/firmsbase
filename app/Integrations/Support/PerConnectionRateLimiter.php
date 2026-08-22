<?php

declare(strict_types=1);

namespace App\Integrations\Support;

use Illuminate\Cache\RateLimiter;

/**
 * PerConnectionRateLimiter — thin wrapper enforcing the hard rule that
 * every rate-limit key is namespaced by firm_integration_id and NOTHING
 * ELSE. No method on this class accepts a bare provider key or firm_id
 * as a standalone cache key — firm_integration_id is required in every
 * call, structurally preventing cross-tenant / cross-connection
 * bleed.
 *
 * HARD REQUIREMENT (agent-8e-retry-backoff-ratelimit-design.md §7):
 * rate-limit state must be keyed per firm_integration_id, never
 * globally, never per-firm_id-alone, and never shared across
 * connections — both for multi-tenancy (one firm's provider throttling
 * must never slow/starve another firm) and because a single firm can
 * hold multiple connections to the same provider (each with its own,
 * independent rate-limit budget on the provider's side).
 *
 * This is a PROACTIVE, cache-based gate — additive to, never a
 * replacement for, the outbox's own REACTIVE row-level
 * next_attempt_at eligibility predicate (which already naturally
 * skips a still-cooling-down row, firm-scoped, with no new mechanism
 * required).
 */
final class PerConnectionRateLimiter
{
    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * True if this connection is currently within its configured
     * budget (and consumes one unit of it).
     */
    public function attempt(int $firmIntegrationId, int $maxAttemptsPerWindow, int $windowSeconds): bool
    {
        return $this->limiter->attempt(
            $this->key($firmIntegrationId),
            $maxAttemptsPerWindow,
            static fn (): bool => true,
            $windowSeconds,
        );
    }

    public function availableIn(int $firmIntegrationId): int
    {
        return $this->limiter->availableIn($this->key($firmIntegrationId));
    }

    public function clear(int $firmIntegrationId): void
    {
        $this->limiter->clear($this->key($firmIntegrationId));
    }

    private function key(int $firmIntegrationId): string
    {
        return "integration-ratelimit:{$firmIntegrationId}";
    }
}
