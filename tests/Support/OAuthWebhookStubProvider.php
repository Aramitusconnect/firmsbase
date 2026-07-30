<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsOAuthContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Providers\TestProvider\TestProvider;
use Closure;

/**
 * OAuthWebhookStubProvider — Checkpoint 8.2 §A7b test support.
 *
 * Delegates every OAuth mechanic to the real `TestProvider` (so the
 * authorization-code/PKCE/account-pinning flow under test is the genuine
 * one, not a reimplementation) while letting a test control `subscribe()`
 * — the one call the staged webhook bootstrap makes, and therefore the one
 * that has to be able to fail on demand.
 *
 * Registered by binding this class as a container instance and pointing
 * `config('integrations.providers')` at it, so `ProviderRegistry::get()`
 * returns the very instance the test is holding.
 */
final class OAuthWebhookStubProvider implements IntegrationProviderContract, SupportsOAuthContract, SupportsPullSyncContract, SupportsWebhooksContract
{
    public int $subscribeCalls = 0;

    /** @var Closure(array<string, mixed>): array<string, mixed> */
    public Closure $onSubscribe;

    private TestProvider $delegate;

    public function __construct()
    {
        $this->delegate = new TestProvider;

        $this->onSubscribe = static fn (): array => [
            'subscription_id' => 'stub-webhook-subscription',
            'status' => 'active',
            'expires_at' => now()->addDays(3)->toIso8601String(),
        ];
    }

    /** Exposes the delegate so a test can mint a real authorization code. */
    public function delegate(): TestProvider
    {
        return $this->delegate;
    }

    // ---- IntegrationProviderContract -------------------------------

    public function key(): ProviderKey
    {
        return ProviderKey::Test;
    }

    public function displayName(): string
    {
        return 'OAuth + Webhook Stub';
    }

    public function description(): string
    {
        return 'Test-only provider: real TestProvider OAuth mechanics, test-controlled subscribe().';
    }

    public function isConfigured(): bool
    {
        return $this->delegate->isConfigured();
    }

    public function supportedAuthMethods(): array
    {
        return $this->delegate->supportedAuthMethods();
    }

    // ---- SupportsOAuthContract (delegated verbatim) -----------------

    public function authorizationUrl(array $params): string
    {
        return $this->delegate->authorizationUrl($params);
    }

    public function exchangeCodeForToken(string $code, array $context): array
    {
        return $this->delegate->exchangeCodeForToken($code, $context);
    }

    public function refreshToken(string $refreshToken, array $context = []): array
    {
        return $this->delegate->refreshToken($refreshToken, $context);
    }

    public function requiredScopes(array $context = []): array
    {
        return $this->delegate->requiredScopes($context);
    }

    public function capabilityScopeMap(): array
    {
        return $this->delegate->capabilityScopeMap();
    }

    // ---- SupportsPullSyncContract -----------------------------------

    public function pullableResourceTypes(): array
    {
        return $this->delegate->pullableResourceTypes();
    }

    public function pull(array $context, string $resourceType, ?string $cursor): array
    {
        return $this->delegate->pull($context, $resourceType, $cursor);
    }

    // ---- SupportsWebhooksContract -----------------------------------

    public function webhookEventTypes(): array
    {
        return $this->delegate->webhookEventTypes();
    }

    public function verifyInboundSignature(string $rawBody, array $headers): bool
    {
        return $this->delegate->verifyInboundSignature($rawBody, $headers);
    }

    public function parseInboundEvent(string $rawBody, array $headers): array
    {
        return $this->delegate->parseInboundEvent($rawBody, $headers);
    }

    /** The one method under this suite's control. */
    public function subscribe(array $context): array
    {
        $this->subscribeCalls++;

        return ($this->onSubscribe)($context);
    }

    public function renewSubscription(array $context): array
    {
        return $this->delegate->renewSubscription($context);
    }

    public function detectSubscriptionValidationChallenge(array $queryParams, array $headers): ?array
    {
        return $this->delegate->detectSubscriptionValidationChallenge($queryParams, $headers);
    }

    public function extractRoutingIdentifier(string $rawBody, array $headers): ?string
    {
        return $this->delegate->extractRoutingIdentifier($rawBody, $headers);
    }
}
