<?php

declare(strict_types=1);

namespace App\Integrations\Providers\Microsoft365;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsIncrementalSyncContract;
use App\Integrations\Contracts\SupportsOAuthContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Contracts\SupportsPushSyncContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\SyncCursorService;
use App\Integrations\Support\ProviderEnvironmentResolver;
use App\Integrations\Support\ProviderRequestExecutor;
use App\Services\TenantContextService;
use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use RuntimeException;

/**
 * Microsoft365Provider — FirmsVault Live Integrations, Checkpoint 2.
 * The first REAL (non-simulated) provider adapter in this codebase.
 * Implements IntegrationProviderContract, SupportsOAuthContract,
 * SupportsPullSyncContract, SupportsPushSyncContract,
 * SupportsIncrementalSyncContract, SupportsWebhooksContract, per
 * checkpoint2-combined-design.md §3 (the frozen, security-reviewed
 * design) and checkpoint2-security-review.md's three required
 * corrections (Findings 2, 4, 5 — Finding 2 and Finding 10 bind this
 * class directly; Findings 4/5 bind framework files this class depends
 * on, already landed by phase-1 of this checkpoint).
 *
 * Deliberately does NOT implement SupportsApiKeyContract (Microsoft 365
 * is OAuth-only for the delegated-permission model this checkpoint
 * targets) or SupportsDisconnectContract — an honestly-researched
 * non-implementation, not an oversight: Microsoft Graph has no
 * self-service "revoke my own app's grant" endpoint for a delegated
 * OAuth2 app (the nearest-sounding capability,
 * `POST /users/{id}/revokeSignInSessions`, requires admin-level Graph
 * permissions this app registration deliberately does not hold, and
 * revokes every session for every app the user has ever signed into —
 * a wildly disproportionate side effect for "disconnect this one
 * integration"). Local teardown via
 * `ProviderConnectionService::disconnect()`'s existing, fully generic
 * machinery (credential crypto-erasure, webhook-routing-token
 * clearing, status transition, audit trail) is completely unaffected by
 * this — its remote-revoke step is documented as best-effort,
 * only-if-implemented. The disconnect-confirmation UI should disclose
 * that Microsoft's own record of the grant persists until the user or
 * their tenant admin separately removes it via
 * myaccount.microsoft.com or the Entra admin center — a UX/UI concern
 * for a parallel agent's work, not something this class's code can
 * close.
 *
 * DISCOVERED, DISCLOSED GAP (not fixed here — outside this file's
 * scope, see this checkpoint's final report): `App\Jobs\PullSyncJob`
 * and `App\Jobs\PushSyncJob` both currently pass ONLY their own
 * test-harness-only `$providerContext` (defaults to `[]`, never
 * containing a `connection`/`firm_integration_id` key) as `pull()`'s/
 * `push()'s `$context` argument — neither job threads the
 * `FirmIntegration` model (or even its bare id) it already holds in
 * scope into that argument. Since `ProviderRequestExecutor::send()`
 * requires a real `FirmIntegration $connection` object, NO real
 * provider's `pull()`/`push()` can be driven end-to-end through
 * today's actual `PullSyncJob`/`PushSyncJob` wiring without a small
 * follow-up fix to those two jobs (threading `'connection' =>
 * $connection` into the array passed to `pull()`/`push()`). This class
 * implements `resolveConnectionFromContext()` below to accept EITHER a
 * `'connection'` key OR a `'firm_integration_id'` key (mirroring the
 * exact convention `SupportsIncrementalSyncContract::incrementalCursorFor()`
 * already documents) so it is ready the moment that follow-up lands,
 * and fails loudly/typed (never silently) if neither is present, rather
 * than guessing or duplicating framework-level job logic in this
 * provider-specific file.
 */
final class Microsoft365Provider implements IntegrationProviderContract, SupportsIncrementalSyncContract, SupportsOAuthContract, SupportsPullSyncContract, SupportsPushSyncContract, SupportsWebhooksContract
{
    /**
     * The changeType vocabulary Graph subscriptions default to when a
     * caller does not explicitly override it (subscribe()'s
     * $context['provider_change_type']) — comma-joined, per Graph's own
     * documented `changeType` field shape (e.g. "created,updated,deleted").
     */
    private const DEFAULT_CHANGE_TYPES = 'created,updated,deleted';

    /**
     * CONSERVATIVE PLACEHOLDER — Microsoft's real, documented maximum
     * subscription lifetime varies materially by resource type (Outlook
     * mail/calendar/contact resources typically cap in the low
     * thousands of minutes; the exact current ceiling per resource was
     * not deep-fetched as part of this checkpoint's research pass, per
     * checkpoint2-design-sync-webhooks.md §3.3's own explicit flag).
     * 4230 minutes (~70.5 hours, just under 3 days) is the commonly
     * documented ceiling for Outlook resource subscriptions at the time
     * of writing — used here as an honest, disclosed starting value,
     * NOT a value this class treats as authoritative. MUST be revisited
     * against Microsoft's actual current per-resource documentation
     * before this provider is enabled against real production traffic
     * (mirrors config/integrations.php's own disclosed
     * rate_limits.providers.microsoft365 placeholder precedent).
     * RenewProviderWebhookSubscriptionsCommand's renewal safety margin
     * is computed from each subscription's own actual `expires_at` —
     * NOT from this constant — so a wrong value here affects only how
     * long a single subscription lasts before its first renewal, never
     * whether renewal happens at all.
     */
    private const SUBSCRIPTION_LIFETIME_MINUTES = 4230;

    /**
     * Payload keys App\Jobs\PushSyncJob's own payload-building code
     * always includes that are structural/framework bookkeeping, never
     * real Graph-shaped resource field data — stripped out of push()'s
     * outbound body before anything else is treated as real field
     * content. See push()'s own docblock for why the remainder of the
     * payload (today: nothing, since no resource-specific field-mapping
     * layer exists yet) is passed through verbatim rather than mapped.
     */
    private const PUSH_STRUCTURAL_PAYLOAD_KEYS = [
        'local_type', 'local_id', 'idempotency_key', 'existing_external_id', '__simulate_failure',
    ];

    public function __construct(
        private readonly ProviderRequestExecutor $executor,
        private readonly IntegrationCredentialService $credentials,
        private readonly SyncCursorService $cursors,
    ) {}

    // ---------------------------------------------------------------
    // IntegrationProviderContract
    // ---------------------------------------------------------------

    public function key(): ProviderKey
    {
        return ProviderKey::Microsoft365;
    }

    public function displayName(): string
    {
        return 'Microsoft 365';
    }

    public function description(): string
    {
        return 'Connect Outlook email and calendar, OneDrive/SharePoint files, and contacts via Microsoft Graph.';
    }

    public function isConfigured(): bool
    {
        $clientId = config('integrations.oauth_apps.microsoft365.client_id');
        $clientSecret = config('integrations.oauth_apps.microsoft365.client_secret');

        return is_string($clientId) && trim($clientId) !== ''
            && is_string($clientSecret) && trim($clientSecret) !== '';
    }

    /**
     * @return AuthMethod[]
     */
    public function supportedAuthMethods(): array
    {
        return [AuthMethod::OAuth2];
    }

    // ---------------------------------------------------------------
    // SupportsOAuthContract
    // ---------------------------------------------------------------

    /**
     * Pure string construction, no network call — self-supplies
     * client_id from platform config (NEVER `$params['client_id']`,
     * which no longer exists in the params array
     * ProviderConnectionService::initiateOAuthConnection() builds —
     * that key previously carried FirmsBase's own internal
     * integration_providers.id primary key, not a real OAuth client
     * identifier; confirmed removed with no regression by
     * checkpoint2-security-review.md Finding 7).
     *
     * Tenant-domain hint (security review Finding 10, recommended
     * hardening, implemented here): `$params['ms_tenant_hint']` is
     * format-validated (DNS-domain-like or a bare GUID) before being
     * interpolated into the URL path. A genuinely absent hint (null or
     * empty string) falls back to `/organizations/...` silently. A
     * PRESENT but malformed hint is rejected outright (InvalidArgumentException)
     * rather than silently substituted with `/organizations/...` —
     * per the task's explicit requirement, a user who typed something
     * deserves an error, not a silent redirect to the wrong tenant
     * segment.
     */
    public function authorizationUrl(array $params): string
    {
        $clientId = (string) config('integrations.oauth_apps.microsoft365.client_id');
        $tenantSegment = $this->resolveTenantSegment($params['ms_tenant_hint'] ?? null);

        $query = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => (string) ($params['redirect_uri'] ?? ''),
            'response_mode' => 'query',
            'scope' => (string) ($params['scope'] ?? ''),
            'state' => (string) ($params['state'] ?? ''),
            'code_challenge' => (string) ($params['code_challenge'] ?? ''),
            'code_challenge_method' => (string) ($params['code_challenge_method'] ?? 'S256'),
        ]);

        $identityBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::Microsoft365, 'identity');

        return "{$identityBase}/{$tenantSegment}/oauth2/v2.0/authorize?{$query}";
    }

    /**
     * Requires $context['connection'] to be a FirmIntegration instance
     * — guaranteed present by ProviderConnectionService::finishCallback()
     * (checkpoint2-combined-design.md §2 P-6c, already landed by
     * phase-1). Builds the token-exchange request body and calls
     * ProviderRequestExecutor::send() with formEncoded: true (Microsoft's
     * token endpoint requires application/x-www-form-urlencoded per RFC
     * 6749 §4.1.3 — a JSON body would be rejected outright),
     * operationType: 'token_exchange', urlPurpose: 'identity', and a
     * no-op auth injector (the token endpoint authenticates via body
     * fields, not a bearer header).
     *
     * REQUIRED (security review Finding 2, P1): the decoded ID token's
     * `aud` and `iss` claims are validated BEFORE `tid`/`oid` are
     * trusted for anything — `aud` must equal this platform's configured
     * client_id exactly; `iss` must match Microsoft's expected issuer
     * template for the token's own `tid`
     * (`https://login.microsoftonline.com/{tid}/v2.0`). Either check
     * failing is treated as a token-exchange failure (a thrown
     * SanitizedProviderHttpException), never a soft pass with null
     * claims — the unverified `tid` claim is not merely informational,
     * it is the entire enforcement mechanism for
     * ProviderConnectionService::finishCallback()'s tenant-mismatch
     * defense (external_tenant_id capture/compare).
     *
     * Full JWS signature verification against Microsoft's published
     * JWKS is deliberately NOT performed — no JWT/JWK library exists in
     * this codebase (confirmed absent from composer.json), and per the
     * frozen design this is a defensible, disclosed stretch goal, not a
     * blocker. This "no signature verification needed" reasoning
     * applies ONLY to an ID token obtained via a direct, first-party,
     * TLS-authenticated call to a host validated by
     * ProviderEnvironmentResolver (exactly what this method does) —
     * NEVER to be generalized to an ID token obtained any other way
     * (e.g. a front-channel-relayed token, or a future provider's
     * admin-consent variant).
     */
    public function exchangeCodeForToken(string $code, array $context): array
    {
        $connection = $context['connection'] ?? null;

        if (! $connection instanceof FirmIntegration) {
            throw new InvalidArgumentException(
                'Microsoft365Provider::exchangeCodeForToken() requires $context[\'connection\'] to be a FirmIntegration instance.'
            );
        }

        $clientId = (string) config('integrations.oauth_apps.microsoft365.client_id');
        $clientSecret = (string) config('integrations.oauth_apps.microsoft365.client_secret');
        $tenantSegment = $this->resolveTenantSegment($context['ms_tenant_hint'] ?? null);

        $body = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) ($context['redirect_uri'] ?? ''),
            'code_verifier' => (string) ($context['code_verifier'] ?? ''),
        ];

        $identityBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::Microsoft365, 'identity');

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Microsoft365,
            method: 'POST',
            url: "{$identityBase}/{$tenantSegment}/oauth2/v2.0/token",
            capability: 'oauth_connect',
            operationType: 'token_exchange',
            direction: SyncDirection::Outbound,
            resourceType: null,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'oauth_code_exchange:'.$connection->id.':'.hash('sha256', $code),
            body: $body,
            formEncoded: true,
            urlPurpose: 'identity',
        );

        $claims = $this->decodeAndValidateIdToken($response->json['id_token'] ?? null, $clientId);

        return [
            'access_token' => $response->json['access_token'] ?? null,
            'refresh_token' => $response->json['refresh_token'] ?? null,
            'token_type' => $response->json['token_type'] ?? 'bearer',
            'expires_in' => $response->json['expires_in'] ?? null,
            'scope' => $response->json['scope'] ?? '',
            'external_account_id' => $claims['oid'] ?? null,
            'tenant_id' => $claims['tid'] ?? null,
        ];
    }

    /**
     * Same shape as exchangeCodeForToken()'s HTTP call, grant_type=refresh_token,
     * operationType: 'refresh_token'. Re-asserts
     * implode(' ', $this->requiredScopes($context)) as the requested
     * scope — Graph allows requesting equal-or-narrower on refresh.
     * Always returns a 'refresh_token' key whenever Graph's response
     * includes one; per Graph's documented MANDATORY refresh-token
     * rotation this is every successful refresh, never optional the way
     * it is for TestProvider.
     *
     * The tenant path segment is deliberately always `/organizations/...`
     * here, never the original authorize-time hint — Graph's own
     * documented behavior does not require the refresh call's tenant
     * segment to match the original authorize-time value.
     *
     * DEFENSIVE HANDLING for a real, discovered gap: ProviderConnectionService::refreshConnectionToken()'s
     * actual, current call site (already landed by phase-1) threads
     * $context = ['firm_integration_id' => ..., 'connection' => ...]
     * — it does NOT include a 'requested_capabilities' key, unlike
     * finishCallback()'s equivalent call to requiredScopes(). Calling
     * $this->requiredScopes($context) directly with that context would
     * therefore throw on every real refresh (empty capability list).
     * This method instead derives 'requested_capabilities' from
     * $connection->requested_capabilities_json when the caller-supplied
     * $context does not already carry it, so a real refresh succeeds
     * without requiring a change to ProviderConnectionService.
     */
    public function refreshToken(string $refreshToken, array $context = []): array
    {
        $connection = $this->resolveConnectionFromContext($context);

        // DETERMINISTIC REFRESH-CYCLE IDENTITY (see
        // oauthRefreshCycleToken()'s own docblock). Computed BEFORE the
        // call, from state the refresh itself is about to replace.
        $refreshCycleToken = $this->oauthRefreshCycleToken($connection, $refreshToken);

        $clientId = (string) config('integrations.oauth_apps.microsoft365.client_id');
        $clientSecret = (string) config('integrations.oauth_apps.microsoft365.client_secret');

        $scopeContext = $context;
        if (! isset($scopeContext['requested_capabilities'])) {
            $scopeContext['requested_capabilities'] = $connection->requested_capabilities_json ?? [];
        }

        $body = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', $this->requiredScopes($scopeContext)),
        ];

        $identityBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::Microsoft365, 'identity');

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Microsoft365,
            method: 'POST',
            url: "{$identityBase}/organizations/oauth2/v2.0/token",
            capability: 'oauth_refresh',
            operationType: 'refresh_token',
            direction: SyncDirection::Outbound,
            resourceType: null,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'oauth_refresh:'.$connection->id.':'.$refreshCycleToken,
            body: $body,
            formEncoded: true,
            urlPurpose: 'identity',
        );

        return [
            'access_token' => $response->json['access_token'] ?? null,
            'refresh_token' => $response->json['refresh_token'] ?? null,
            'token_type' => $response->json['token_type'] ?? 'bearer',
            'expires_in' => $response->json['expires_in'] ?? null,
            'scope' => $response->json['scope'] ?? '',
        ];
    }

    /**
     * Reads $context['requested_capabilities'] ?? []. THROWS on an
     * empty list — deliberately never a silent fallback to a broad
     * scope bundle (the mission's explicit "least-privilege... never
     * broad-scope-by-default" requirement). Capability vocabulary IS
     * ResourceType (Contact/CalendarEvent/Message/Document), per
     * checkpoint2-combined-design.md §1.1's reconciliation decision —
     * not a separate string taxonomy. Always includes the baseline
     * offline_access/openid/profile scopes (required to receive a
     * refresh token and an ID token at all). De-dups superset pairs:
     * Calendars.ReadWrite/Files.ReadWrite already imply their
     * corresponding *.Read scope, so a bundle that happens to also
     * carry the narrower *.Read value has it dropped.
     *
     * DISCLOSED, NOT FIXED HERE: `App\Integrations\Data\ProviderMetadata::fromProvider()`
     * calls `$provider->requiredScopes()` with ZERO arguments for every
     * SupportsOAuthContract provider (e.g. when building the connect-flow
     * provider list). Because this method throws on an empty capability
     * list, that zero-arg call will throw for THIS provider specifically
     * — a real, disclosed tension between this checkpoint's frozen,
     * security-reviewed requirement ("throws on empty, never guesses")
     * and ProviderMetadata's own existing zero-arg call site, which this
     * file has no mandate or scope to modify. Flagged prominently in
     * this checkpoint's final report as a genuine follow-up item for
     * whoever owns ProviderMetadata/the connect-flow UI next.
     *
     * @param  array<string, mixed>  $context
     * @return string[]
     */
    public function requiredScopes(array $context = []): array
    {
        $capabilities = $context['requested_capabilities'] ?? [];

        if (! is_array($capabilities) || $capabilities === []) {
            throw new InvalidArgumentException(
                'Microsoft365Provider::requiredScopes() requires a non-empty requested_capabilities context — '.
                'refusing to request a broad, unscoped default.'
            );
        }

        $bundles = self::capabilityScopeBundles();
        $scopes = self::baselineScopes();

        foreach ($capabilities as $capability) {
            if (! is_string($capability)) {
                continue;
            }

            $scopes = array_merge($scopes, $bundles[$capability] ?? []);
        }

        if (in_array('Calendars.ReadWrite', $scopes, true)) {
            $scopes = array_diff($scopes, ['Calendars.Read']);
        }

        if (in_array('Files.ReadWrite', $scopes, true)) {
            $scopes = array_diff($scopes, ['Files.Read']);
        }

        return array_values(array_unique($scopes));
    }

    /**
     * Capability key (a ResourceType value) => its raw scope array, for
     * the UI's pre-connect disclosure screen ("Email access requires:
     * Mail.Read, Mail.Send"). Documentation/UI-only, same status as
     * requiredScopes() itself — never authoritative for what was
     * actually granted. Deliberately excludes the baseline
     * offline_access/openid/profile scopes (those are not tied to any
     * one selectable capability).
     *
     * @return array<string, string[]>
     */
    public function capabilityScopeMap(): array
    {
        return self::capabilityScopeBundles();
    }

    /**
     * @return array<string, string[]>
     */
    private static function capabilityScopeBundles(): array
    {
        return [
            ResourceType::Contact->value => ['Contacts.Read'],
            ResourceType::CalendarEvent->value => ['Calendars.ReadWrite'],
            ResourceType::Message->value => ['Mail.Read', 'Mail.Send'],
            // Declared here for capabilityScopeMap()/requiredScopes()
            // completeness (checkpoint2-combined-design.md §1.1's
            // capability vocabulary includes Document/OneDrive), even
            // though pull()/push() below honestly decline to support
            // Document sync this checkpoint (§ pullableResourceTypes()/
            // pushableResourceTypes() docblocks) — a firm may still
            // request Files scope now so a future checkpoint's Document
            // sync support does not require a fresh OAuth consent round
            // trip.
            ResourceType::Document->value => ['Files.ReadWrite'],
        ];
    }

    /**
     * @return string[]
     */
    private static function baselineScopes(): array
    {
        return ['offline_access', 'openid', 'profile'];
    }

    // ---------------------------------------------------------------
    // SupportsPullSyncContract
    // ---------------------------------------------------------------

    /**
     * NOT Document — OneDrive delta nuances are an explicitly
     * unresolved documentation gap (official-documentation-research.md's
     * own "Unresolved gaps" note) — declared honestly as unsupported
     * this checkpoint rather than assumed.
     *
     * @return string[]
     */
    public function pullableResourceTypes(): array
    {
        return [ResourceType::Contact->value, ResourceType::CalendarEvent->value, ResourceType::Message->value];
    }

    /**
     * Issues a Graph `/delta` GET request via ProviderRequestExecutor::send()
     * with urlPurpose: 'graph', bearer-token auth injection. The
     * connection's active access-token credential is decrypted via
     * IntegrationCredentialService::decryptForOperation() immediately
     * before building the auth-injector closure; the plaintext is held
     * only for this method's own stack frame and is explicitly unset()
     * once send() returns.
     *
     * $cursor === null means full/initial delta enumeration (the first
     * call, with no prior $deltatoken/$skiptoken). A non-null $cursor is
     * always treated as the full, opaque `@odata.nextLink`/
     * `@odata.deltaLink` URL a PRIOR call to this method returned —
     * never a bare token this method would have to reconstruct a URL
     * around (Graph's own links are opaque; parsing just the token out
     * is unnecessary, fragile surface area).
     *
     * Returns has_more: true + the full `@odata.nextLink` URL as
     * next_cursor for a mid-walk page; has_more: false + the full
     * `@odata.deltaLink` URL as next_cursor on the terminal page — see
     * SupportsPullSyncContract::pull()'s own docblock for why this
     * distinction (added by this checkpoint, P-16) is required for
     * Microsoft specifically: Graph's delta query never terminates with
     * a null continuation token, only a deltaLink, so PullSyncJob's
     * has_more-aware loop condition is what stops the correct persisted
     * cursor from being wiped back to "no prior sync" every cycle.
     *
     * @param  array<string, mixed>  $context
     */
    public function pull(array $context, string $resourceType, ?string $cursor): array
    {
        $connection = $this->resolveConnectionFromContext($context);

        $url = ($cursor !== null && $this->looksLikeUrl($cursor))
            ? $cursor
            : $this->initialDeltaUrlFor($resourceType);

        $accessToken = $this->decryptAccessToken($connection, 'pull_sync');

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Microsoft365,
            method: 'GET',
            url: $url,
            capability: 'sync_pull',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::tryFrom($resourceType),
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: 'pull:'.$connection->id.':'.$resourceType.':'.hash('sha256', (string) $cursor),
            urlPurpose: 'graph',
        );

        unset($accessToken);

        $rawItems = $response->json['value'] ?? [];
        $rawItems = is_array($rawItems) ? array_values($rawItems) : [];

        $items = array_map(static function ($item) use ($resourceType): array {
            $item = is_array($item) ? $item : [];

            return [
                'external_id' => (string) ($item['id'] ?? ''),
                'resource_type' => $resourceType,
                'version_token' => $item['@odata.etag'] ?? null,
                'removed' => array_key_exists('@removed', $item),
            ];
        }, $rawItems);

        $nextLink = $response->json['@odata.nextLink'] ?? null;

        if (is_string($nextLink) && $nextLink !== '') {
            return ['items' => $items, 'next_cursor' => $nextLink, 'has_more' => true];
        }

        $deltaLink = $response->json['@odata.deltaLink'] ?? null;

        return [
            'items' => $items,
            'next_cursor' => (is_string($deltaLink) && $deltaLink !== '') ? $deltaLink : null,
            'has_more' => false,
        ];
    }

    private function initialDeltaUrlFor(string $resourceType): string
    {
        $graphBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::Microsoft365, 'graph');

        $path = match ($resourceType) {
            ResourceType::Contact->value => '/v1.0/me/contacts/delta',
            ResourceType::CalendarEvent->value => '/v1.0/me/events/delta',
            ResourceType::Message->value => "/v1.0/me/mailFolders('inbox')/messages/delta",
            default => throw new InvalidArgumentException(
                "Microsoft365Provider::pull() does not support resource type \"{$resourceType}\"."
            ),
        };

        return $graphBase.$path;
    }

    private function looksLikeUrl(string $value): bool
    {
        return str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
    }

    // ---------------------------------------------------------------
    // SupportsPushSyncContract
    // ---------------------------------------------------------------

    /**
     * Calendar create/update and Mail send are explicitly named in the
     * mission's capability list; Contact push is a plausible, symmetric
     * extension of pullableResourceTypes() and is included too. NOT
     * Document, matching pull()'s own honest declaration.
     *
     * @return string[]
     */
    public function pushableResourceTypes(): array
    {
        return [ResourceType::Contact->value, ResourceType::CalendarEvent->value, ResourceType::Message->value];
    }

    /**
     * Issues the appropriate Graph POST/PATCH via
     * ProviderRequestExecutor::send(). Returns
     * ['external_id' => ..., 'version_token' => ...] per the contract's
     * documented requirement.
     *
     * HONEST LIMITATION, disclosed rather than papered over:
     * App\Jobs\PushSyncJob's own $payload today carries ONLY structural/
     * bookkeeping fields (local_type, local_id, idempotency_key,
     * existing_external_id — confirmed by reading that job in full) —
     * there is no resource-specific field-mapping layer anywhere in
     * this codebase yet that would populate e.g. a contact's name/email
     * or an event's subject/time into the payload. This method strips
     * the known structural keys and forwards WHATEVER remains as the
     * literal outbound Graph body (wrapped in the {"message": {...},
     * "saveToSentItems": true} envelope Graph's sendMail endpoint
     * requires, for the Message resource type specifically) — it does
     * not invent a business-data mapping the framework does not
     * otherwise provide. A future resource-mapping layer can populate
     * real field content into $payload without requiring any change to
     * this method.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    public function push(array $context, string $resourceType, array $payload): array
    {
        $connection = $this->resolveConnectionFromContext($context);

        $existingExternalId = $payload['existing_external_id'] ?? null;
        $existingExternalId = (is_string($existingExternalId) && $existingExternalId !== '') ? $existingExternalId : null;

        [$method, $path] = $this->pushEndpointFor($resourceType, $existingExternalId);

        $graphBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::Microsoft365, 'graph');
        $body = $this->buildPushBody($resourceType, $payload);

        $idempotencyKeyRaw = $payload['idempotency_key'] ?? null;
        $idempotencyKey = (is_string($idempotencyKeyRaw) && $idempotencyKeyRaw !== '')
            ? $idempotencyKeyRaw
            : 'push:'.$connection->id.':'.$resourceType.':'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        $accessToken = $this->decryptAccessToken($connection, 'push_sync');

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Microsoft365,
            method: $method,
            url: $graphBase.$path,
            capability: 'sync_push',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: ResourceType::tryFrom($resourceType),
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: $idempotencyKey,
            body: $body,
            urlPurpose: 'graph',
        );

        unset($accessToken);

        $externalId = $response->json['id'] ?? $existingExternalId
            ?? ('sent:'.hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)));

        return [
            'external_id' => (string) $externalId,
            'version_token' => $response->json['@odata.etag'] ?? null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function pushEndpointFor(string $resourceType, ?string $existingExternalId): array
    {
        return match ($resourceType) {
            ResourceType::Contact->value => $existingExternalId !== null
                ? ['PATCH', "/v1.0/me/contacts/{$existingExternalId}"]
                : ['POST', '/v1.0/me/contacts'],
            ResourceType::CalendarEvent->value => $existingExternalId !== null
                ? ['PATCH', "/v1.0/me/events/{$existingExternalId}"]
                : ['POST', '/v1.0/me/events'],
            // Mail has no "update" concept — every push is a fresh send.
            ResourceType::Message->value => ['POST', '/v1.0/me/sendMail'],
            default => throw new InvalidArgumentException(
                "Microsoft365Provider::push() does not support resource type \"{$resourceType}\"."
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildPushBody(string $resourceType, array $payload): array
    {
        $body = array_diff_key($payload, array_flip(self::PUSH_STRUCTURAL_PAYLOAD_KEYS));

        if ($resourceType === ResourceType::Message->value) {
            // Graph's sendMail body must be shaped
            // {"message": {...}, "saveToSentItems": true} — wrap
            // whatever message-shaped fields the caller supplied rather
            // than sending them at the top level.
            return ['message' => $body, 'saveToSentItems' => true];
        }

        return $body;
    }

    // ---------------------------------------------------------------
    // SupportsIncrementalSyncContract
    // ---------------------------------------------------------------

    /**
     * true for every resource type pull() actually issues a /delta
     * request for; false for Document (matches pullableResourceTypes()'s
     * own honest declaration) and anything else outside this provider's
     * vocabulary.
     */
    public function supportsIncrementalFor(string $resourceType): bool
    {
        return in_array($resourceType, $this->pullableResourceTypes(), true);
    }

    /**
     * Per checkpoint2-design-sync-webhooks.md §2: this contract has zero
     * production callers today — PullSyncJob decides delta-vs-full
     * purely by cursor-value presence via SyncCursorService, never
     * consulting this method. Implemented here as a correct, honest,
     * currently-dead-code-path read (a future UI diagnostic, or a
     * future PullSyncJob wiring), never a permanent stub that would lie
     * if ever actually invoked: requires $context['firm_id'] AND
     * $context['firm_integration_id'], returns null if either is
     * missing (fail-safe — "no prior sync" is always a safe, non-throwing
     * answer). When both are present, resolves the connection under
     * TenantContextService::runWithFirmContext() and delegates to
     * SyncCursorService::firstOrCreate() + decryptCursorValue() — a
     * read-only mirror of what PullSyncJob already does internally,
     * never a second source of truth.
     *
     * @param  array<string, mixed>  $context
     */
    public function incrementalCursorFor(array $context, string $resourceType): ?string
    {
        if (! $this->supportsIncrementalFor($resourceType)) {
            return null;
        }

        $firmId = $this->coerceToPositiveInt($context['firm_id'] ?? null);
        $firmIntegrationId = $this->coerceToPositiveInt($context['firm_integration_id'] ?? null);

        if ($firmId === null || $firmIntegrationId === null) {
            return null;
        }

        return (new TenantContextService)->runWithFirmContext($firmId, function () use ($firmIntegrationId, $resourceType): ?string {
            $connection = FirmIntegration::query()->where('id', $firmIntegrationId)->first();

            if ($connection === null) {
                return null;
            }

            $cursor = $this->cursors->firstOrCreate($connection, $resourceType, SyncDirection::Inbound);

            return $this->cursors->decryptCursorValue($connection, $cursor);
        });
    }

    // ---------------------------------------------------------------
    // SupportsWebhooksContract
    // ---------------------------------------------------------------

    /**
     * The Graph `changeType` vocabulary this provider actually handles.
     *
     * @return string[]
     */
    public function webhookEventTypes(): array
    {
        return ['created', 'updated', 'deleted'];
    }

    /**
     * Microsoft Graph has no per-request cryptographic signature. Its
     * documented anti-forgery mechanism IS the clientState match already
     * performed by WebhookConnectionResolverService::resolveConnectionIdentity()
     * (via extractRoutingIdentifier(), called BEFORE this method, on
     * this SAME $rawBody) — a request only reaches this method after
     * that hash-backed lookup has already succeeded against a specific,
     * FirmsVault-minted 256-bit CSPRNG value
     * (ProviderConnectionService::enableWebhookRouting()'s
     * webhook_routing_token, reused verbatim by subscribe() below as
     * Graph's clientState). This method has no connection-identity
     * parameter (by SupportsWebhooksContract's own deliberate design)
     * and therefore cannot independently re-derive an "expected"
     * clientState to compare against beyond what routing resolution
     * already confirmed — re-parsing $rawBody here would only reproduce
     * the identical check already performed. Returning true is the
     * honest representation of "verification, for this provider, is
     * complete by the time this method is called," not a bypass.
     *
     * This reasoning is SPECIFIC to Microsoft's own documented security
     * model and applies ONLY to this provider — it must never be copied
     * onto a future provider that has a real, independent per-request
     * signature to check (e.g. an HMAC-based provider, where resolving a
     * connection via routing token is a separate, WEAKER check than the
     * signature itself, which is exactly why this method exists as a
     * distinct step for that shape of provider).
     *
     * @param  array<string, mixed>  $headers
     */
    public function verifyInboundSignature(string $rawBody, array $headers): bool
    {
        return true;
    }

    /**
     * Graph's `resource` field (e.g. "me/mailFolders('Inbox')/messages",
     * "me/events", "me/contacts") never appears verbatim in
     * ResourceType's own vocabulary — a substring match against each of
     * this provider's own pullableResourceTypes() is the deliberately
     * narrow, closed mapping used to prefix event_type below, matching
     * App\Integrations\Listeners\DispatchPullSyncOnVerifiedWebhookEvent's
     * own documented contract ("event_type should either exactly match
     * a ResourceType value or LEAD WITH one, delimited by one of
     * `.`/`:`/`/`/`-`"). Never a guess: an unrecognized resource string
     * returns null, and the caller leaves event_type unprefixed for
     * that item (the listener then correctly skips dispatching rather
     * than picking a wrong resource type).
     */
    private function resourceTypeSegmentFor(string $resource): ?string
    {
        $normalized = strtolower($resource);

        return match (true) {
            str_contains($normalized, 'messages') => ResourceType::Message->value,
            str_contains($normalized, 'events') => ResourceType::CalendarEvent->value,
            str_contains($normalized, 'contacts') => ResourceType::Contact->value,
            default => null,
        };
    }

    /**
     * Decodes `value[]`; derives a deterministic SHA-256 content-
     * fingerprint `event_id` over each item's
     * (subscriptionId, resource, changeType-or-lifecycleEvent,
     * resourceData.id) tuple, JSON-encoded then hashed — Graph
     * notifications carry no stable per-delivery id field of their own,
     * so this is the only way to derive a value that is BOTH stable
     * under true redelivery (identical value[] content -> identical
     * hash) AND distinct for genuinely different changes.
     *
     * `event_type` is the sorted-unique, comma-joined set of
     * resource-type-prefixed changeTypes across the batch (e.g.
     * `"message:created"`, lifecycle items prefixed `lifecycle:`
     * unchanged — Graph's own lifecycle notifications carry no
     * resource-type-shaped `resource` value worth prefixing), matching
     * Checkpoint 1's confirmed-permissive stance that event_type is any
     * string, never validated against a closed set — and satisfying
     * DispatchPullSyncOnVerifiedWebhookEvent's own documented
     * "leads with a ResourceType value" contract, closing a real,
     * previously-undisclosed defect: webhook-triggered sync could never
     * actually fire for a real Microsoft delivery before this fix,
     * since a bare changeType like "created" never matches or leads
     * with any ResourceType value on its own.
     *
     * Returns ['event_id' => null, 'event_type' => null, 'payload' =>
     * []] if `value` is missing/empty/not an array — routes to the
     * existing malformed_payload rejection path.
     *
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function parseInboundEvent(string $rawBody, array $headers): array
    {
        $decoded = json_decode($rawBody, true);
        $items = is_array($decoded['value'] ?? null) ? $decoded['value'] : [];

        if ($items === []) {
            return ['event_id' => null, 'event_type' => null, 'payload' => []];
        }

        $fingerprint = array_map(static function ($item): array {
            $item = is_array($item) ? $item : [];

            return [
                $item['subscriptionId'] ?? null,
                $item['resource'] ?? null,
                $item['changeType'] ?? ($item['lifecycleEvent'] ?? null),
                $item['resourceData']['id'] ?? null,
            ];
        }, $items);

        $eventId = hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR));

        $changeTypes = array_values(array_unique(array_filter(array_map(
            function ($item): ?string {
                $item = is_array($item) ? $item : [];

                if (isset($item['changeType']) && is_string($item['changeType']) && $item['changeType'] !== '') {
                    $resourceSegment = is_string($item['resource'] ?? null)
                        ? $this->resourceTypeSegmentFor($item['resource'])
                        : null;

                    return $resourceSegment !== null
                        ? "{$resourceSegment}:{$item['changeType']}"
                        : $item['changeType'];
                }

                if (isset($item['lifecycleEvent']) && is_string($item['lifecycleEvent']) && $item['lifecycleEvent'] !== '') {
                    return 'lifecycle:'.$item['lifecycleEvent'];
                }

                return null;
            },
            $items,
        ))));

        sort($changeTypes);

        return [
            'event_id' => $eventId,
            'event_type' => $changeTypes === [] ? null : implode(',', $changeTypes),
            'payload' => ['value' => $items],
        ];
    }

    /**
     * Returns null unless $queryParams['validationToken'] is present and
     * non-empty; otherwise echoes it byte-for-byte, no HTML/JS-escaping
     * ($queryParams already comes from Laravel's already-URL-decoded
     * $request->query()). Never throws.
     *
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, mixed>  $headers
     * @return array{body: string, status: int, content_type: string}|null
     */
    public function detectSubscriptionValidationChallenge(array $queryParams, array $headers): ?array
    {
        $token = $queryParams['validationToken'] ?? null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        return ['body' => $token, 'status' => 200, 'content_type' => 'text/plain'];
    }

    /**
     * Decodes `value[]`; collects every item's `clientState`. Returns
     * null (fail-closed) unless ALL items share one identical, non-empty
     * `clientState` — never guesses/uses just the first one. Graph's
     * batching model (whether a single POST could ever legitimately
     * carry notifications for two different subscriptions/clientStates)
     * is not confirmed by this checkpoint's research; this fail-closed
     * design means such a request (if it ever occurs) is rejected
     * outright rather than risking a cross-connection misattribution.
     *
     * @param  array<string, mixed>  $headers
     */
    public function extractRoutingIdentifier(string $rawBody, array $headers): ?string
    {
        $decoded = json_decode($rawBody, true);
        $items = is_array($decoded['value'] ?? null) ? $decoded['value'] : [];

        if ($items === []) {
            return null;
        }

        $clientStates = [];

        foreach ($items as $item) {
            $item = is_array($item) ? $item : [];
            $clientState = $item['clientState'] ?? null;

            if (! is_string($clientState) || $clientState === '') {
                return null;
            }

            $clientStates[] = $clientState;
        }

        $unique = array_values(array_unique($clientStates));

        return count($unique) === 1 ? $unique[0] : null;
    }

    /**
     * Requires $context['connection']->webhook_routing_token to be
     * non-null — already guaranteed by phase-1's auto-enableWebhookRouting()
     * call at OAuth-completion (ProviderConnectionService::finishCallback(),
     * P-6g). Uses that token VERBATIM as Graph's clientState — no new
     * token generation, reusing the existing generic routing-token
     * mechanism entirely unmodified (this class never reads or writes
     * the underlying routing-index storage directly).
     *
     * Idempotent from FirmsVault's own side FIRST: checks
     * integration_provider_webhook_subscriptions for an existing,
     * non-expired, Active row for (connection, provider_resource,
     * provider_change_type) before ever calling Graph; if found, returns
     * it unchanged. On success against Graph, the CALLER (this
     * checkpoint's own connect-flow orchestration, not this method) is
     * responsible for persisting the returned subscription state into
     * that table — this method only returns
     * ['subscription_id' => ..., 'expires_at' => ..., 'resource' => ...,
     * 'change_type' => ...].
     *
     * $context accepts 'resource_type' (a ResourceType value, required),
     * plus optional 'provider_resource'/'provider_change_type'
     * overrides — mirrors exactly what
     * App\Integrations\Jobs\RenewGraphSubscriptionJob's own 404-triggered
     * fresh-subscribe call site supplies
     * (['connection' => ..., 'resource_type' => ..., 'provider_resource'
     * => ..., 'provider_change_type' => ...]).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function subscribe(array $context): array
    {
        $connection = $this->resolveConnectionFromContext($context);

        if ($connection->webhook_routing_token === null) {
            throw new RuntimeException(
                "Microsoft365Provider::subscribe() requires connection {$connection->id} to already have webhook routing enabled (webhook_routing_token is null)."
            );
        }

        $resourceType = $context['resource_type'] ?? null;

        if (! is_string($resourceType) || $resourceType === '') {
            throw new InvalidArgumentException("Microsoft365Provider::subscribe() requires \$context['resource_type'].");
        }

        $providerResourceRaw = $context['provider_resource'] ?? null;
        $providerResource = (is_string($providerResourceRaw) && $providerResourceRaw !== '')
            ? $providerResourceRaw
            : $this->graphResourcePathFor($resourceType);

        $changeTypeRaw = $context['provider_change_type'] ?? null;
        $changeType = (is_string($changeTypeRaw) && $changeTypeRaw !== '') ? $changeTypeRaw : self::DEFAULT_CHANGE_TYPES;

        $existing = IntegrationProviderWebhookSubscription::query()
            ->where('firm_integration_id', $connection->id)
            ->where('provider_resource', $providerResource)
            ->where('provider_change_type', $changeType)
            ->where('status', ProviderWebhookSubscriptionStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($existing !== null) {
            return [
                'subscription_id' => $existing->provider_subscription_id,
                'expires_at' => $existing->expires_at?->toIso8601String(),
                'resource' => $existing->provider_resource,
                'change_type' => $existing->provider_change_type,
            ];
        }

        $accessToken = $this->decryptAccessToken($connection, 'webhook_subscribe');

        $graphBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::Microsoft365, 'graph');
        $notificationUrl = route('integrations.webhooks.inbound', ['provider' => ProviderKey::Microsoft365->value], true);
        $expirationDateTime = now()->addMinutes(self::SUBSCRIPTION_LIFETIME_MINUTES)->toIso8601String();

        $body = [
            'changeType' => $changeType,
            'notificationUrl' => $notificationUrl,
            'resource' => $providerResource,
            'expirationDateTime' => $expirationDateTime,
            'clientState' => $connection->webhook_routing_token,
        ];

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Microsoft365,
            method: 'POST',
            url: $graphBase.'/v1.0/subscriptions',
            capability: 'webhooks',
            operationType: 'webhook_subscribe',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::tryFrom($resourceType),
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: 'webhook_subscribe:'.$connection->id.':'.hash('sha256', $providerResource.'|'.$changeType),
            body: $body,
            urlPurpose: 'graph',
        );

        unset($accessToken);

        return [
            'subscription_id' => $response->json['id'] ?? null,
            'expires_at' => $response->json['expirationDateTime'] ?? $expirationDateTime,
            'resource' => $response->json['resource'] ?? $providerResource,
            'change_type' => $response->json['changeType'] ?? $changeType,
        ];
    }

    /**
     * Requires $context['subscription'] to be an
     * IntegrationProviderWebhookSubscription instance (the ACTUAL shape
     * App\Integrations\Jobs\RenewGraphSubscriptionJob::handle() passes —
     * ['connection' => $connection, 'subscription' => $subscription] —
     * confirmed by reading that job's real, current call site rather
     * than assumed); falls back to a bare
     * $context['provider_subscription_id'] string for any other future
     * caller. Calls Graph PATCH {graph-base}/subscriptions/{id} with a
     * renewed expirationDateTime; returns the same shape as subscribe().
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function renewSubscription(array $context): array
    {
        $connection = $this->resolveConnectionFromContext($context);

        $subscriptionModel = $context['subscription'] ?? null;
        $subscriptionId = null;
        $fallbackResource = null;
        $fallbackChangeType = null;
        $renewalCycleToken = null;

        if ($subscriptionModel instanceof IntegrationProviderWebhookSubscription) {
            $subscriptionId = $subscriptionModel->provider_subscription_id;
            $fallbackResource = $subscriptionModel->provider_resource;
            $fallbackChangeType = $subscriptionModel->provider_change_type;

            // DETERMINISTIC RENEWAL-CYCLE IDENTITY, computed from the
            // SAME durable fields
            // App\Integrations\Jobs\RenewGraphSubscriptionJob::handle()
            // already hashes into its own `$renewalCycleToken` — that
            // job is the one and only production caller of this method
            // (confirmed by grepping `->renewSubscription(` across app/),
            // and it always supplies `$context['subscription']` as a
            // hydrated model, so this branch is the real path.
            //
            // This key used to end in `now()->format('YmdHi')`. The job
            // retries with backoff() = [30, 60, 120, 240] and $tries = 5,
            // so every retry after the first almost certainly lands in a
            // DIFFERENT wall-clock minute than the attempt it is
            // retrying — which meant one logical renewal wrote up to five
            // SEPARATE local usage-record rows and sent Graph five
            // DIFFERENT Idempotency-Key header values, defeating both
            // dedup layers at once.
            //
            // `expires_at` already IS the durable "this specific renewal
            // still needs doing" marker: the job re-reads the
            // subscription row fresh at the top of every attempt and
            // rewrites provider_subscription_id/expires_at ONLY once a
            // renewal actually SUCCEEDS. So all five attempts at one
            // renewal share a key, while the next genuine renewal of the
            // same subscription gets a different one.
            $renewalCycleToken = hash('sha256', implode('|', [
                (string) $connection->id,
                (string) $subscriptionModel->id,
                (string) ($subscriptionModel->provider_subscription_id ?? ''),
                $subscriptionModel->expires_at?->toIso8601String() ?? 'no_expiry',
            ]));
        } elseif (is_string($context['provider_subscription_id'] ?? null) && $context['provider_subscription_id'] !== '') {
            $subscriptionId = $context['provider_subscription_id'];
        }

        if (! is_string($subscriptionId) || $subscriptionId === '') {
            throw new InvalidArgumentException(
                "Microsoft365Provider::renewSubscription() requires \$context['subscription'] ".
                '(an IntegrationProviderWebhookSubscription with a provider_subscription_id) or '.
                "\$context['provider_subscription_id']."
            );
        }

        $accessToken = $this->decryptAccessToken($connection, 'webhook_subscribe');

        $graphBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::Microsoft365, 'graph');
        $expirationDateTime = now()->addMinutes(self::SUBSCRIPTION_LIFETIME_MINUTES)->toIso8601String();

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Microsoft365,
            method: 'PATCH',
            url: $graphBase.'/v1.0/subscriptions/'.$subscriptionId,
            capability: 'webhooks',
            operationType: 'webhook_subscribe',
            direction: SyncDirection::Inbound,
            resourceType: null,
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            // INTENTIONALLY TIME-BASED ONLY on the `$renewalCycleToken
            // === null` fallback branch (documented per the security
            // review's own "document each remaining time-based use that
            // is intentionally not an idempotency identity"): that
            // branch is reached only by a caller that supplies a BARE
            // `$context['provider_subscription_id']` opaque string and
            // no subscription model. Such a caller hands this method no
            // durable renewal-cycle marker at all — no local row id, no
            // expires_at — so there is genuinely nothing stable to
            // anchor on, and a bare `$subscriptionId`-only key would be
            // worse than the clock: it never changes, so the SECOND
            // genuine renewal of that subscription would collide
            // permanently against `integration_usage_records`' own
            // `unique(firm_integration_id, idempotency_key)` index and
            // silently stop recording usage forever. This branch has
            // ZERO production callers today (verified by grep); the real
            // path above is fully deterministic.
            usageIdempotencyKey: 'webhook_renew:'.$connection->id.':'.$subscriptionId.':'
                .($renewalCycleToken ?? now()->format('YmdHi')),
            body: ['expirationDateTime' => $expirationDateTime],
            urlPurpose: 'graph',
        );

        unset($accessToken);

        return [
            'subscription_id' => $response->json['id'] ?? $subscriptionId,
            'expires_at' => $response->json['expirationDateTime'] ?? $expirationDateTime,
            'resource' => $response->json['resource'] ?? $fallbackResource,
            'change_type' => $response->json['changeType'] ?? $fallbackChangeType,
        ];
    }

    private function graphResourcePathFor(string $resourceType): string
    {
        return match ($resourceType) {
            ResourceType::Contact->value => 'me/contacts',
            ResourceType::CalendarEvent->value => 'me/events',
            ResourceType::Message->value => "me/mailFolders('inbox')/messages",
            default => throw new InvalidArgumentException(
                "Microsoft365Provider does not support webhook subscriptions for resource type \"{$resourceType}\"."
            ),
        };
    }

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    /**
     * See this class's own top-of-file docblock for the discovered,
     * disclosed gap this fallback exists to tolerate: today's real
     * App\Jobs\PullSyncJob/App\Jobs\PushSyncJob call sites never thread
     * a 'connection'/'firm_integration_id' key into the $context this
     * method reads. Accepts EITHER key (mirroring
     * SupportsIncrementalSyncContract::incrementalCursorFor()'s own
     * documented convention) so this class is ready the moment that
     * follow-up wiring lands, and fails loudly with a typed exception —
     * never a silent guess — when neither is present.
     *
     * @param  array<string, mixed>  $context
     */
    private function resolveConnectionFromContext(array $context): FirmIntegration
    {
        $connection = $context['connection'] ?? null;

        if ($connection instanceof FirmIntegration) {
            return $connection;
        }

        $firmIntegrationId = $this->coerceToPositiveInt($context['firm_integration_id'] ?? null);

        if ($firmIntegrationId !== null) {
            return FirmIntegration::query()->findOrFail($firmIntegrationId);
        }

        throw new InvalidArgumentException(
            'Microsoft365Provider requires $context[\'connection\'] (a FirmIntegration instance) or '.
            '$context[\'firm_integration_id\'] to resolve the active connection for this operation.'
        );
    }

    /**
     * DETERMINISTIC REFRESH-CYCLE IDENTITY for refreshToken()'s usage
     * idempotency key (replaces a `now()->format('YmdHi')` component).
     *
     * The sole production caller is
     * `App\Integrations\Services\ProviderConnectionService::refreshConnectionToken()`,
     * itself driven by the retrying `App\Integrations\Jobs\RefreshIntegrationToken`
     * job — so one logical refresh can genuinely reach this method
     * several times, and with a wall-clock key each attempt wrote a
     * SEPARATE `integration_usage_records` row and sent Microsoft a
     * DIFFERENT `Idempotency-Key` header, defeating local usage
     * deduplication, provider-side idempotency, and audit correlation
     * alike.
     *
     * The anchor is the connection's CURRENTLY-Active access-token
     * credential — the exact structural analogue of the subscription
     * row `App\Integrations\Jobs\RenewGraphSubscriptionJob` anchors its
     * own `$renewalCycleToken` on. `refreshConnectionToken()` reads that
     * credential under `withRefreshLock()` and only calls
     * `IntegrationCredentialService::rotate()` AFTER this method
     * returns successfully; `rotate()` marks the old row `Rotated` and
     * stores a NEW row (a new id, a new expires_at). So the token is
     * stable across every retry of one refresh and advances exactly
     * once a refresh actually completes — never a permanently static
     * key, which `integration_usage_records`' own
     * `unique(firm_integration_id, idempotency_key)` index would turn
     * into "silently stop recording usage forever".
     *
     * `$refreshToken` is folded in (hashed, never in the clear —
     * matching this file's existing `hash('sha256', $code)` precedent
     * in exchangeCodeForToken()) as a second, independent advance
     * signal: Microsoft's documented MANDATORY refresh-token rotation
     * means this value also changes on every successful refresh, so the
     * key still advances correctly even in the defensive
     * no-access-credential case below (which `refreshConnectionToken()`
     * itself already makes unreachable — it throws on a missing active
     * access credential before ever calling this provider).
     */
    private function oauthRefreshCycleToken(FirmIntegration $connection, string $refreshToken): string
    {
        $accessCredential = $this->credentials->findActiveCredential($connection, CredentialType::OauthAccessToken);

        return hash('sha256', implode('|', [
            (string) $connection->id,
            (string) ($accessCredential?->id ?? 'no_access_credential'),
            $accessCredential?->expires_at?->toIso8601String() ?? 'no_expiry',
            hash('sha256', $refreshToken),
        ]));
    }

    /**
     * Decrypts the connection's Active OAuth access-token credential
     * immediately before use — the caller MUST hold the returned
     * plaintext only for the duration of building the auth-injector
     * closure and unset() it as soon as send() returns (every call site
     * in this class does so).
     */
    private function decryptAccessToken(FirmIntegration $connection, string $operationSuffix): string
    {
        $credential = $this->credentials->findActiveCredential($connection, CredentialType::OauthAccessToken);

        if ($credential === null) {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED,
                null,
                $operationSuffix,
            );
        }

        return $this->credentials->decryptForOperation(
            $connection,
            $credential,
            'microsoft365 '.$operationSuffix.' connection '.$connection->id,
            $operationSuffix,
        );
    }

    /**
     * Base64url-decodes an ID token's middle (payload) JWS segment only
     * — no signature verification (see exchangeCodeForToken()'s own
     * docblock for the full, disclosed justification) — then validates
     * the REQUIRED `aud`/`iss` claims (security review Finding 2, P1)
     * before returning the claims for the caller to read `tid`/`oid`
     * from. Throws a SanitizedProviderHttpException (category
     * authentication_failed) — never a soft pass with null/partial
     * claims — if the token is missing, malformed, or either claim
     * check fails.
     *
     * @return array<string, mixed>
     */
    private function decodeAndValidateIdToken(mixed $idToken, string $expectedClientId): array
    {
        if (! is_string($idToken) || $idToken === '') {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED,
                null,
                'token_exchange',
            );
        }

        $segments = explode('.', $idToken);

        if (count($segments) !== 3) {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED,
                null,
                'token_exchange',
            );
        }

        $payloadJson = $this->base64UrlDecode($segments[1]);

        if ($payloadJson === null) {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED,
                null,
                'token_exchange',
            );
        }

        $claims = json_decode($payloadJson, true);

        if (! is_array($claims)) {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED,
                null,
                'token_exchange',
            );
        }

        $aud = $claims['aud'] ?? null;

        if (! is_string($aud) || ! hash_equals($expectedClientId, $aud)) {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED,
                null,
                'token_exchange',
            );
        }

        $tid = $claims['tid'] ?? null;

        if (! is_string($tid) || $tid === '') {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED,
                null,
                'token_exchange',
            );
        }

        $iss = $claims['iss'] ?? null;
        $expectedIssuer = "https://login.microsoftonline.com/{$tid}/v2.0";

        if (! is_string($iss) || ! hash_equals($expectedIssuer, $iss)) {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED,
                null,
                'token_exchange',
            );
        }

        return $claims;
    }

    private function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;

        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Format-validates a tenant-domain hint (security review Finding 10)
     * before it is ever interpolated into an authorize/token URL path.
     * A genuinely absent hint (null, or an empty/whitespace-only string)
     * falls back to 'organizations' silently. A PRESENT but non-string
     * or malformed value is rejected via InvalidArgumentException —
     * never silently substituted — matching a DNS-domain-like pattern
     * (e.g. `contoso.onmicrosoft.com`) or a bare GUID.
     */
    private function resolveTenantSegment(mixed $hint): string
    {
        if ($hint === null) {
            return 'organizations';
        }

        if (! is_string($hint)) {
            throw new InvalidArgumentException('Microsoft365Provider: ms_tenant_hint must be a string.');
        }

        $trimmed = trim($hint);

        if ($trimmed === '') {
            return 'organizations';
        }

        if (! $this->isValidTenantHint($trimmed)) {
            throw new InvalidArgumentException(
                "Microsoft365Provider: ms_tenant_hint \"{$trimmed}\" is not a valid tenant domain or GUID."
            );
        }

        return $trimmed;
    }

    private function isValidTenantHint(string $hint): bool
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $hint) === 1) {
            return true;
        }

        return preg_match(
            '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i',
            $hint,
        ) === 1;
    }

    private function coerceToPositiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
