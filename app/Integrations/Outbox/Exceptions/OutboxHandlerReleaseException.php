<?php

declare(strict_types=1);

namespace App\Integrations\Outbox\Exceptions;

use RuntimeException;

/**
 * OutboxHandlerReleaseException — thrown by an
 * App\Integrations\Outbox\OutboxEventHandlerContract implementation to
 * signal "this row should go back to the pool immediately, with no
 * error recorded and no attempt-accounting penalty implied beyond what
 * claiming already cost" (Checkpoint 8, agent-8b-outbox-dispatch-design.md
 * §4) — e.g. the target connection is Pending/ReauthorizationRequired
 * right now and an immediate retry (once a human reconnects) is
 * expected to succeed with no reason to believe the very next attempt
 * will fail the same way.
 *
 * A deliberately small, explicit escape hatch, NOT the default failure
 * path — App\Jobs\OutboxDispatchJob catches this and calls
 * IntegrationOutboxEventService::release($id, $lockToken), never
 * fail(). No message field beyond a short internal log line — nothing
 * goes into last_error for this path since none is recorded.
 */
class OutboxHandlerReleaseException extends RuntimeException
{
    public function __construct(string $internalLogReason = 'Handler requested immediate release.')
    {
        parent::__construct($internalLogReason);
    }
}
