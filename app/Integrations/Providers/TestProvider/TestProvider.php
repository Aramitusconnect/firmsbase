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
use App\Integrations\Exceptions\AuthorizationCodeAlreadyUsedException;
use App\Integrations\Exceptions\ExpiredAuthorizationCodeException;
use App\Integrations\Exceptions\InvalidPkceVerifierException;
use App\Integrations\Exceptions\SimulatedProviderFailureException;
use App\Integrations\Services\InboundWebhookSignatureVerifier;
use App\Integrations\Support\PkceService;
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
 *
 * Checkpoint 5 addition — OAuth authorization-code simulation
 * (checkpoint-00-final-specification.md §18; agent-h-security-architecture-review.md
 * item 11, "APPROVED, with conditions"): $issuedAuthorizationCodes is a
 * PRIVATE STATIC in-process array, a deliberate and narrow exception to
 * this class's own "every method returns purely synthetic, in-memory
 * data" rule — it is the one piece of state that survives across
 * separate TestProvider resolutions within the same process, standing
 * in for what a real provider's own server-side authorization-code
 * store would do. Approved under three conditions, all of which the
 * companion test suite (not this file) is responsible for satisfying:
 * (a) resetSimulationState() must actually be called by every test that
 * exercises reuse detection; (b) an explicit unit test must prove this
 * class cannot be resolved/used when INTEGRATIONS_TEST_PROVIDER_ENABLED
 * is unset or false; (c) this disclosure itself. Keyed on an opaque,
 * non-secret UUID (`jti`-shaped) — never a token or verifier value.
 * Never touches disk/cache/queue infrastructure. No DB/cache-backed
 * replacement is required (the mechanism this stands in for — a real
 * provider's own code store — is itself out of scope for TestProvider
 * by design).
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
     * A magic, non-secret sentinel value: if passed as the
     * authorization `$code` to exchangeCodeForToken(), or as the
     * `$refreshToken` to refreshToken(), simulates a raw outbound-call
     * failure (SimulatedProviderFailureException) instead of a normal
     * response — the only way this checkpoint exercises
     * OutboundProviderHttpClient's sanitization path end to end without
     * a real network call. Never a value random_bytes()/Str::random()
     * could plausibly generate by coincidence.
     */
    public const FAILURE_SENTINEL = '__simulate_provider_failure__';

    /**
     * @var array<string, array{code_challenge: string, external_account_id: string, granted_scopes: string[], used: bool, expires_at: \Illuminate\Support\Carbon}>
     */
    private static array $issuedAuthorizationCodes = [];

    /**
     * TEST-ONLY: clears the static authorization-code replay registry.
     * MUST be called from every test's setUp()/tearDown() that exercises
     * reuse/expiry detection — see class docblock condition (a). Never
     * called from any production code path.
     */
    public static function resetSimulationState(): void
    {
        self::$issuedAuthorizationCodes = [];
    }

    /**
     * Ephemeral, per-instance simulated webhook signing key — generated
     * via a CSPRNG (`random_bytes(32)`, base64url-encoded without
     * padding), byte-for-byte the same construction as
     * IntegrationOAuthStateService::generateRawState() (Checkpoint 7
     * requirement, reviews/checkpoint-07/frozen-design-post-security-review.md
     * §4/§8 — the prior `Str::random(40)` stub is explicitly rejected).
     * Never a hardcoded constant, never persisted anywhere (no
     * credential table backs this simulation surface). Mutable (not
     * `readonly`), specifically so `rotateWebhookSigningKey()` below can
     * simulate a secret-rotation event within one instance's lifetime.
     */
    private string $webhookSigningKey;

    /**
     * Checkpoint 7 addition: the previous signing key, set only by
     * `rotateWebhookSigningKey()`, simulating the frozen design's
     * 2-candidate secret-rotation overlap window (§8) — a signature
     * produced with either the current OR the immediately-prior key
     * verifies successfully, mirroring
     * App\Integrations\Services\WebhookConnectionResolverService::activeAndPreviousWebhookSecretsFor()'s
     * real (Active, most-recent-Rotated) 2-candidate contract.
     */
    private ?string $previousWebhookSigningKey = null;

    public function __construct()
    {
        $this->webhookSigningKey = $this->generateWebhookSigningKey();
    }

    /**
     * TEST-ONLY: simulates a secret-rotation event for this instance —
     * the current signing key becomes the one and only "previous"
     * candidate, and a fresh CSPRNG key becomes current. Mirrors the
     * real credential lifecycle's "at most 2 candidates" bound: calling
     * this twice in a row discards whatever was the FIRST previous key
     * (never accumulates more than one).
     */
    public function rotateWebhookSigningKey(): void
    {
        $this->previousWebhookSigningKey = $this->webhookSigningKey;
        $this->webhookSigningKey = $this->generateWebhookSigningKey();
    }

    private function generateWebhookSigningKey(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
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

    /**
     * TEST-ONLY simulation surface: mints an opaque authorization code
     * bound to the given PKCE code_challenge and simulated
     * account/scope outcome, standing in for "the user completed the
     * provider's hosted consent screen and the provider redirected back
     * with ?code=...". No real provider consent UI exists to automate
     * in this checkpoint (§21) — a test harness (or, in a real deploy,
     * nothing — this method has no legitimate production caller) must
     * call this directly to obtain a `code` value to submit to
     * OAuthConnectionController's callback route, exactly as a real
     * provider would hand one back via the browser redirect.
     *
     * @param  string[]|null $grantedScopes defaults to requiredScopes()
     *                                      when null — pass a narrower
     *                                      array to simulate a
     *                                      missing-scope grant.
     */
    public function simulateAuthorizationGrant(
        string $codeChallenge,
        ?string $externalAccountId = null,
        ?array $grantedScopes = null,
        bool $expired = false,
    ): string {
        $code = (string) Str::uuid();

        self::$issuedAuthorizationCodes[$code] = [
            'code_challenge' => $codeChallenge,
            'external_account_id' => $externalAccountId ?? 'test-external-account-'.Str::random(8),
            'granted_scopes' => $grantedScopes ?? $this->requiredScopes(),
            'used' => false,
            'expires_at' => $expired ? now()->subMinute() : now()->addMinutes(5),
        ];

        return $code;
    }

    public function exchangeCodeForToken(string $code, array $context): array
    {
        if ($code === self::FAILURE_SENTINEL) {
            throw new SimulatedProviderFailureException(
                category: 'provider_rejected',
                statusCode: 502,
                message: 'Simulated provider failure during code exchange.',
            );
        }

        $entry = self::$issuedAuthorizationCodes[$code] ?? null;

        if ($entry === null) {
            throw new ExpiredAuthorizationCodeException();
        }

        if ($entry['used']) {
            throw new AuthorizationCodeAlreadyUsedException();
        }

        if (now()->greaterThan($entry['expires_at'])) {
            throw new ExpiredAuthorizationCodeException();
        }

        $verifier = $context['code_verifier'] ?? '';

        if (! is_string($verifier) || $verifier === '' || ! (new PkceService())->verify($verifier, $entry['code_challenge'])) {
            throw new InvalidPkceVerifierException();
        }

        self::$issuedAuthorizationCodes[$code]['used'] = true;

        return [
            'access_token' => Str::random(40),
            'refresh_token' => Str::random(40),
            'token_type' => 'bearer',
            'expires_in' => 3600,
            'scope' => implode(' ', $entry['granted_scopes']),
            'external_account_id' => $entry['external_account_id'],
        ];
    }

    public function refreshToken(string $refreshToken, array $context = []): array
    {
        if ($refreshToken === self::FAILURE_SENTINEL) {
            throw new SimulatedProviderFailureException(
                category: 'invalid_grant',
                statusCode: 400,
                message: 'Simulated provider failure during token refresh.',
            );
        }

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

    /**
     * Checkpoint 7 rewrite
     * (reviews/checkpoint-07/frozen-design-post-security-review.md §8)
     * — this simulation surface now follows the SAME signature contract
     * as the real inbound webhook pipeline
     * (App\Integrations\Http\Controllers\InboundWebhookController /
     * App\Integrations\Services\InboundWebhookSignatureVerifier), which
     * this method delegates to directly rather than reimplementing:
     * `v1=<hex>` signature header, `X-Test-Provider-Timestamp` header,
     * signing input `"v1" . ":" . <timestamp> . "." . <raw body>`,
     * HMAC-SHA256 only, format-validated hex BEFORE hash_equals(),
     * ±300s replay window, up to 2 candidates (current + previous, per
     * rotateWebhookSigningKey() above).
     *
     * Header lookup is case-insensitive over the generic $headers array
     * this interface's contract already requires
     * (App\Integrations\Contracts\SupportsWebhooksContract stays
     * wire-location-agnostic — this class's own header NAME convention,
     * `X-Test-Provider-Signature`/`X-Test-Provider-Timestamp`, is purely
     * this provider's internal simulation choice, matching the actual
     * header names Checkpoint 7 froze for the real HTTP route). Because
     * $headers here is a plain associative array (never real, possibly-
     * repeated HTTP header lines), there is no artifact to represent a
     * literal "duplicate header" with — that specific rejection rule is
     * enforced where it structurally applies, in
     * InboundWebhookController's own real-Request header extraction.
     */
    public function verifyInboundSignature(string $rawBody, array $headers): bool
    {
        $signatureRaw = $this->findHeaderCaseInsensitive($headers, 'X-Test-Provider-Signature');
        $timestampRaw = $this->findHeaderCaseInsensitive($headers, 'X-Test-Provider-Timestamp');

        $candidates = array_values(array_filter(
            [$this->webhookSigningKey, $this->previousWebhookSigningKey],
            static fn (?string $key): bool => $key !== null,
        ));

        return (new InboundWebhookSignatureVerifier())->verify($candidates, $rawBody, $timestampRaw, $signatureRaw);
    }

    /**
     * Checkpoint 7 rewrite (frozen design §8): the `Str::uuid()`
     * fallback for a missing/empty/non-string `event_id` is REMOVED —
     * it would defeat idempotency by minting a fresh, unrelated id on
     * every retried delivery of the SAME logical event. A malformed
     * `event_id` now surfaces as `event_id => null` in the returned
     * array — a distinct, caller-visible malformed-payload signal —
     * never a randomly-generated substitute value.
     */
    public function parseInboundEvent(string $rawBody, array $headers): array
    {
        $decoded = json_decode($rawBody, true);

        if (! is_array($decoded)) {
            $decoded = [];
        }

        $eventId = $decoded['event_id'] ?? null;

        if (! is_string($eventId) || trim($eventId) === '') {
            $eventId = null;
        }

        return [
            'event_id' => $eventId,
            'event_type' => $decoded['event_type'] ?? $this->webhookEventTypes()[0],
            'payload' => $decoded['payload'] ?? [],
        ];
    }

    /**
     * Case-insensitive key lookup over a generic associative array —
     * this class's own substitute for a real HTTP header bag's
     * case-insensitivity, since SupportsWebhooksContract's $headers
     * parameter is deliberately just a plain array (see that
     * interface's own docblock).
     */
    private function findHeaderCaseInsensitive(array $headers, string $name): ?string
    {
        $target = strtolower($name);

        foreach ($headers as $key => $value) {
            if (is_string($key) && strtolower($key) === $target) {
                return is_string($value) ? $value : null;
            }
        }

        return null;
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
