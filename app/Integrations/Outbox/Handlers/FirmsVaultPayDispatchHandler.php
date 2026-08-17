<?php

declare(strict_types=1);

namespace App\Integrations\Outbox\Handlers;

use App\Integrations\Outbox\Exceptions\OutboxHandlerPermanentException;
use App\Integrations\Outbox\OutboxEventHandlerContract;
use App\Models\ProviderCommand;
use App\Services\Pay\ProviderCommandExecutorService;
use App\Services\TenantContextService;

/**
 * FirmsVaultPayDispatchHandler — FirmsVault Pay Gate A3. The outbox
 * handler for 'firmsvault_pay.provider_command.dispatch': loads the
 * ProviderCommand identified by the outbox row's domain_event_id (the
 * command's own uuid — Gate A2's atomic recordOnce() wiring) and hands
 * it to the executor.
 *
 * DELIVERY SEMANTICS. The outbox is at-least-once; economic safety does
 * NOT live here. It lives one layer down, in the executor's claim on
 * the durable at-most-once gate — so a duplicate delivery of this
 * handler is a documented no-op, never a second send (v1.4 §18).
 *
 * A command that no longer resolves is a PERMANENT condition (the
 * command uuid is immutable and the row can never be deleted), so this
 * handler throws the registry's permanent exception rather than
 * retrying forever.
 */
class FirmsVaultPayDispatchHandler implements OutboxEventHandlerContract
{
    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly ProviderCommandExecutorService $executor,
    ) {}

    public function handle(int $firmId, ?int $firmIntegrationId, string $domainEventId, array $payload): void
    {
        $command = $this->tenantContext->runWithFirmContext(
            $firmId,
            fn (): ?ProviderCommand => ProviderCommand::query()->where('uuid', $domainEventId)->first(),
        );

        if ($command === null) {
            throw new OutboxHandlerPermanentException(
                'No provider command exists for dispatch event ['.$domainEventId.'] — permanently undeliverable.'
            );
        }

        $this->executor->execute($command);
    }
}
