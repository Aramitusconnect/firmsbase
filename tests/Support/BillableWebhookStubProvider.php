<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\RequiresBillableCallPipelineContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Enums\ProviderKey;
use Closure;

/**
 * BillableWebhookStubProvider — a test-only provider that satisfies BOTH
 * SupportsWebhooksContract and the RequiresBillableCallPipelineContract
 * marker, so RenewGraphSubscriptionJob takes its
 * ProviderBillableCallPipeline branch (the branch only PlaidProvider
 * reaches in production) WITHOUT any real Plaid credential, real HTTP,
 * or real network access.
 *
 * Every outbound method is a counting closure supplied by the test.
 * Registered by binding this class as a container singleton and pointing
 * `config('integrations.providers')` at it, so ProviderRegistry::get()'s
 * `app()->make($class)` returns the very instance the test is counting.
 */
final class BillableWebhookStubProvider implements IntegrationProviderContract, RequiresBillableCallPipelineContract, SupportsWebhooksContract
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
            'subscription_id' => 'stub-subscription',
            'expires_at' => now()->addHours(70)->toIso8601String(),
        ];

        $this->onSubscribe = static fn (): array => [
            'subscription_id' => 'stub-subscription-new',
            'expires_at' => now()->addHours(70)->toIso8601String(),
        ];
    }

    public function key(): ProviderKey
    {
        return ProviderKey::Plaid;
    }

    public function displayName(): string
    {
        return 'Billable Webhook Stub';
    }

    public function description(): string
    {
        return 'Test-only provider used to exercise the billable-call pipeline from a real job.';
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
