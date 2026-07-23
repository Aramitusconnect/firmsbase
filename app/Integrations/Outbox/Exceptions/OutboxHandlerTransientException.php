<?php

declare(strict_types=1);

namespace App\Integrations\Outbox\Exceptions;

use RuntimeException;

/**
 * OutboxHandlerTransientException — thrown by an
 * App\Integrations\Outbox\OutboxEventHandlerContract implementation to
 * signal a retryable failure (Checkpoint 8, agent-8b-outbox-dispatch-design.md
 * §4). App\Jobs\OutboxDispatchJob catches this and calls
 * IntegrationOutboxEventService::fail($id, $lockToken, $sanitizedReason, $category)
 * — $category, when supplied, threads into that service's
 * category-aware exhaustion/backoff (App\Services\WebhookRetryPolicyService).
 *
 * $sanitizedReason MUST already be a short, non-secret reason string —
 * never a raw exception message, provider response body, or stack
 * trace (mirrors
 * App\Integrations\Exceptions\SanitizedProviderHttpException's own
 * category-only discipline).
 */
class OutboxHandlerTransientException extends RuntimeException
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
