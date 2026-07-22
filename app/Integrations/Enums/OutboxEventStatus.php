<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * OutboxEventStatus — lifecycle state of an `integration_outbox_events`
 * row (Checkpoint 6, frozen-design-post-review.md §7;
 * agent-6d-outbox-claiming.md §2/§9). Plain string column, no DB-level
 * enum type. Exactly 5 states — two candidates deliberately rejected:
 *
 * `retry_scheduled` — rejected. A retryable failure returns the row to
 * Pending with `next_attempt_at` pushed into the future; the claim
 * query's own `next_attempt_at <= now()` predicate is what actually
 * gates eligibility. A separate state would carry no information
 * Pending + next_attempt_at doesn't already carry.
 *
 * `failed` — rejected as a resting state. Every failure is resolved
 * immediately, in the same guarded UPDATE, into exactly one of Pending
 * (attempts remain, retryable) or DeadLettered (attempts exhausted or
 * explicitly non-retryable). A row is never observed sitting in a bare
 * "failed" state with an ambiguous next step.
 *
 * Terminal states: Completed, DeadLettered, Cancelled — no transition
 * originates from any of these; every guarded UPDATE names its
 * required starting state explicitly (see
 * IntegrationOutboxEventService), so an attempt to act on a
 * already-terminal row always affects zero rows.
 */
enum OutboxEventStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case DeadLettered = 'dead_lettered';
    case Cancelled = 'cancelled';
}
