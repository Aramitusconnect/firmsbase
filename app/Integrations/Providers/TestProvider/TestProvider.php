<?php

declare(strict_types=1);

namespace App\Integrations\Providers\TestProvider;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsApiKeyContract;
use App\Integrations\Contracts\SupportsDisconnectContract;
use App\Integrations\Contracts\SupportsHealthCheckContract;
use App\Integrations\Contracts\SupportsIncrementalSyncContract;
use App\Integrations\Contracts\SupportsOAuthContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\HealthStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use Illuminate\Support\Str;

/**
 * TestProvider — the ONLY concrete provider implementation in this
 * entire mission (checkpoint-00-final-specification.md §18/§21). An
 * internal, non-production, proof-of-capability adapter: it exists so
 * every framework capability (registration, capability discovery,
 * simulated OAuth, API-key validation, webhook verification, push/pull
 * sync, incremental cursors, health checks, disconnect) can be
 * exercised end-to-end with zero external network calls and zero real
 * credentials, before any live provider is ever built.
 *
 * Hard restrictions enforced by this class:
 * - Makes ZERO real HTTP/network calls anywhere in any method. Every
 *   method returns purely synthetic, in-memory data.
 * - Every secret/credential/token-shaped string this class returns or
 *   generates (simulated access/refresh tokens, webhook signing key,
 *   subscription ids) is generated at runtime via Str::random()
 *   (itself backed by random_bytes) — never a hardcoded string literal
 *   that looks like a plausible secret. See exchangeCodeForToken(),
 *   refreshToken(), and the $webhookSigningKey property.
 * - isConfigured() reflects the same environment gate that
 *   config('integrations.providers') already uses to decide whether to
 *   register this class at all (INTEGRATIONS_TEST_PROVIDER_ENABLED,
 *   default false) — re-checked independently here as defense in
 *   depth, never assumed true.
 * - Never appears as a purchasable/customer-facing provider card in
 *   production; displayName()/description() self-identify clearly as
 *   internal/non-production so no later UI checkpoint could mistake it
 *   for a real provider.
 *
 * Webhook simulation note (checkpoint-00-final-specification.md §11):
 * verifyInboundSignature()/parseInboundEvent() below read a
 * `'signature'` key out of the generic $headers array purely as
 * TestProvider's OWN internal simulated-header convention for its
 * proof-of-capability tests — this is not, and must not be read as,
 * the framework's actual wire-location decision for a routing/identity
 * token, which remains explicitly deferred to Checkpoint 7 per §11.
 * SupportsWebhooksContract itself stays wire-location-agnostic.
 */
final class TestProvider implements
    IntegrationProviderContract,
    SupportsOAuthContract,
    SupportsApiKeyContract,
    SupportsWebhooksContract,
    SupportsHealthCheckContract,
    SupportsPullSyncContract,
    SupportsPushSyncContract,
    SupportsIncrementalSyncContract,
    SupportsDisconnectContract
{
    /**
     * Ephemeral, per-instance simulated webhook signing key — generated
     * fresh via Str::random() (random_bytes-backed), never a hardcoded
     * constant. Exists only so this single instance's
     * verifyInboundSignature()/subscribe() calls are internally
     * consistent for the lifetime of one resolution; it is not
     * persisted anywhere (no credential table exists at Checkpoint 1).
     */
    private readonly string $webhookSigningKey;

    public function __construct()
    {
        $this->webhookSigningKey = Str::random(40);
    }

    public function key(): ProviderKey
    {
        return ProviderKey::Test;
    }

    public function displayName(): string
    {
        return 'Internal Test Provider (non-production)';
    }

    public function description(): string
    {
        return 'Internal, non-production proof-of-capability adapter used to exercise the '
            .'integration framework end-to-end with zero external network calls. Never a real '
            .'or purchasable provider — must never be shown to a firm outside an approved '
            .'internal testing context.';
    }

    public function isConfigured(): bool
    {
        return $this->isEnabledByEnvironment();
    }

    /**
     * @return AuthMethod[]
     */
    public function supportedAuthMethods(): array
    {
        return [AuthMethod::OAuth2, AuthMethod::ApiKey];
    }

    // ---------------------------------------------------------------
    // SupportsOAuthContract
    // ---------------------------------------------------------------

    public function authorizationUrl(array $params): string
    {
        // Pure string construction only — no network call. The
        // ".invalid" TLD (RFC 2606) is used deliberately so this can
        // never resolve to a real host even if something downstream
        // mistakenly tried to dereference it.
        return 'https://internal-test-provider.invalid/oauth/authorize?'.http_build_query($params);
    }

    public function exchangeCodeForToken(string $code, array $context): array
    {
        return [
            'access_token' => Str::random(40),
            'refresh_token' => Str::random(40),
            'token_type' => 'bearer',
            'expires_in' => 3600,
            'scope' => implode(' ', $this->requiredScopes()),
        ];
    }

    public function refreshToken(string $refreshToken): array
    {
        return [
            'access_token' => Str::random(40),
            'refresh_token' => Str::random(40),
            'token_type' => 'bearer',
            'expires_in' => 3600,
            'scope' => implode(' ', $this->requiredScopes()),
        ];
    }

    /**
     * @return string[]
     */
    public function requiredScopes(): array
    {
        return ['test.read', 'test.write'];
    }

    // ---------------------------------------------------------------
    // SupportsApiKeyContract
    // ---------------------------------------------------------------

    /**
     * @return string[]
     */
    public function requiredCredentialFields(): array
    {
        return ['api_key'];
    }

    public function validateCredentials(array $credentials): bool
    {
        // Structural/format check only, per contract — never a real
        // network call to verify against a provider.
        return isset($credentials['api_key'])
            && is_string($credentials['api_key'])
            && strlen($credentials['api_key']) >= 16;
    }

    // ---------------------------------------------------------------
    // SupportsWebhooksContract
    // ---------------------------------------------------------------

    /**
     * @return string[]
     */
    public function webhookEventTypes(): array
    {
        return [
            'test.resource.created',
            'test.resource.updated',
            'test.resource.deleted',
        ];
    }

    public function verifyInboundSignature(string $rawBody, array $headers): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $this->webhookSigningKey);
        $provided = $headers['signature'] ?? '';

        return is_string($provided) && hash_equals($expected, $provided);
    }

    public function parseInboundEvent(string $rawBody, array $headers): array
    {
        $decoded = json_decode($rawBody, true);

        if (! is_array($decoded)) {
            $decoded = [];
        }

        return [
            'event_id' => $decoded['event_id'] ?? (string) Str::uuid(),
            'event_type' => $decoded['event_type'] ?? $this->webhookEventTypes()[0],
            'payload' => $decoded['payload'] ?? [],
        ];
    }

    public function subscribe(array $context): array
    {
        return [
            'subscription_id' => (string) Str::uuid(),
            'status' => 'active',
            'event_types' => $this->webhookEventTypes(),
        ];
    }

    public function renewSubscription(array $context): array
    {
        return [
            'subscription_id' => (string) Str::uuid(),
            'status' => 'active',
            'event_types' => $this->webhookEventTypes(),
            'renewed_at' => now()->toIso8601String(),
        ];
    }

    // ---------------------------------------------------------------
    // SupportsHealthCheckContract
    // ---------------------------------------------------------------

    public function healthCheckEndpointConvention(): string
    {
        // Documentation-only — never executable, never passed to any
        // HTTP client.
        return 'Simulated only: no real endpoint exists. A live provider '
            .'would typically GET a lightweight identity/ping endpoint and '
            .'treat a 2xx response as healthy.';
    }

    public function checkHealth(array $context): array
    {
        return [
            'status' => HealthStatus::Healthy->value,
            'checked_at' => now()->toIso8601String(),
            'detail' => 'Simulated health check — no real network call was made.',
        ];
    }

    // ---------------------------------------------------------------
    // SupportsPullSyncContract
    // ---------------------------------------------------------------

    /**
     * @return string[]
     */
    public function pullableResourceTypes(): array
    {
        return [ResourceType::Contact->value, ResourceType::Task->value];
    }

    public function pull(array $context, string $resourceType, ?string $cursor): array
    {
        return [
            'items' => [
                ['external_id' => (string) Str::uuid(), 'resource_type' => $resourceType],
                ['external_id' => (string) Str::uuid(), 'resource_type' => $resourceType],
            ],
            'next_cursor' => null,
        ];
    }

    // ---------------------------------------------------------------
    // SupportsPushSyncContract
    // ---------------------------------------------------------------

    /**
     * @return string[]
     */
    public function pushableResourceTypes(): array
    {
        return [ResourceType::Contact->value, ResourceType::Task->value];
    }

    public function push(array $context, string $resourceType, array $payload): array
    {
        return [
            'external_id' => (string) Str::uuid(),
            'status' => 'synced',
        ];
    }

    // ---------------------------------------------------------------
    // SupportsIncrementalSyncContract
    // ---------------------------------------------------------------

    public function supportsIncrementalFor(string $resourceType): bool
    {
        return in_array($resourceType, $this->pullableResourceTypes(), true);
    }

    public function incrementalCursorFor(array $context, string $resourceType): ?string
    {
        if (! $this->supportsIncrementalFor($resourceType)) {
            return null;
        }

        return Str::random(24);
    }

    // ---------------------------------------------------------------
    // SupportsDisconnectContract
    // ---------------------------------------------------------------

    public function revokeAtProvider(array $context): bool
    {
        // Simulated success — no real network call. Local teardown
        // (crypto-shredding stored credentials, emitting the audit
        // event) is generic core logic that belongs to a future
        // checkpoint's connection service, not here.
        return true;
    }

    /**
     * Independent, defense-in-depth re-check of the same environment
     * gate config('integrations.providers') already uses to decide
     * whether to register this class at all
     * (checkpoint-00-final-specification.md §18/§21) — deliberately
     * does not rely solely on the registry map having filtered this
     * class out correctly elsewhere. Reads the environment variable
     * directly (rather than via config()) so this check remains valid
     * even if config('integrations.providers') were ever consulted
     * from a code path that bypassed the framework-level cache;
     * reviewer note: this intentionally revisits Laravel's usual
     * "never call env() outside a config file" guidance, as an
     * explicit second, independent gate rather than the sole source of
     * truth.
     */
    private function isEnabledByEnvironment(): bool
    {
        return filter_var(env('INTEGRATIONS_TEST_PROVIDER_ENABLED', false), FILTER_VALIDATE_BOOLEAN);
    }
}
