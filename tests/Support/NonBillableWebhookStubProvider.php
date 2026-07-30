<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Enums\ProviderKey;
use Closure;

/**
 * NonBillableWebhookStubProvider — the sibling of
 * BillableWebhookStubProvider that deliberately does NOT implement
 * `RequiresBillableCallPipelineContract`.
 *
 * That single difference is the point: it makes
 * `RenewGraphSubscriptionJob` take its DIRECT provider-call branch — the
 * one every real Microsoft 365 and Google Workspace webhook renewal takes,
 * and the one that never went through `ProviderBillableCallPipeline` and
 * therefore had no at-most-once protection at all before Checkpoint 8.2
 * §A7 applied the durable gate to it directly.
 *
 * Keyed as Microsoft365 so it stands in for the real Graph renewal path.
 * Every outbound method is a counting closure supplied by the test; no
 * real credential, no real HTTP.
 */
final class NonBillableWebhookStubProvider implements IntegrationProviderContract, SupportsWebhooksContract
{
    public int $renewCalls = 0;

    public int $subscribeCalls = 0;

    /** @var Closure(array<string, mixed>): array<string, mixed> */
    public Closure $onRenew;

    /** @var Closure(array<string, mixed>): array<string, mixed> */
    public Closure $onSubscribe;

    public function __construct()
    {
        $this->onRenew = static fn (): array => [
            'subscription_id' => 'graph-subscription-renewed',
            'expires_at' => now()->addHours(70)->toIso8601String(),
        ];

        $this->onSubscribe = static fn (): array => [
            'subscription_id' => 'graph-subscription-new',
            'expires_at' => now()->addHours(70)->toIso8601String(),
        ];
    }

    public function key(): ProviderKey
    {
        return ProviderKey::Microsoft365;
    }

    public function displayName(): string
    {
        return 'Non-Billable Webhook Stub';
    }

    public function description(): string
    {
        return 'Test-only provider standing in for the direct (non-pipeline) Graph/Google renewal path.';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportedAuthMethods(): array
    {
        return [];
    }

    public function webhookEventTypes(): array
    {
        return ['stub.event'];
    }

    public function verifyInboundSignature(string $rawBody, array $headers): bool
    {
        return true;
    }

    public function parseInboundEvent(string $rawBody, array $headers): array
    {
        return ['event_id' => null, 'event_type' => null, 'payload' => []];
    }

    public function subscribe(array $context): array
    {
        $this->subscribeCalls++;

        return ($this->onSubscribe)($context);
    }

    public function renewSubscription(array $context): array
    {
        $this->renewCalls++;

        return ($this->onRenew)($context);
    }

    public function detectSubscriptionValidationChallenge(array $queryParams, array $headers): ?array
    {
        return null;
    }

    public function extractRoutingIdentifier(string $rawBody, array $headers): ?string
    {
        return null;
    }
}
