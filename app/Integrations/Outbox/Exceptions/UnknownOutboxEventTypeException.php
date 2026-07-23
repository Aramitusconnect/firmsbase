<?php

declare(strict_types=1);

namespace App\Integrations\Outbox\Exceptions;

use RuntimeException;

/**
 * UnknownOutboxEventTypeException — thrown by
 * App\Integrations\Outbox\OutboxEventHandlerRegistry::get() when asked
 * to resolve an event_type with no entry in that registry's private,
 * in-class HANDLERS constant (Checkpoint 8,
 * agent-8b-outbox-dispatch-design.md §2) — deliberately NOT
 * config('integrations.outbox_handlers'); this checkpoint's closed
 * config-key allowlist does not include an outbox_handlers key. An
 * unmapped event_type is
 * ALWAYS a permanent failure — no handler exists to ever succeed on
 * retry — App\Jobs\OutboxDispatchJob treats this exception identically
 * to OutboxHandlerPermanentException, dead-lettering immediately rather
 * than burning attempts against a rescue that can never happen. The
 * message intentionally includes only the offending event_type value,
 * never any internal class name or file path.
 */
final class UnknownOutboxEventTypeException extends RuntimeException
{
    public function __construct(string $eventType)
    {
        parent::__construct(sprintf('Unknown outbox event type: "%s".', $eventType));
    }
}
