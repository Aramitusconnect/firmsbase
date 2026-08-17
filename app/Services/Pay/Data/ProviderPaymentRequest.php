<?php

declare(strict_types=1);

namespace App\Services\Pay\Data;

use App\Models\ProviderCommand;

/**
 * ProviderPaymentRequest — FirmsVault Pay Gate A3 (v1.4 §6). The
 * canonical, provider-NEUTRAL command input every PaymentProviderAdapter
 * receives. Core never passes a Finix Transfer, a Stripe PaymentIntent,
 * a provider-native Merchant, or any provider-native object across this
 * boundary — only these fields.
 *
 * Built exclusively from the immutable ProviderCommand envelope (plus
 * the executor's environment context), so the adapter input can never
 * drift from the durable economic instruction it executes.
 */
final class ProviderPaymentRequest
{
    public function __construct(
        /** The immutable ProviderCommand uuid — the command identity. */
        public readonly string $commandUuid,
        /** Deterministic key for the durable at-most-once send gate. */
        public readonly string $logicalOperationKey,
        public readonly int $firmId,
        /** FirmProviderAccount role — the FirmIntegration id. */
        public readonly ?int $firmIntegrationId,
        /** ProviderPlatformConnection role — the IntegrationProvider id. */
        public readonly ?int $integrationProviderId,
        public readonly int $amountCents,
        /** POC #1: always 'USD'. */
        public readonly string $currency,
        /** 'capture_payment' | 'refund_payment' — the command type value. */
        public readonly string $operation,
        /**
         * Payment-method token/reference FIXTURE (v1.4 §6). Opaque to
         * core; for refunds this carries the refund scenario reference
         * instead. Never a PAN, never a CVV.
         */
        public readonly ?string $methodToken,
        /**
         * For refunds: the parent attempt's provider resource reference,
         * so the provider knows WHICH payment is being refunded.
         */
        public readonly ?string $parentProviderReference,
        public readonly string $correlationId,
        /** Environment context ('sandbox' for the whole POC). */
        public readonly string $environment,
    ) {}

    public static function fromCommand(ProviderCommand $command, string $environment, ?string $parentProviderReference = null): self
    {
        $payload = $command->canonical_payload ?? [];

        return new self(
            commandUuid: $command->uuid,
            logicalOperationKey: $command->logicalOperationKey(),
            firmId: (int) $command->firm_id,
            firmIntegrationId: $command->firm_integration_id === null ? null : (int) $command->firm_integration_id,
            integrationProviderId: $command->integration_provider_id === null ? null : (int) $command->integration_provider_id,
            amountCents: (int) ($payload['amount_cents'] ?? 0),
            currency: (string) ($payload['currency'] ?? 'USD'),
            operation: $command->command_type->value,
            methodToken: $payload['method_token'] ?? $payload['reason'] ?? null,
            parentProviderReference: $parentProviderReference,
            correlationId: (string) $command->correlation_id,
            environment: $environment,
        );
    }
}
