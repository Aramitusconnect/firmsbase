<?php

declare(strict_types=1);

namespace App\Integrations\Outbox;

use App\Integrations\Outbox\Exceptions\OutboxHandlerPermanentException;
use App\Integrations\Outbox\Exceptions\OutboxHandlerReleaseException;
use App\Integrations\Outbox\Exceptions\OutboxHandlerTransientException;

/**
 * OutboxEventHandlerContract — Checkpoint 8
 * (agent-8b-outbox-dispatch-design.md §2). Implemented by a per-
 * event_type handler class living under App\Integrations\Outbox\Handlers
 * — never under a provider's own namespace. A handler that needs to
 * talk to a provider resolves App\Integrations\Core\ProviderRegistry
 * itself, exactly as any other framework code does; the dispatcher and
 * App\Integrations\Outbox\OutboxEventHandlerRegistry never import or
 * reference a concrete provider class directly.
 *
 * MUST be idempotent under redelivery (agent-8b §10) — the dispatcher
 * makes no promise beyond "you will be called at least once per logical
 * event," never "at most once." The proven pattern every handler must
 * follow is
 * App\Integrations\Services\IntegrationExternalMappingService::recordMapping()'s
 * own discipline: check-for-existing-live-row-first, never a bare
 * create().
 */
interface OutboxEventHandlerContract
{
    /**
     * Performs the durable effect for one claimed outbox event.
     * "Durable result confirmed" reduces to: this method returning
     * normally IS the durability confirmation — the handler's own
     * effect must already be durably committed before returning, not
     * merely "attempted."
     *
     * @param  array{resource_type: ?string, resource_id: ?string, fields: array<string, mixed>}  $payload
     *
     * @throws OutboxHandlerTransientException retry (fail()'s retry branch)
     * @throws OutboxHandlerPermanentException dead-letter immediately, regardless of remaining attempts
     * @throws OutboxHandlerReleaseException re-enter the pool immediately, no error recorded
     */
    public function handle(int $firmId, ?int $firmIntegrationId, string $domainEventId, array $payload): void;
}
