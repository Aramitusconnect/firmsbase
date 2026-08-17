<?php

declare(strict_types=1);

namespace App\Services\Pay\Data;

use App\Enums\ProviderOutcome;

/**
 * FakeProviderEvent — FirmsVault Pay Gate A3. The provider-NEUTRAL
 * shape of one inbound provider event as it exits verification. Gate B
 * will construct exactly this from a verified Finix webhook; Gate A3
 * constructs it from fake fixtures. Nothing downstream of ingestion may
 * depend on how it was produced.
 *
 * `presentedFirmIntegrationId` is the connection the event ARRIVED
 * through — deliberately separate from whatever the ownership authority
 * resolves, so a wrong-connection presentation is detectable (v1.4 §28).
 */
final class FakeProviderEvent
{
    public function __construct(
        public readonly int $integrationProviderId,
        public readonly string $providerKey,
        /** Provider-assigned event id — the dedupe identity. */
        public readonly string $eventId,
        /** 'payment' | 'refund' — the locator's provider_resource_type. */
        public readonly string $resourceType,
        public readonly string $resourceReference,
        public readonly ProviderOutcome $outcome,
        public readonly ?int $amountCents,
        public readonly string $environment,
        public readonly ?int $presentedFirmIntegrationId = null,
    ) {}
}
