<?php

declare(strict_types=1);

namespace App\Integrations\Outbox\Exceptions;

use RuntimeException;

/**
 * OutboxHandlerPermanentException — thrown by an
 * App\Integrations\Outbox\OutboxEventHandlerContract implementation to
 * force IMMEDIATE dead-letter, regardless of remaining attempts
 * (Checkpoint 8, agent-8b-outbox-dispatch-design.md §4/§6;
 * agent-8e-retry-backoff-ratelimit-design.md §1's terminal categories).
 * App\Jobs\OutboxDispatchJob catches this and calls
 * IntegrationOutboxEventService::fail() with a terminal $category (per
 * App\Services\WebhookRetryPolicyService::TERMINAL_CATEGORIES), which
 * forces isExhausted() to return true on the FIRST occurrence.
 *
 * $sanitizedReason MUST already be a short, non-secret reason string —
 * never a raw exception message, provider response body, or stack
 * trace.
 */
class OutboxHandlerPermanentException extends RuntimeException
{
    public function __construct(
        private readonly string $sanitizedReason,
        private readonly ?string $category = null,
    ) {
        parent::__construct($sanitizedReason);
    }

    public function sanitizedReason(): string
    {
        return $this->sanitizedReason;
    }

    public function category(): ?string
    {
        return $this->category;
    }
}
