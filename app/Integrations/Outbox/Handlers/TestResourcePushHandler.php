<?php

declare(strict_types=1);

namespace App\Integrations\Outbox\Handlers;

use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Outbox\Exceptions\OutboxHandlerPermanentException;
use App\Integrations\Outbox\Exceptions\OutboxHandlerReleaseException;
use App\Integrations\Outbox\Exceptions\OutboxHandlerTransientException;
use App\Integrations\Outbox\OutboxEventHandlerContract;
use App\Integrations\Services\IntegrationExternalMappingService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Services\WebhookRetryPolicyService;

/**
 * TestResourcePushHandler — the one concrete
 * App\Integrations\Outbox\OutboxEventHandlerContract implementation
 * this checkpoint ships, proving the contract end-to-end against
 * TestProvider (Checkpoint 8, agent-8b-outbox-dispatch-design.md §2/§10).
 * Registered under `test.resource.push_retry` in
 * OutboxEventHandlerRegistry. Never branches on provider identity — it
 * resolves the connection's provider polymorphically via
 * ProviderRegistry + `instanceof SupportsPushSyncContract`, exactly as
 * every other framework code path in this mission does.
 *
 * Idempotency (agent-8b §10): the ONLY durable effect this handler
 * performs is IntegrationExternalMappingService::recordMapping()'s own
 * already-proven firstOrCreate-shaped write — a redelivery of the same
 * logical event that has already been mapped simply re-resolves the
 * existing live row, never a duplicate push-confirmation side effect
 * of its own beyond the (idempotent, TestProvider-simulated,
 * zero-network) provider call itself.
 *
 * Expected `fields` shape (from the outbox row's own
 * SanitizedPayloadReference, built upstream by
 * IntegrationOutboxPayloadBuilderService): `local_type` (string),
 * `local_id` (int) — the polymorphic FirmsBase-side identity this
 * push's resulting mapping resolves against.
 */
final class TestResourcePushHandler implements OutboxEventHandlerContract
{
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly OutboundProviderHttpClient $httpClient,
        private readonly IntegrationExternalMappingService $mappings,
    ) {
    }

    public function handle(int $firmId, ?int $firmIntegrationId, string $domainEventId, array $payload): void
    {
        if ($firmIntegrationId === null) {
            throw new OutboxHandlerPermanentException(
                'test_resource_push_requires_a_connection',
                SanitizedProviderHttpException::CATEGORY_CONFIGURATION_ERROR,
            );
        }

        $connection = FirmIntegration::query()->where('id', $firmIntegrationId)->first();

        if ($connection === null) {
            throw new OutboxHandlerPermanentException(
                'test_resource_push_connection_not_found',
                SanitizedProviderHttpException::CATEGORY_CONNECTION_UNAVAILABLE,
            );
        }

        if ($connection->status !== ConnectionStatus::Active) {
            // Not the row's fault — an immediate retry once a human
            // reconnects is expected to succeed; no error worth
            // recording (agent-8b §4's narrow release() escape hatch).
            throw new OutboxHandlerReleaseException('Connection is not Active; releasing for a later attempt.');
        }

        $resourceType = $payload['resource_type'] ?? null;
        $fields = $payload['fields'] ?? [];
        $localType = $fields['local_type'] ?? null;
        $localId = $fields['local_id'] ?? null;

        if (! is_string($resourceType) || ! is_string($localType) || ! is_int($localId)) {
            throw new OutboxHandlerPermanentException(
                'test_resource_push_malformed_payload',
                SanitizedProviderHttpException::CATEGORY_VALIDATION_FAILED,
            );
        }

        $provider = $this->providerRegistry->get(ProviderKey::from($connection->integrationProvider->code));

        if (! $provider instanceof SupportsPushSyncContract) {
            throw new OutboxHandlerPermanentException(
                'test_resource_push_provider_does_not_support_push',
                SanitizedProviderHttpException::CATEGORY_CONFIGURATION_ERROR,
            );
        }

        try {
            $result = $this->httpClient->execute(
                fn () => $provider->push([], $resourceType, $fields),
                'outboxPush',
            );
        } catch (SanitizedProviderHttpException $e) {
            $category = $e->category();

            if (in_array($category, WebhookRetryPolicyService::TERMINAL_CATEGORIES, true)) {
                throw new OutboxHandlerPermanentException("test_resource_push_failed:{$category}", $category);
            }

            throw new OutboxHandlerTransientException("test_resource_push_failed:{$category}", $category);
        }

        $this->mappings->recordMapping(
            $connection,
            $resourceType,
            $localType,
            $localId,
            (string) $result['external_id'],
            SyncDirection::Outbound,
            $result['version_token'] ?? null,
            null,
        );
    }
}
