<?php

declare(strict_types=1);

namespace App\Integrations\Outbox;

use App\Integrations\Outbox\Exceptions\UnknownOutboxEventTypeException;
use App\Integrations\Outbox\Handlers\TestResourcePushHandler;

/**
 * OutboxEventHandlerRegistry — Checkpoint 8
 * (agent-8b-outbox-dispatch-design.md §2), modeled directly on
 * App\Integrations\Core\ProviderRegistry's exact shape: a closed,
 * array-driven map from a stable string key (event_type) to a
 * resolvable class, resolved via the container. What this class must
 * never do is branch on event_type to decide WHAT a handler does — all
 * such logic belongs on the handler class itself.
 *
 * DESIGN NOTE (deviating from agent-8b §2's "config-driven" sketch,
 * disclosed): agent-8h-architecture-security-review.md §2 item 19's
 * exact, closed list of new config/integrations.php keys does not
 * include an `outbox_handlers` entry — only retention/backoff/health
 * keys are authorized additions to that file. This map is therefore a
 * private, in-class constant rather than a config('integrations.
 * outbox_handlers') read — functionally identical (closed, array-
 * driven, no per-key behavior branching), just not sourced from the
 * config file this checkpoint's frozen allowlist does not authorize
 * for this particular key.
 */
final class OutboxEventHandlerRegistry
{
    /**
     * event_type string => FQCN implementing OutboxEventHandlerContract.
     *
     * @var array<string, class-string<OutboxEventHandlerContract>>
     */
    private const HANDLERS = [
        'test.resource.push_retry' => TestResourcePushHandler::class,
    ];

    public function get(string $eventType): OutboxEventHandlerContract
    {
        $class = self::HANDLERS[$eventType] ?? null;

        if ($class === null) {
            throw new UnknownOutboxEventTypeException($eventType);
        }

        return app()->make($class);
    }
}
