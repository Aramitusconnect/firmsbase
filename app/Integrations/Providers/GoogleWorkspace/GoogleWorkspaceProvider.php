<?php

declare(strict_types=1);

namespace App\Integrations\Providers\GoogleWorkspace;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsDisconnectContract;
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
use App\Integrations\Support\GmailMailboxRoutingService;
use App\Integrations\Support\ProviderEnvironmentResolver;
use App\Integrations\Support\ProviderRequestExecutor;
use App\Services\TenantContextService;
use Google\Auth\AccessToken;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * GoogleWorkspaceProvider — FirmsVault Live Integrations, Checkpoint 3.
 * The second REAL (non-simulated) provider adapter in this codebase,
 * built to `checkpoint3-combined-design.md`'s frozen, security-reviewed
 * design (checkpoint3-security-review.md's three required Findings —
 * 1, 2, 3 — are all binding on `App\Integrations\Services\ProviderConnectionService`/
 * `app/Providers/IntegrationServiceProvider.php`/`App\Services\RowLevelSecurityCoverageMappingService`,
 * a parallel writer's scope, NOT this file's — but this class is
 * written to fit the corrected contract those fixes establish:
 * `subscribe()` is reached both from the new
 * `ProviderConnectionService::bootstrapWebhookSubscriptions()`
 * orchestration on first connect AND from
 * `App\Integrations\Jobs\RenewGraphSubscriptionJob.php:176`'s existing
 * 404-triggered re-subscribe fallback, and `Google\Auth\AccessToken` is
 * constructor-injected, never constructed inline, so it is swappable
 * for a test double).
 *
 * Structural template: `App\Integrations\Providers\Microsoft365\Microsoft365Provider`
 * — this class follows the exact same method-by-method shape wherever
 * the mechanics are analogous (OAuth token exchange/refresh, ID-token
 * claim validation, `resolveConnectionFromContext()`/`decryptAccessToken()`
 * helpers, the push-payload structural-key-stripping discipline,
 * `incrementalCursorFor()`), and diverges only where Google's own APIs
 * are genuinely different — see each method's own docblock for the
 * specific divergence and its source in the frozen design.
 *
 * Implements `SupportsDisconnectContract` (genuinely different posture
 * from Microsoft365Provider's deliberate non-implementation): Google
 * exposes a real self-service revoke endpoint
 * (`POST https://oauth2.googleapis.com/revoke`), unlike Graph — see
 * `revokeAtProvider()`.
 *
 * Deliberately does NOT implement `SupportsApiKeyContract` — Google
 * Workspace is OAuth-only for the per-user delegated-consent model this
 * checkpoint targets (checkpoint3-combined-design.md §1.4 — domain-wide
 * delegation is explicitly out of scope, settled, not reopened here).
 *
 * DISCLOSED JUDGMENT CALL (not resolved by any of the three frozen
 * design documents, which enumerate `pubsub_push_audience`/
 * `pubsub_push_service_account_email`/`gmail_mailbox_routing_hmac_key`
 * as the only new `oauth_apps.googleworkspace` config keys but never
 * name where Gmail's `watch()` call obtains the Pub/Sub `topicName`
 * parameter it is REQUIRED to send): this class reads
 * `config('integrations.oauth_apps.googleworkspace.gmail_pubsub_topic_name')`
 * (the fully-qualified `projects/{project}/topics/{topic}` resource
 * name of the one shared Pub/Sub topic §4.8/§6.6 of the design
 * describes) — a fourth, additive key belonging to the same
 * `oauth_apps.googleworkspace` config block the parallel writer owns.
 * Flagged prominently in this checkpoint's final report as a config key
 * that config/integrations.php (out of this file's scope) must supply
 * for `subscribeGmail()`/`renewGmail()` to function against a real GCP
 * project.
 *
 * DISCLOSED, NOT FIXED HERE (mirrors Microsoft365Provider's own
 * top-of-file precedent of disclosing a gap outside its file's scope
 * rather than silently working around it): `extractRoutingIdentifier()`'s
 * Gmail branch returns the inbound (unverified, at that pipeline step)
 * `emailAddress` field, exactly as `checkpoint3-design-sync-webhooks.md`
 * §6.2 specifies — but `App\Integrations\Services\WebhookConnectionResolverService::resolveConnectionIdentity()`,
 * the ONLY consumer `App\Integrations\Http\Controllers\InboundWebhookController`
 * calls with that returned value, is hardcoded to hash-and-look-up
 * against `integration_webhook_routing_index` — a table Gmail routes
 * are, by the human reviewer's own binding mandate, NEVER written into.
 * As of this file alone, nothing wires `GmailMailboxRoutingService::resolveByMailbox()`
 * into that resolution step, so a real inbound Gmail Pub/Sub delivery
 * cannot yet resolve to a connection through today's unmodified
 * controller/resolver pipeline. Neither `InboundWebhookController.php`
 * nor `WebhookConnectionResolverService.php` is in this file's scope to
 * modify (both are explicitly the parallel writer's/coordinator's
 * territory), and neither file is named in the frozen design's own §7
 * consolidated file-change list despite the design's §6.2 prose
 * describing exactly this widening as necessary — this is flagged here,
 * prominently, as a real, disclosed integration gap for the coordinator,
 * not silently papered over.
 */
final class GoogleWorkspaceProvider implements IntegrationProviderContract, SupportsDisconnectContract, SupportsIncrementalSyncContract, SupportsOAuthContract, SupportsPullSyncContract, SupportsPushSyncContract, SupportsWebhooksContract
{
    /**
     * Bounded clock-skew tolerance for the Gmail Pub/Sub OIDC JWT's
     * `iat` freshness re-check (checkpoint3-design-sync-webhooks.md
     * §6.3.1 requirement #6, §6.3.3) — `Google\Auth\AccessToken::verify()`
     * itself already rejects an expired `exp` by construction (spot-checked
     * live against the library's real source,
     * checkpoint3-security-review.md Finding 4); this constant governs
     * ONLY this class's own additional "not issued in the future" check,
     * which that library method does not itself perform.
     */
    private const CLOCK_SKEW_SECONDS = 300;

    /**
     * CONSERVATIVE PLACEHOLDER — Google Calendar's `events.watch()` push
     * channel has NO documented maximum duration ("Google Calendar API
     * internal limits or defaults (the more restrictive value is used)"
     * — checkpoint3-design-sync-webhooks.md §3.3). 604800 seconds
     * (7 days) is used here as an honest, disclosed starting value —
     * mirrors the SAME disclosed-placeholder posture
     * `Microsoft365Provider::SUBSCRIPTION_LIFETIME_MINUTES` already
     * carries for an analogous "not deep-fetched, revisit before
     * production" gap. `RenewProviderWebhookSubscriptionsCommand`'s
     * renewal safety margin is computed from each subscription's own
     * actual `expires_at` — NOT from this constant.
     */
    private const CALENDAR_WATCH_TTL_SECONDS = 604800;

    /**
     * Drive's `changes.watch()` push channel DOES document its own
     * ceiling precisely: "the maximum expiration time is ... 604800
     * seconds (1 week) for `changes`" (checkpoint3-design-sync-webhooks.md
     * §4.3) — this checkpoint watches the `changes` resource, so this is
     * the documented maximum, requested explicitly rather than relying
     * on Google's much shorter 1-hour default, mirroring
     * `Microsoft365Provider`'s own "request the max explicitly" posture.
     */
    private const DRIVE_CHANGES_WATCH_TTL_SECONDS = 604800;

    /**
     * Payload keys App\Jobs\PushSyncJob's own payload-building code
     * always includes that are structural/framework bookkeeping, never
     * real Google-API-shaped resource field data — stripped out of
     * push()'s outbound body before anything else is treated as real
     * field content. Identical list to Microsoft365Provider's own
     * constant of the same name/purpose.
     */
    private const PUSH_STRUCTURAL_PAYLOAD_KEYS = [
        'local_type', 'local_id', 'idempotency_key', 'existing_external_id', '__simulate_failure',
    ];

    public function __construct(
        private readonly ProviderRequestExecutor $executor,
        private readonly IntegrationCredentialService $credentials,
        private readonly SyncCursorService $cursors,
        private readonly GmailMailboxRoutingService $mailboxRouting,
        // checkpoint3-security-review.md Finding 2 (required, binding):
        // NEVER `new AccessToken(` inline anywhere in this file —
        // constructor-injected only, resolved by Laravel's service
        // container (bound as a singleton in
        // app/Providers/IntegrationServiceProvider.php — the parallel
        // writer's scope), so every test exercising Gmail webhook
        // verification can swap this for a test double via
        // `app()->instance(AccessToken::class, $fake)`, identical to the
        // existing `app()->instance($class, $provider)` precedent this
        // codebase already establishes for provider instances.
        private readonly AccessToken $accessTokenVerifier,
    ) {}

    // ---------------------------------------------------------------
    // IntegrationProviderContract
    // ---------------------------------------------------------------

    public function key(): ProviderKey
    {
        return ProviderKey::GoogleWorkspace;
    }

    public function displayName(): string
    {
        return 'Google Workspace';
    }

    public function description(): string
    {
        return 'Connect Gmail, Calendar, and Drive via Google Workspace.';
    }

    public function isConfigured(): bool
    {
        $clientId = config('integrations.oauth_apps.googleworkspace.client_id');
        $clientSecret = config('integrations.oauth_apps.googleworkspace.client_secret');

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
     * `client_id` from platform config, never `$params['client_id']`
     * (checkpoint3-design-oauth-capabilities.md §3.2).
     *
     * `access_type=offline` is REQUIRED to receive a refresh token at
     * all. `prompt=consent` is sent UNCONDITIONALLY on every
     * connect/reconnect — a load-bearing detail, not optional: without
     * it, a returning user who already granted consent receives NO
     * `refresh_token` on a second authorization even with
     * `access_type=offline` set, and
     * `ProviderConnectionService::finishCallback()`'s existing
     * "absence of `refresh_token` is not an error" handling means that
     * failure mode is otherwise silent (design §3.2's own detailed
     * reasoning).
     *
     * `hd` is sent ONLY as a UI account-chooser optimization hint
     * (`$params['google_domain_hint']`) — Google's own documentation
     * explicitly warns this request-time parameter is never a security
     * control (client-side requests can be modified); it is never
     * trusted for anything security-relevant anywhere in this class —
     * only the ID token's OWN `hd` CLAIM (`decodeAndValidateIdToken()`
     * below) is ever treated as trustworthy.
     *
     * `code_challenge`/`code_challenge_method` are sent unconditionally
     * (Checkpoint 1's `IntegrationOAuthStateService` generates PKCE for
     * every provider with no per-provider opt-out) — design §3.6's
     * disclosed, unresolved item: Google's own web-server-flow
     * documentation does not list these among its documented parameters
     * for this flow; sending them is expected to be harmless but is not
     * empirically confirmed against Google's real sandbox by this
     * implementation pass.
     */
    public function authorizationUrl(array $params): string
    {
        $clientId = (string) config('integrations.oauth_apps.googleworkspace.client_id');

        $query = http_build_query(array_filter([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => (string) ($params['redirect_uri'] ?? ''),
            'scope' => (string) ($params['scope'] ?? ''),
            'state' => (string) ($params['state'] ?? ''),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'code_challenge' => (string) ($params['code_challenge'] ?? ''),
            'code_challenge_method' => (string) ($params['code_challenge_method'] ?? 'S256'),
            'hd' => $params['google_domain_hint'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== ''));

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$query;
    }

    /**
     * Requires $context['connection'] to be a FirmIntegration instance —
     * guaranteed present by ProviderConnectionService::finishCallback(),
     * identical precondition to Microsoft365Provider's own. `formEncoded:
     * true` — Google's token endpoint is RFC 6749 §4.1.3-compliant,
     * exactly like Microsoft's (checkpoint3-design-oauth-capabilities.md
     * §3.3).
     *
     * `external_account_id` maps to the ID token's `sub` claim ("An
     * identifier for the user, unique among all Google accounts" —
     * Google's own OpenID Connect documentation). `tenant_id` maps to
     * the `hd` (hosted domain) claim, Google's analog to Microsoft's
     * `tid` — MAY legitimately be null for a personal @gmail.com
     * account (not part of any Workspace org); `finishCallback()`'s
     * existing null-tolerant capture branch already handles this with
     * zero framework-level change (design §3.1).
     */
    public function exchangeCodeForToken(string $code, array $context): array
    {
        $connection = $context['connection'] ?? null;

        if (! $connection instanceof FirmIntegration) {
            throw new InvalidArgumentException(
                'GoogleWorkspaceProvider::exchangeCodeForToken() requires $context[\'connection\'] to be a FirmIntegration instance.'
            );
        }

        $clientId = (string) config('integrations.oauth_apps.googleworkspace.client_id');
        $clientSecret = (string) config('integrations.oauth_apps.googleworkspace.client_secret');

        $body = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => (string) ($context['redirect_uri'] ?? ''),
            'code_verifier' => (string) ($context['code_verifier'] ?? ''),
        ];

        $tokenBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'token');

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::GoogleWorkspace,
            method: 'POST',
            url: "{$tokenBase}/token",
            capability: 'oauth_connect',
            operationType: 'token_exchange',
            direction: SyncDirection::Outbound,
            resourceType: null,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'oauth_code_exchange:'.$connection->id.':'.hash('sha256', $code),
            body: $body,
            formEncoded: true,
            urlPurpose: 'token',
        );

        $claims = $this->decodeAndValidateIdToken($response->json['id_token'] ?? null, $clientId);

        return [
            'access_token' => $response->json['access_token'] ?? null,
            'refresh_token' => $response->json['refresh_token'] ?? null,
            'token_type' => $response->json['token_type'] ?? 'bearer',
            'expires_in' => $response->json['expires_in'] ?? null,
            'scope' => $response->json['scope'] ?? '',
            'external_account_id' => $claims['sub'] ?? null,
            'tenant_id' => $claims['hd'] ?? null,
        ];
    }

    /**
     * Same shape as exchangeCodeForToken()'s HTTP call, `grant_type=refresh_token`.
     * Deliberately NO `scope` parameter (unlike Microsoft's refresh
     * call) — Google's refresh grant does not accept a caller-supplied
     * scope re-assertion (checkpoint3-design-oauth-capabilities.md
     * §3.4). `refresh_token` in the response is USUALLY absent — Google's
     * refresh tokens are long-lived by design and a refresh-grant
     * response typically does not include a new one;
     * `ProviderConnectionService::refreshConnectionToken()`'s existing
     * `isset($tokenSet['refresh_token'])` conditional-rotate branch
     * already handles this correctly with zero code change (the branch
     * simply almost never fires for Google, vs. firing on every
     * Microsoft refresh).
     */
    public function refreshToken(string $refreshToken, array $context = []): array
    {
        $connection = $this->resolveConnectionFromContext($context);

        // DETERMINISTIC REFRESH-CYCLE IDENTITY (see
        // oauthRefreshCycleToken()'s own docblock). Computed BEFORE the
        // call, from state the refresh itself is about to replace.
        $refreshCycleToken = $this->oauthRefreshCycleToken($connection);

        $clientId = (string) config('integrations.oauth_apps.googleworkspace.client_id');
        $clientSecret = (string) config('integrations.oauth_apps.googleworkspace.client_secret');

        $body = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ];

        $tokenBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'token');

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::GoogleWorkspace,
            method: 'POST',
            url: "{$tokenBase}/token",
            capability: 'oauth_refresh',
            operationType: 'refresh_token',
            direction: SyncDirection::Outbound,
            resourceType: null,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'oauth_refresh:'.$connection->id.':'.$refreshCycleToken,
            body: $body,
            formEncoded: true,
            urlPurpose: 'token',
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
     * scope bundle, identical discipline to Microsoft365Provider's own.
     * Always includes the baseline openid/email scopes (required to
     * receive an ID token carrying `sub`/`hd` at all).
     *
     * @param  array<string, mixed>  $context
     * @return string[]
     */
    public function requiredScopes(array $context = []): array
    {
        $capabilities = $context['requested_capabilities'] ?? [];

        if (! is_array($capabilities) || $capabilities === []) {
            throw new InvalidArgumentException(
                'GoogleWorkspaceProvider::requiredScopes() requires a non-empty requested_capabilities context — '.
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

        return array_values(array_unique($scopes));
    }

    /**
     * @return array<string, string[]>
     */
    public function capabilityScopeMap(): array
    {
        return self::capabilityScopeBundles();
    }

    /**
     * Binding scope bundles, per checkpoint3-combined-design.md §4.2
     * (sourced live from developers.google.com by the OAuth design
     * pass): `gmail.readonly` (Restricted) + `gmail.send` (Sensitive)
     * for Message; `calendar.events` (Sensitive, superset of
     * `.readonly`, single bundle) for CalendarEvent; `drive.file`
     * (Non-sensitive by DELIBERATE choice, not `drive.readonly`) for
     * Document — `drive.file` avoids Drive's Restricted-scope CASA gate
     * entirely and grants only per-file access, a disclosed
     * product/UX trade-off the design carries forward as an open item
     * for product confirmation, not silently resolved.
     *
     * @return array<string, string[]>
     */
    private static function capabilityScopeBundles(): array
    {
        return [
            ResourceType::Message->value => [
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
            ],
            ResourceType::CalendarEvent->value => [
                'https://www.googleapis.com/auth/calendar.events',
            ],
            ResourceType::Document->value => [
                'https://www.googleapis.com/auth/drive.file',
            ],
        ];
    }

    /**
     * @return string[]
     */
    private static function baselineScopes(): array
    {
        return ['openid', 'email'];
    }

    // ---------------------------------------------------------------
    // SupportsDisconnectContract
    // ---------------------------------------------------------------

    /**
     * Google, unlike Microsoft, exposes a real self-service revoke
     * endpoint (`POST https://oauth2.googleapis.com/revoke`). Revokes
     * the refresh token specifically when available (falling back to
     * the access token), the standard OAuth2 practice for a durable
     * "disconnect this grant" action — the access token would expire on
     * its own shortly regardless. `operationType: 'token_exchange'`
     * (reused, not a dedicated `'disconnect'` operation type — none
     * exists in `ProviderRequestExecutor::SUPPORTED_OPERATION_TYPES`,
     * and this call's shape — POST, form-encoded, no bearer auth —
     * matches `token_exchange`'s existing semantics closely enough not
     * to warrant widening that list, checkpoint3-design-oauth-capabilities.md
     * §6).
     *
     * Fully best-effort, per `ProviderConnectionService::disconnect()`'s
     * existing, unmodified discipline: local teardown proceeds
     * unconditionally regardless of whether this call succeeds. Returns
     * `false` (never throws) when there is nothing to revoke.
     */
    public function revokeAtProvider(array $context): bool
    {
        $connection = $this->resolveConnectionFromContext($context);

        $credential = $this->credentials->findActiveCredential($connection, CredentialType::OauthRefreshToken)
            ?? $this->credentials->findActiveCredential($connection, CredentialType::OauthAccessToken);

        if ($credential === null) {
            return false;
        }

        $tokenPlaintext = $this->credentials->decryptForOperation(
            $connection,
            $credential,
            'googleworkspace oauth_disconnect connection '.$connection->id,
            'oauth_disconnect',
        );

        $tokenBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'token');

        try {
            $response = $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::GoogleWorkspace,
                method: 'POST',
                url: "{$tokenBase}/revoke",
                capability: 'oauth_disconnect',
                operationType: 'token_exchange',
                direction: SyncDirection::Outbound,
                resourceType: null,
                authInjector: fn (PendingRequest $request): PendingRequest => $request,
                // DETERMINISTIC REVOCATION IDENTITY (replaces a
                // `now()->format('YmdHi')` component). Traced caller:
                // `App\Integrations\Services\ProviderConnectionService::disconnect()`
                // (the only `->revokeAtProvider(` call site in app/),
                // which passes `['firm_integration_id' => $fresh->id]`
                // and swallows failures as best-effort — so a user
                // double-click, or a re-entered disconnect after a
                // transient revoke failure, genuinely re-runs this call.
                //
                // `$credential` (already resolved above) is the durable
                // anchor: an `integration_credentials` row id never
                // repeats, and it is exactly what this revocation acts
                // on. It is stable across every re-entry of one failed
                // disconnect (nothing is revoked locally until
                // disconnect() proceeds past this call) and can never
                // wedge a future revocation, because once disconnect()
                // completes, `IntegrationCredentialService::revoke()`
                // flips every credential off Active — so the
                // findActiveCredential() lookup above returns null and
                // this method short-circuits to `false` without ever
                // reaching here again. A later re-connect mints brand
                // new credential rows, hence a brand new key.
                //
                // The credential's own row id is the whole anchor —
                // `credential_type` is deliberately NOT folded in: it is
                // an enum-cast attribute (not a string), and the id
                // already identifies the row uniquely across every type,
                // so adding it would buy nothing and only invite a cast
                // bug.
                usageIdempotencyKey: 'oauth_revoke:'.$connection->id.':'.hash('sha256', implode('|', [
                    (string) $connection->id,
                    (string) $credential->id,
                ])),
                body: ['token' => $tokenPlaintext],
                formEncoded: true,
                urlPurpose: 'token',
            );
        } catch (SanitizedProviderHttpException) {
            // SupportsDisconnectContract::revokeAtProvider()'s own
            // contract: "@return bool whether the provider confirmed
            // revocation" -- ProviderRequestExecutor::send() throws
            // (never returns a non-2xx response object) on any
            // categorized HTTP failure, so a non-200 Google response
            // must be caught HERE and reported as false, fulfilling
            // this method's own bool contract cleanly, rather than
            // relying on ProviderConnectionService::disconnect()'s
            // caller-side try/catch (that catch is legitimate
            // defense-in-depth for a provider that doesn't fully honor
            // this contract, not a substitute for honoring it here).
            return false;
        } finally {
            unset($tokenPlaintext);
        }

        return $response->status === 200;
    }

    // ---------------------------------------------------------------
    // SupportsPullSyncContract
    // ---------------------------------------------------------------

    /**
     * @return string[]
     */
    public function pullableResourceTypes(): array
    {
        return [ResourceType::Message->value, ResourceType::CalendarEvent->value, ResourceType::Document->value];
    }

    /**
     * A new, provider-owned cursor-value prefix convention is used
     * throughout this class's pull methods — `cursor_value` is fully
     * opaque to `PullSyncJob`/`SyncCursorService`, so this is a
     * provider-local pattern, not a framework change
     * (checkpoint3-design-sync-webhooks.md §"headline finding"):
     *
     *   "full:<token-or-empty>"   — full-list enumeration phase
     *   "delta:<token>"           — incremental phase (Gmail: a
     *                               `startHistoryId`, optionally
     *                               pipe-suffixed with an in-progress
     *                               `pageToken` — see pullGmailHistory();
     *                               Calendar: a `syncToken`; Drive: a
     *                               `pageToken`)
     *   "deltapage:<pageToken>"   — Calendar ONLY: mid-pagination within
     *                               either a full or delta cycle, where
     *                               Google's API requires `pageToken`
     *                               to be sent INSTEAD OF `syncToken`
     *                               (the two are mutually exclusive
     *                               request shapes — see pullCalendar()'s
     *                               own docblock for why this differs
     *                               from Gmail's/Drive's simpler
     *                               two-phase schemes).
     *
     * @param  array<string, mixed>  $context
     */
    public function pull(array $context, string $resourceType, ?string $cursor): array
    {
        $connection = $this->resolveConnectionFromContext($context);
        $accessToken = $this->decryptAccessToken($connection, 'pull_sync');

        try {
            return match ($resourceType) {
                ResourceType::Message->value => $this->pullGmail($connection, $accessToken, $cursor),
                ResourceType::CalendarEvent->value => $this->pullCalendar($connection, $accessToken, $cursor),
                ResourceType::Document->value => $this->pullDrive($connection, $accessToken, $cursor),
                default => throw new InvalidArgumentException(
                    "GoogleWorkspaceProvider::pull() does not support resource type \"{$resourceType}\"."
                ),
            };
        } finally {
            unset($accessToken);
        }
    }

    /**
     * Gmail — `users.messages.list` (full) + `users.history.list`
     * (incremental) — a genuine TWO-ENDPOINT shape, unlike Microsoft's
     * single `/delta` endpoint: `history.list` is incremental-only, so a
     * first-time/full sync must come from the separate `messages.list`
     * (checkpoint3-design-sync-webhooks.md §2.1).
     */
    private function pullGmail(FirmIntegration $connection, string $accessToken, ?string $cursor): array
    {
        [$phase, $token] = $this->splitCursor($cursor);

        return $phase === 'delta'
            ? $this->pullGmailHistory($connection, $accessToken, $token)
            : $this->pullGmailFullList($connection, $accessToken, $token);
    }

    private function pullGmailFullList(FirmIntegration $connection, string $accessToken, ?string $pageToken): array
    {
        $gmailBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'gmail');
        $query = $pageToken !== null ? ['pageToken' => $pageToken] : [];

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::GoogleWorkspace,
            method: 'GET',
            url: $gmailBase.'/gmail/v1/users/me/messages',
            capability: 'sync_pull',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Message,
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: 'pull:'.$connection->id.':message:full:'.hash('sha256', (string) $pageToken),
            body: $query,
            urlPurpose: 'gmail',
        );

        $items = $this->mapGmailMessageIds($response->json['messages'] ?? []);
        $nextPageToken = $response->json['nextPageToken'] ?? null;

        if (is_string($nextPageToken) && $nextPageToken !== '') {
            return ['items' => $items, 'next_cursor' => 'full:'.$nextPageToken, 'has_more' => true];
        }

        // Terminal page of the full-list phase — one extra, cheap
        // request to capture the CURRENT historyId as the incremental
        // baseline, the phase transition (analogous to Graph's terminal
        // @odata.deltaLink, but across two different Gmail endpoints).
        $historyId = $this->fetchGmailStartingHistoryId($connection, $accessToken, $pageToken);

        return ['items' => $items, 'next_cursor' => 'delta:'.$historyId, 'has_more' => false];
    }

    /**
     * $pageToken is threaded down from pullGmailFullList() (this
     * method's ONLY caller) purely to give the usage idempotency key a
     * stable identity — replacing a `now()->format('YmdHi')` component.
     *
     * This profile fetch is not a free-standing operation: it happens
     * exactly once, on the TERMINAL page of one full-list enumeration,
     * to capture the incremental baseline. Its logical identity is
     * therefore "the terminal page of THIS enumeration", which is
     * precisely the `(connection, $pageToken)` pair that already keys
     * the `messages.list` call immediately preceding it — so a
     * `PullSyncJob` retry of that same page collapses onto one usage
     * row and one `Idempotency-Key` header instead of minting a fresh
     * pair per attempt. A distinct `:message:profile:` key prefix keeps
     * the two calls separately traceable.
     *
     * DISCLOSED RESIDUAL: for a mailbox small enough to fit in a single
     * page, $pageToken is null on every full enumeration, so a LATER
     * full re-baseline (only reachable after
     * `SyncCursorService::invalidate()` on a CATEGORY_CURSOR_EXPIRED
     * cycle) re-derives the same key and its usage row is deduplicated
     * away rather than recorded again. That is a deliberate trade:
     * `PullSyncJob` threads no run id or cursor_version into `pull()`'s
     * $context (it merges only `'connection'`), so no
     * enumeration-generation discriminator exists at this layer without
     * widening a framework contract this narrow fix does not own.
     * Under-recording one usage row on a rare re-baseline is strictly
     * less harmful than the per-attempt key explosion it replaces.
     */
    private function fetchGmailStartingHistoryId(FirmIntegration $connection, string $accessToken, ?string $pageToken): string
    {
        $gmailBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'gmail');

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::GoogleWorkspace,
            method: 'GET',
            url: $gmailBase.'/gmail/v1/users/me/profile',
            capability: 'sync_pull',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Message,
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: 'pull:'.$connection->id.':message:profile:'.hash('sha256', implode('|', [
                (string) $connection->id,
                'message_full_terminal',
                (string) $pageToken,
            ])),
            urlPurpose: 'gmail',
        );

        return (string) ($response->json['historyId'] ?? '');
    }

    /**
     * Gmail's documented `404` cursor-expiry signal
     * ("If the startHistoryId supplied by your client is outside the
     * available range of history records, the Gmail API returns an
     * HTTP 404... your client must perform a full sync") is caught and
     * remapped to `CATEGORY_CURSOR_EXPIRED` LOCALLY, inside this method
     * — deliberately NOT a change to
     * `ProviderRequestExecutor::categorizeStatus()`'s shared `404` arm,
     * which is already load-bearing for Microsoft's own
     * subscription-renewal 404 handling (checkpoint3-design-sync-webhooks.md
     * §2.2 — remapping it globally would silently break that existing
     * path).
     *
     * DISCLOSED REFINEMENT beyond the frozen design's own §2.1
     * pseudocode: the design's own sample code threads only a bare
     * `startHistoryId` through pullGmailHistory() and does not show how
     * an in-progress `history.list` PAGINATION token (`pageToken`,
     * returned mid-batch as `nextPageToken`) is carried into the NEXT
     * call alongside the still-required `startHistoryId` anchor. This
     * implementation closes that gap with a `"delta:<startHistoryId>"`
     * / `"delta:<startHistoryId>|<pageToken>"` pipe-encoding
     * (splitGmailDeltaCursor() below) so a multi-page history batch
     * resumes correctly rather than silently losing its `startHistoryId`
     * anchor on the second page.
     */
    private function pullGmailHistory(FirmIntegration $connection, string $accessToken, ?string $cursorToken): array
    {
        [$startHistoryId, $pageToken] = $this->splitGmailDeltaCursor($cursorToken);

        if ($startHistoryId === null) {
            throw new InvalidArgumentException(
                'GoogleWorkspaceProvider::pull() reached the Gmail delta phase with no startHistoryId cursor.'
            );
        }

        $query = ['startHistoryId' => $startHistoryId];

        if ($pageToken !== null) {
            $query['pageToken'] = $pageToken;
        }

        $gmailBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'gmail');

        try {
            $response = $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::GoogleWorkspace,
                method: 'GET',
                url: $gmailBase.'/gmail/v1/users/me/history',
                capability: 'sync_pull',
                operationType: 'pull',
                direction: SyncDirection::Inbound,
                resourceType: ResourceType::Message,
                authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
                usageIdempotencyKey: 'pull:'.$connection->id.':message:delta:'.hash('sha256', $startHistoryId.'|'.(string) $pageToken),
                body: $query,
                urlPurpose: 'gmail',
            );
        } catch (SanitizedProviderHttpException $e) {
            if ($e->category() === SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED && $e->statusCode() === 404) {
                throw new SanitizedProviderHttpException(
                    SanitizedProviderHttpException::CATEGORY_CURSOR_EXPIRED,
                    404,
                    'pull',
                    null,
                    $e->correlationId(),
                );
            }

            throw $e;
        }

        $items = $this->mapGmailHistoryItems($response->json['history'] ?? []);
        $nextPageToken = $response->json['nextPageToken'] ?? null;

        if (is_string($nextPageToken) && $nextPageToken !== '') {
            return ['items' => $items, 'next_cursor' => "delta:{$startHistoryId}|{$nextPageToken}", 'has_more' => true];
        }

        // Gmail's own history.list response always echoes the CURRENT
        // historyId on every call, so this is a direct field read, not
        // a second profile-fetch.
        $historyId = (string) ($response->json['historyId'] ?? $startHistoryId);

        return ['items' => $items, 'next_cursor' => 'delta:'.$historyId, 'has_more' => false];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitGmailDeltaCursor(?string $cursorToken): array
    {
        if ($cursorToken === null || $cursorToken === '') {
            return [null, null];
        }

        if (str_contains($cursorToken, '|')) {
            [$startHistoryId, $pageToken] = explode('|', $cursorToken, 2);

            return [$startHistoryId !== '' ? $startHistoryId : null, $pageToken !== '' ? $pageToken : null];
        }

        return [$cursorToken, null];
    }

    /**
     * Deduplicated to one row per distinct message id touched in the
     * batch (Gmail's `History` resource groups changes into
     * `messagesAdded`/`messagesDeleted`/`labelsAdded`/`labelsRemoved`
     * sub-arrays — the same message id may appear in more than one
     * bucket within a single history record), mirroring Microsoft's own
     * disclosed "framework doesn't own resource-specific field mapping"
     * limitation.
     */
    private function mapGmailHistoryItems(mixed $rawHistory): array
    {
        $rawHistory = is_array($rawHistory) ? array_values($rawHistory) : [];
        $items = [];
        $seen = [];

        foreach ($rawHistory as $record) {
            $record = is_array($record) ? $record : [];

            foreach (['messagesAdded', 'messagesDeleted', 'labelsAdded', 'labelsRemoved'] as $bucket) {
                $entries = is_array($record[$bucket] ?? null) ? $record[$bucket] : [];

                foreach ($entries as $entry) {
                    $entry = is_array($entry) ? $entry : [];
                    $message = is_array($entry['message'] ?? null) ? $entry['message'] : [];
                    $messageId = (string) ($message['id'] ?? '');

                    if ($messageId === '' || isset($seen[$messageId])) {
                        continue;
                    }

                    $seen[$messageId] = true;

                    $items[] = [
                        'external_id' => $messageId,
                        'resource_type' => ResourceType::Message->value,
                        'version_token' => null,
                        'removed' => $bucket === 'messagesDeleted',
                    ];
                }
            }
        }

        return $items;
    }

    private function mapGmailMessageIds(mixed $rawMessages): array
    {
        $rawMessages = is_array($rawMessages) ? array_values($rawMessages) : [];

        return array_map(static function ($item): array {
            $item = is_array($item) ? $item : [];

            return [
                'external_id' => (string) ($item['id'] ?? ''),
                'resource_type' => ResourceType::Message->value,
                'version_token' => null,
                'removed' => false,
            ];
        }, $rawMessages);
    }

    /**
     * Google Calendar — `events.list`, the "one endpoint, two modes"
     * shape (closer to Graph's `/delta` than Gmail's/Drive's
     * two-endpoint split). MVP scope: `calendars/primary/events` only
     * (Calendar's API is inherently per-calendar, not account-wide —
     * checkpoint3-design-sync-webhooks.md §3.1).
     *
     * Google's `syncToken` (begin/restart a delta cycle) and
     * `pageToken` (continue pagination mid-batch, within EITHER a full
     * or delta cycle) are MUTUALLY EXCLUSIVE request parameters — unlike
     * Gmail's `startHistoryId`+`pageToken`, which are sent TOGETHER.
     * This is why Calendar alone uses the third `"deltapage:"` cursor
     * phase (see pull()'s own docblock): a `"delta:<token>"` cursor
     * always means "begin fresh with this syncToken"; a
     * `"deltapage:<token>"` cursor always means "continue with this
     * pageToken" — the terminal page of EITHER shape always carries
     * `nextSyncToken` (Google's own documented guarantee), so the next
     * cycle always restarts via `"delta:"` regardless of which phase
     * produced it.
     *
     * `410 Gone` (Google's own documented syncToken-expiry signal)
     * ALREADY propagates through `ProviderRequestExecutor::categorizeStatus()`'s
     * existing Checkpoint-2-added `410` arm as `CATEGORY_CURSOR_EXPIRED`
     * with ZERO new code here — the first real confirmation that
     * generalization was correct (checkpoint3-design-sync-webhooks.md
     * §3.2).
     */
    private function pullCalendar(FirmIntegration $connection, string $accessToken, ?string $cursor): array
    {
        [$phase, $token] = $this->splitCursor($cursor);

        $query = match ($phase) {
            'delta' => $token !== null ? ['syncToken' => $token] : [],
            'deltapage' => $token !== null ? ['pageToken' => $token] : [],
            default => $token !== null ? ['pageToken' => $token] : [],
        };

        $calendarBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'calendar');

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::GoogleWorkspace,
            method: 'GET',
            url: $calendarBase.'/calendar/v3/calendars/primary/events',
            capability: 'sync_pull',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::CalendarEvent,
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: 'pull:'.$connection->id.':calendar_event:'.$phase.':'.hash('sha256', (string) $token),
            body: $query,
            urlPurpose: 'calendar',
        );

        $items = $this->mapCalendarEvents($response->json['items'] ?? []);
        $nextPageToken = $response->json['nextPageToken'] ?? null;

        if (is_string($nextPageToken) && $nextPageToken !== '') {
            return ['items' => $items, 'next_cursor' => 'deltapage:'.$nextPageToken, 'has_more' => true];
        }

        // Terminal page of EITHER a full or incremental cycle —
        // nextSyncToken is always present here per Google's own
        // documented contract.
        $nextSyncToken = $response->json['nextSyncToken'] ?? null;

        return [
            'items' => $items,
            'next_cursor' => (is_string($nextSyncToken) && $nextSyncToken !== '') ? 'delta:'.$nextSyncToken : null,
            'has_more' => false,
        ];
    }

    private function mapCalendarEvents(mixed $rawItems): array
    {
        $rawItems = is_array($rawItems) ? array_values($rawItems) : [];

        return array_map(static function ($item): array {
            $item = is_array($item) ? $item : [];

            return [
                'external_id' => (string) ($item['id'] ?? ''),
                'resource_type' => ResourceType::CalendarEvent->value,
                'version_token' => $item['etag'] ?? null,
                'removed' => ($item['status'] ?? null) === 'cancelled',
            ];
        }, $rawItems);
    }

    /**
     * Google Drive — `files.list` (full) + `changes.list` (incremental)
     * — the same TWO-ENDPOINT shape as Gmail (`changes.list` is
     * incremental-only; `changes.getStartPageToken()` returns a token
     * representing CURRENT state, so a genuine first sync must come
     * from `files.list`, checkpoint3-design-sync-webhooks.md §4.1).
     * Unlike Calendar, Drive's continuation `pageToken` is the SAME
     * parameter name whether beginning a fresh delta cycle
     * (`newStartPageToken` from the prior cycle) or continuing
     * mid-pagination (`nextPageToken`) — no third cursor phase needed.
     */
    private function pullDrive(FirmIntegration $connection, string $accessToken, ?string $cursor): array
    {
        [$phase, $token] = $this->splitCursor($cursor);

        return $phase === 'delta'
            ? $this->pullDriveChanges($connection, $accessToken, $token)
            : $this->pullDriveFullList($connection, $accessToken, $token);
    }

    private function pullDriveFullList(FirmIntegration $connection, string $accessToken, ?string $pageToken): array
    {
        $driveBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'drive');
        $query = $pageToken !== null ? ['pageToken' => $pageToken] : [];

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::GoogleWorkspace,
            method: 'GET',
            url: $driveBase.'/drive/v3/files',
            capability: 'sync_pull',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Document,
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: 'pull:'.$connection->id.':document:full:'.hash('sha256', (string) $pageToken),
            body: $query,
            urlPurpose: 'drive',
        );

        $items = $this->mapDriveFiles($response->json['files'] ?? []);
        $nextPageToken = $response->json['nextPageToken'] ?? null;

        if (is_string($nextPageToken) && $nextPageToken !== '') {
            return ['items' => $items, 'next_cursor' => 'full:'.$nextPageToken, 'has_more' => true];
        }

        $startPageToken = $this->fetchDriveStartPageToken(
            $connection,
            $accessToken,
            'pull:'.$connection->id.':document:start_page_token:'.hash('sha256', implode('|', [
                (string) $connection->id,
                'document_full_terminal',
                (string) $pageToken,
            ])),
        );

        return ['items' => $items, 'next_cursor' => 'delta:'.$startPageToken, 'has_more' => false];
    }

    /**
     * $usageIdempotencyKey is supplied by the caller — replacing a
     * `now()->format('YmdHi')` component that was ALSO hiding a second,
     * independent defect: this method has TWO callers driving two
     * genuinely different logical operations
     * (pullDriveFullList()'s terminal-page baseline capture, and
     * callDriveWatch()'s pre-watch change-stream position fetch), and
     * the old key shape made them indistinguishable — two different
     * operations landing in the same wall-clock minute collided on one
     * usage row, destroying audit correlation between them.
     *
     * Each caller now derives its own stable identity from what it
     * actually holds — the enumeration's $pageToken for the pull path,
     * the watch-cycle token for the watch path (see watchCycleToken())
     * — and passes the finished key here, so the two are both
     * retry-stable and permanently distinguishable from each other.
     */
    private function fetchDriveStartPageToken(FirmIntegration $connection, string $accessToken, string $usageIdempotencyKey): string
    {
        $driveBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'drive');

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::GoogleWorkspace,
            method: 'GET',
            url: $driveBase.'/drive/v3/changes/startPageToken',
            capability: 'sync_pull',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Document,
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: $usageIdempotencyKey,
            urlPurpose: 'drive',
        );

        return (string) ($response->json['startPageToken'] ?? '');
    }

    /**
     * Drive's page-token-expiry error shape is GENUINELY UNDOCUMENTED by
     * Google (confirmed via a targeted fetch of Drive's error-handling
     * guide — no dedicated code the way Gmail's `404`/Calendar's `410`
     * are, only a generic `400 badRequest`,
     * checkpoint3-design-sync-webhooks.md §4.2). Conservative,
     * explicitly-flagged design: remap ONLY a `404` locally (same
     * pattern as Gmail's pullGmailHistory()) — deliberately NOT a
     * generic `400`, which is also the response for a broad class of
     * genuinely-different malformed-request errors and would mask a
     * real caller bug as a false "resync" trigger. THE SINGLE LARGEST
     * UNCONFIRMED ASSUMPTION IN THIS PROVIDER — requires live-API
     * verification before this resource type is enabled against real
     * production Drive traffic.
     */
    private function pullDriveChanges(FirmIntegration $connection, string $accessToken, ?string $pageToken): array
    {
        if ($pageToken === null || $pageToken === '') {
            throw new InvalidArgumentException(
                'GoogleWorkspaceProvider::pull() reached the Drive delta phase with no pageToken cursor.'
            );
        }

        $driveBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'drive');

        try {
            $response = $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::GoogleWorkspace,
                method: 'GET',
                url: $driveBase.'/drive/v3/changes',
                capability: 'sync_pull',
                operationType: 'pull',
                direction: SyncDirection::Inbound,
                resourceType: ResourceType::Document,
                authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
                usageIdempotencyKey: 'pull:'.$connection->id.':document:delta:'.hash('sha256', $pageToken),
                body: ['pageToken' => $pageToken],
                urlPurpose: 'drive',
            );
        } catch (SanitizedProviderHttpException $e) {
            if ($e->category() === SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED && $e->statusCode() === 404) {
                throw new SanitizedProviderHttpException(
                    SanitizedProviderHttpException::CATEGORY_CURSOR_EXPIRED,
                    404,
                    'pull',
                    null,
                    $e->correlationId(),
                );
            }

            throw $e;
        }

        $items = $this->mapDriveChanges($response->json['changes'] ?? []);
        $nextPageToken = $response->json['nextPageToken'] ?? null;

        if (is_string($nextPageToken) && $nextPageToken !== '') {
            return ['items' => $items, 'next_cursor' => 'delta:'.$nextPageToken, 'has_more' => true];
        }

        // Distinct field name from nextPageToken, per Drive's own
        // explicit warning not to conflate them — the terminal page's
        // OWN continuation token for the NEXT cycle.
        $newStartPageToken = (string) ($response->json['newStartPageToken'] ?? $pageToken);

        return ['items' => $items, 'next_cursor' => 'delta:'.$newStartPageToken, 'has_more' => false];
    }

    private function mapDriveFiles(mixed $rawFiles): array
    {
        $rawFiles = is_array($rawFiles) ? array_values($rawFiles) : [];

        return array_map(static function ($item): array {
            $item = is_array($item) ? $item : [];

            return [
                'external_id' => (string) ($item['id'] ?? ''),
                'resource_type' => ResourceType::Document->value,
                'version_token' => $item['version'] ?? null,
                'removed' => false,
            ];
        }, $rawFiles);
    }

    private function mapDriveChanges(mixed $rawChanges): array
    {
        $rawChanges = is_array($rawChanges) ? array_values($rawChanges) : [];

        return array_map(static function ($item): array {
            $item = is_array($item) ? $item : [];
            $file = is_array($item['file'] ?? null) ? $item['file'] : [];

            return [
                'external_id' => (string) ($item['fileId'] ?? ''),
                'resource_type' => ResourceType::Document->value,
                'version_token' => $file['version'] ?? null,
                'removed' => (bool) ($item['removed'] ?? false),
            ];
        }, $rawChanges);
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return ['full', null];
        }

        $parts = explode(':', $cursor, 2);
        $phase = $parts[0] ?? 'full';
        $token = $parts[1] ?? null;

        if (! in_array($phase, ['full', 'delta', 'deltapage'], true)) {
            return ['full', null];
        }

        return [$phase, ($token === '' ? null : $token)];
    }

    // ---------------------------------------------------------------
    // SupportsPushSyncContract
    // ---------------------------------------------------------------

    /**
     * @return string[]
     */
    public function pushableResourceTypes(): array
    {
        return [ResourceType::Message->value, ResourceType::CalendarEvent->value, ResourceType::Document->value];
    }

    /**
     * HONEST LIMITATION, disclosed rather than papered over (mirrors
     * Microsoft365Provider::push()'s identical disclosure verbatim):
     * `App\Jobs\PushSyncJob`'s own $payload today carries ONLY
     * structural/bookkeeping fields — there is no resource-specific
     * field-mapping layer anywhere in this codebase yet that would
     * populate e.g. a calendar event's summary/start/end or a Drive
     * file's name/content into the payload. This method strips the
     * known structural keys and forwards WHATEVER remains as the
     * literal outbound Google API body — it does not invent a
     * business-data mapping the framework does not otherwise provide.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     */
    public function push(array $context, string $resourceType, array $payload): array
    {
        $connection = $this->resolveConnectionFromContext($context);
        $accessToken = $this->decryptAccessToken($connection, 'push_sync');

        try {
            return match ($resourceType) {
                ResourceType::Message->value => $this->pushGmailMessage($connection, $accessToken, $payload),
                ResourceType::CalendarEvent->value => $this->pushCalendarEvent($connection, $accessToken, $payload),
                ResourceType::Document->value => $this->pushDriveFile($connection, $accessToken, $payload),
                default => throw new InvalidArgumentException(
                    "GoogleWorkspaceProvider::push() does not support resource type \"{$resourceType}\"."
                ),
            };
        } finally {
            unset($accessToken);
        }
    }

    /**
     * Gmail has no "update" concept for a sent message — every push is
     * a fresh send via `users.messages.send`, which expects a top-level
     * `{"raw": "<base64url-encoded RFC 5322 message>"}` body — whatever
     * remains of $payload after stripping structural keys is forwarded
     * verbatim, expected to already carry that shape (no field-mapping
     * layer exists to construct it here, per this method's honest
     * limitation).
     */
    private function pushGmailMessage(FirmIntegration $connection, string $accessToken, array $payload): array
    {
        $body = array_diff_key($payload, array_flip(self::PUSH_STRUCTURAL_PAYLOAD_KEYS));

        $gmailBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'gmail');

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::GoogleWorkspace,
            method: 'POST',
            url: $gmailBase.'/gmail/v1/users/me/messages/send',
            capability: 'sync_push',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: ResourceType::Message,
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: $this->pushIdempotencyKey($connection, ResourceType::Message->value, $payload),
            body: $body,
            urlPurpose: 'gmail',
        );

        return [
            'external_id' => (string) ($response->json['id'] ?? ('sent:'.hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)))),
            'version_token' => null,
        ];
    }

    private function pushCalendarEvent(FirmIntegration $connection, string $accessToken, array $payload): array
    {
        $existingExternalId = $this->normalizeExistingExternalId($payload);
        $body = array_diff_key($payload, array_flip(self::PUSH_STRUCTURAL_PAYLOAD_KEYS));

        $calendarBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'calendar');

        $method = $existingExternalId !== null ? 'PATCH' : 'POST';
        $path = $existingExternalId !== null
            ? "/calendar/v3/calendars/primary/events/{$existingExternalId}"
            : '/calendar/v3/calendars/primary/events';

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::GoogleWorkspace,
            method: $method,
            url: $calendarBase.$path,
            capability: 'sync_push',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: ResourceType::CalendarEvent,
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: $this->pushIdempotencyKey($connection, ResourceType::CalendarEvent->value, $payload),
            body: $body,
            urlPurpose: 'calendar',
        );

        $externalId = $response->json['id'] ?? $existingExternalId
            ?? ('sent:'.hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)));

        return [
            'external_id' => (string) $externalId,
            'version_token' => $response->json['etag'] ?? null,
        ];
    }

    private function pushDriveFile(FirmIntegration $connection, string $accessToken, array $payload): array
    {
        $existingExternalId = $this->normalizeExistingExternalId($payload);
        $body = array_diff_key($payload, array_flip(self::PUSH_STRUCTURAL_PAYLOAD_KEYS));

        $driveBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'drive');

        $method = $existingExternalId !== null ? 'PATCH' : 'POST';
        $path = $existingExternalId !== null ? "/drive/v3/files/{$existingExternalId}" : '/drive/v3/files';

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::GoogleWorkspace,
            method: $method,
            url: $driveBase.$path,
            capability: 'sync_push',
            operationType: 'push',
            direction: SyncDirection::Outbound,
            resourceType: ResourceType::Document,
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: $this->pushIdempotencyKey($connection, ResourceType::Document->value, $payload),
            body: $body,
            urlPurpose: 'drive',
        );

        $externalId = $response->json['id'] ?? $existingExternalId
            ?? ('sent:'.hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)));

        return [
            'external_id' => (string) $externalId,
            // Drive v3's `files` resource has no `etag` field the way
            // Graph/Calendar resources do; its own monotonically
            // increasing `version` field is the closest analog.
            'version_token' => $response->json['version'] ?? null,
        ];
    }

    private function normalizeExistingExternalId(array $payload): ?string
    {
        $existingExternalId = $payload['existing_external_id'] ?? null;

        return (is_string($existingExternalId) && $existingExternalId !== '') ? $existingExternalId : null;
    }

    private function pushIdempotencyKey(FirmIntegration $connection, string $resourceType, array $payload): string
    {
        $idempotencyKeyRaw = $payload['idempotency_key'] ?? null;

        if (is_string($idempotencyKeyRaw) && $idempotencyKeyRaw !== '') {
            return $idempotencyKeyRaw;
        }

        return 'push:'.$connection->id.':'.$resourceType.':'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    // ---------------------------------------------------------------
    // SupportsIncrementalSyncContract
    // ---------------------------------------------------------------

    public function supportsIncrementalFor(string $resourceType): bool
    {
        return in_array($resourceType, $this->pullableResourceTypes(), true);
    }

    /**
     * Per checkpoint3-design-sync-webhooks.md §2.3: this contract has
     * zero production callers today — `PullSyncJob` decides
     * delta-vs-full purely by cursor-value presence via
     * `SyncCursorService`, never consulting this method. Implemented
     * here as a correct, honest, currently-dead-code-path read —
     * identical shape to `Microsoft365Provider::incrementalCursorFor()`'s
     * own read-only, `firm_id`+`firm_integration_id`-gated
     * implementation, delegating to
     * `SyncCursorService::firstOrCreate()`/`decryptCursorValue()`.
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
     * The closed set of inbound event vocabulary this provider may
     * emit — the union of every literal value `parseInboundEvent()`
     * below can produce: Google's own `X-Goog-Resource-State` values
     * across Calendar (`sync`, `exists`, `not_exists`) and Drive
     * (`sync`, `add`, `remove`, `update`, `trash`, `untrash`, `change`),
     * plus Gmail's single Pub/Sub-derived `history_changed`.
     *
     * @return string[]
     */
    public function webhookEventTypes(): array
    {
        return ['sync', 'exists', 'not_exists', 'add', 'remove', 'update', 'trash', 'untrash', 'change', 'history_changed'];
    }

    /**
     * Two-branch dispatch on wire shape, discriminated by the presence
     * of `X-Goog-Channel-Token` (checkpoint3-design-sync-webhooks.md
     * §6.1/§6.3):
     *
     *   - Calendar/Drive: `X-Goog-Channel-Token` carries
     *     `$connection->webhook_routing_token` verbatim — byte-for-byte
     *     the same reuse-not-reinvent decision
     *     `Microsoft365Provider::subscribe()` made for Graph's
     *     `clientState`. The routing-token MATCH already performed by
     *     `WebhookConnectionResolverService::resolveConnectionIdentity()`
     *     (called BEFORE this method, on this SAME request) IS the
     *     documented anti-forgery check for this wire shape — returning
     *     `true` here is the honest representation of "verification is
     *     already complete," not a bypass, identical justification to
     *     Microsoft's own `verifyInboundSignature()`.
     *   - Gmail: a real, independently-verifiable OIDC JWT bearer token
     *     in the `Authorization` header (Cloud Pub/Sub's own documented
     *     authenticity mechanism, NOT a shared-secret match) — see
     *     verifyPubSubOidcToken() below.
     */
    public function verifyInboundSignature(string $rawBody, array $headers): bool
    {
        $channelToken = $this->findHeaderCaseInsensitive($headers, 'X-Goog-Channel-Token');

        if (is_string($channelToken) && $channelToken !== '') {
            return true;
        }

        return $this->verifyPubSubOidcToken($this->findHeaderCaseInsensitive($headers, 'Authorization'));
    }

    /**
     * Real OIDC JWT verification via `google/auth`'s
     * `Google\Auth\AccessToken::verify()` — Google's own maintained
     * OIDC/ID-token verifier, NEVER a hand-rolled
     * `openssl_verify`/JWKS implementation, per the human reviewer's
     * binding mandate (checkpoint3-design-sync-webhooks.md §6.3). Every
     * check below is a FAIL-CLOSED AND, not a best-effort scorecard:
     *
     *   1. `Authorization: Bearer ` shape checked BEFORE any part of
     *      the token is parsed.
     *   2. RS256 signature + `aud` + `iss` (default-restricted by the
     *      library itself to `accounts.google.com`/`https://accounts.google.com`,
     *      spot-checked live against the library's real source —
     *      checkpoint3-security-review.md Finding 4) + `exp` freshness
     *      — all enforced INSIDE `AccessToken::verify()`, which throws
     *      (never a soft pass) on any failure.
     *   3. `iss` re-checked here too (defense-in-depth — does not
     *      weaken, never substitutes for, the library's own check).
     *   4. `email` exact match to the configured push-auth
     *      service-account email — `hash_equals()`, matching this
     *      codebase's existing identity-comparison discipline.
     *   5. `email_verified` strictly `=== true`, never merely truthy.
     *   6. `iat` not issued in the future beyond a small, bounded
     *      clock-skew allowance (the library itself does not perform
     *      this half of the freshness check).
     *
     * The decoded `emailAddress` field from the Gmail PAYLOAD (as
     * opposed to this verified JWT) is NEVER itself an authentication
     * signal anywhere in this class — routing only (extractRoutingIdentifier()).
     */
    private function verifyPubSubOidcToken(mixed $authorizationHeader): bool
    {
        if (! is_string($authorizationHeader) || ! str_starts_with($authorizationHeader, 'Bearer ')) {
            return false;
        }

        $rawToken = substr($authorizationHeader, 7);

        // "Bearer " (prefix present, nothing after it) must fail on
        // shape alone too, never reaching the verifier with an empty
        // token string -- str_starts_with() above only proves the
        // prefix is present, not that a token actually follows it.
        if ($rawToken === '') {
            return false;
        }

        try {
            $claims = $this->accessTokenVerifier->verify($rawToken, [
                'audience' => (string) config('integrations.oauth_apps.googleworkspace.pubsub_push_audience'),
            ]);
        } catch (\Throwable) {
            return false;
        }

        if ($claims === false || ! is_array($claims)) {
            return false;
        }

        $serviceAccountEmail = (string) config('integrations.oauth_apps.googleworkspace.pubsub_push_service_account_email');
        $iss = $claims['iss'] ?? null;
        $email = $claims['email'] ?? null;
        $emailVerified = $claims['email_verified'] ?? null;
        $iat = $claims['iat'] ?? null;

        return is_string($iss) && (hash_equals('https://accounts.google.com', $iss) || hash_equals('accounts.google.com', $iss))
            && $serviceAccountEmail !== '' && is_string($email) && hash_equals($serviceAccountEmail, $email)
            && $emailVerified === true
            && is_int($iat) && $iat <= time() + self::CLOCK_SKEW_SECONDS;
    }

    /**
     * Discriminates the THREE wire shapes behind the ONE shared
     * `POST /webhooks/integrations/googleworkspace` route by the
     * presence of `X-Goog-Resource-State` — the single reliable
     * discriminator, since every Calendar/Drive notification carries it
     * (including the one-time `sync` handshake) and no Gmail Pub/Sub
     * delivery ever does (checkpoint3-design-sync-webhooks.md §5.2).
     *
     * @param  array<string, mixed>  $headers
     */
    public function parseInboundEvent(string $rawBody, array $headers): array
    {
        $resourceState = $this->findHeaderCaseInsensitive($headers, 'X-Goog-Resource-State');

        if (is_string($resourceState) && $resourceState !== '') {
            return $this->parseGoogleChannelNotification($resourceState, $headers);
        }

        return $this->parseGmailPubSubNotification($rawBody);
    }

    /**
     * Substring match against Google's `X-Goog-Resource-Uri` — the
     * deliberately narrow, closed mapping this class uses to prefix
     * `event_type` below, directly analogous to
     * `Microsoft365Provider::resourceTypeSegmentFor()`'s own approach
     * against Graph's `resource` field. Never a guess: an unrecognized
     * URI returns null.
     */
    private function resourceTypeSegmentForChannelUri(string $resourceUri): ?string
    {
        $normalized = strtolower($resourceUri);

        return match (true) {
            str_contains($normalized, '/calendar/') => ResourceType::CalendarEvent->value,
            str_contains($normalized, '/drive/') => ResourceType::Document->value,
            default => null,
        };
    }

    /**
     * `event_type` ALWAYS leads with a real `ResourceType` value for a
     * genuine data-change notification (e.g. `"calendar_event:exists"`,
     * `"document:update"`) — satisfying
     * `DispatchPullSyncOnVerifiedWebhookEvent`'s documented contract
     * from the FIRST implementation, applying Checkpoint 2's own
     * found-and-fixed `event_type`-construction bug as a starting
     * constraint (checkpoint3-design-sync-webhooks.md §5.1/§5.2). The
     * one-time, no-op `sync` channel-creation handshake (and any
     * unrecognized resource URI) is deliberately prefixed `lifecycle:`
     * — NEVER leading with a ResourceType value — so
     * `DispatchPullSyncOnVerifiedWebhookEvent::mapEventTypeToResourceType()`
     * correctly skips it rather than firing a wasted pull, mirroring
     * Microsoft's own `lifecycle:` convention for Graph's
     * non-data-change lifecycle notifications.
     */
    private function parseGoogleChannelNotification(string $resourceState, array $headers): array
    {
        $resourceUri = (string) ($this->findHeaderCaseInsensitive($headers, 'X-Goog-Resource-Uri') ?? '');
        $segment = $this->resourceTypeSegmentForChannelUri($resourceUri);

        $eventId = hash('sha256', implode('|', [
            $this->findHeaderCaseInsensitive($headers, 'X-Goog-Channel-Id') ?? '',
            $this->findHeaderCaseInsensitive($headers, 'X-Goog-Resource-Id') ?? '',
            $this->findHeaderCaseInsensitive($headers, 'X-Goog-Message-Number') ?? '',
        ]));

        if ($resourceState === 'sync' || $segment === null) {
            return [
                'event_id' => $eventId,
                'event_type' => $segment === null ? 'lifecycle:unrecognized_channel' : "lifecycle:{$segment}_sync",
                'payload' => ['resource_state' => $resourceState, 'resource_uri' => $resourceUri],
            ];
        }

        return [
            'event_id' => $eventId,
            'event_type' => "{$segment}:{$resourceState}",
            'payload' => ['resource_state' => $resourceState, 'resource_uri' => $resourceUri],
        ];
    }

    /**
     * Pub/Sub's own `message.messageId` is a real, stable,
     * guaranteed-unique per-delivery identifier — no content-fingerprint
     * synthesis needed, unlike Graph's own batched `value[]` items.
     * `event_type` always leads with `ResourceType::Message->value` —
     * a Gmail push notification only ever means "the mailbox changed,"
     * unlike Graph's per-item changeType batches.
     *
     * Rejects (returns the empty `malformed_payload`-shaped result,
     * never throws) a malformed envelope, a missing/malformed
     * `message.data`, an un-parseable base64 payload, or a payload
     * missing EITHER `historyId` or `emailAddress`.
     */
    private function parseGmailPubSubNotification(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        $message = is_array($decoded) ? ($decoded['message'] ?? null) : null;
        $messageId = is_array($message) ? ($message['messageId'] ?? null) : null;

        if (! is_string($messageId) || $messageId === '') {
            return ['event_id' => null, 'event_type' => null, 'payload' => []];
        }

        $payload = $this->decodeGmailPubSubData($rawBody);

        if ($payload === null) {
            return ['event_id' => null, 'event_type' => null, 'payload' => []];
        }

        $historyId = $payload['historyId'] ?? null;
        $emailAddress = $payload['emailAddress'] ?? null;

        if ($historyId === null || ! is_string($emailAddress) || $emailAddress === '') {
            return ['event_id' => null, 'event_type' => null, 'payload' => []];
        }

        return [
            'event_id' => $messageId,
            'event_type' => ResourceType::Message->value.':history_changed',
            'payload' => ['history_id' => $historyId],
        ];
    }

    /**
     * Shared base64url-decode-then-json-decode of Pub/Sub's
     * `message.data` field — used by BOTH extractRoutingIdentifier()
     * (unverified, pre-signature-check routing read) and
     * parseGmailPubSubNotification() (post-verification event parse).
     * Returns null on any malformed shape (not valid JSON envelope, no
     * `message` object, no/empty `message.data`, un-base64url-decodable,
     * or the decoded bytes are not a JSON object) — never throws.
     *
     * @return array<string, mixed>|null
     */
    private function decodeGmailPubSubData(string $rawBody): ?array
    {
        $decoded = json_decode($rawBody, true);
        $message = is_array($decoded) ? ($decoded['message'] ?? null) : null;
        $dataB64 = is_array($message) ? ($message['data'] ?? null) : null;

        if (! is_string($dataB64) || $dataB64 === '') {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($dataB64);

        if ($payloadJson === null) {
            return null;
        }

        $payload = json_decode($payloadJson, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Google's push-channel model has no synchronous validation-echo
     * handshake for ANY of its three sub-APIs (Calendar/Drive's `sync`
     * resource-state message is delivered through the ordinary
     * notification pipeline above, not a special pre-routing challenge;
     * Gmail's Pub/Sub push has no validation-challenge concept at all)
     * — `SupportsWebhooksContract::detectSubscriptionValidationChallenge()`'s
     * own docblock already names "Google, Plaid, TestProvider" as
     * always returning null here.
     *
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, mixed>  $headers
     */
    public function detectSubscriptionValidationChallenge(array $queryParams, array $headers): ?array
    {
        return null;
    }

    /**
     * Calendar/Drive: reads `X-Goog-Channel-Token` directly — resolved
     * via the EXISTING, unmodified `integration_webhook_routing_index`
     * lookup (`WebhookConnectionResolverService::resolveConnectionIdentity()`),
     * zero new writer, zero new column.
     *
     * Gmail: returns the (at this pipeline step, UNVERIFIED) `emailAddress`
     * field decoded from the Pub/Sub envelope — Gmail's push envelope
     * carries no other correlator, and `InboundWebhookController`'s
     * routing step unavoidably runs before its signature-verification
     * step for every provider (checkpoint3-design-sync-webhooks.md
     * §6.2). SEE THIS FILE'S TOP-OF-CLASS DOCBLOCK for the disclosed
     * gap this creates: nothing in this file's scope wires
     * `GmailMailboxRoutingService::resolveByMailbox()` into the
     * consumer of this returned value.
     *
     * @param  array<string, mixed>  $headers
     */
    public function extractRoutingIdentifier(string $rawBody, array $headers): ?string
    {
        $resourceState = $this->findHeaderCaseInsensitive($headers, 'X-Goog-Resource-State');

        if (is_string($resourceState) && $resourceState !== '') {
            $token = $this->findHeaderCaseInsensitive($headers, 'X-Goog-Channel-Token');

            return (is_string($token) && $token !== '') ? $token : null;
        }

        $payload = $this->decodeGmailPubSubData($rawBody);
        $emailAddress = is_array($payload) ? ($payload['emailAddress'] ?? null) : null;

        return (is_string($emailAddress) && $emailAddress !== '') ? $emailAddress : null;
    }

    /**
     * Dispatches to the per-resource-type watch() call, per
     * $context['resource_type'] (a ResourceType value, required) —
     * mirrors exactly what
     * `App\Integrations\Jobs\RenewGraphSubscriptionJob`'s existing
     * 404-triggered fresh-subscribe call site supplies. Reached from
     * BOTH real call sites of `->subscribe(` in this codebase once the
     * parallel writer's Finding-1 fix lands: the new
     * `ProviderConnectionService::bootstrapWebhookSubscriptions()`
     * orchestration (first connect) and
     * `RenewGraphSubscriptionJob.php:176`'s existing 404-triggered
     * re-subscribe fallback (checkpoint3-combined-design.md §4.7) —
     * implementing this method correctly once covers both automatically.
     *
     * @param  array<string, mixed>  $context
     */
    public function subscribe(array $context): array
    {
        $connection = $this->resolveConnectionFromContext($context);

        $resourceType = $context['resource_type'] ?? null;

        if (! is_string($resourceType) || $resourceType === '') {
            throw new InvalidArgumentException("GoogleWorkspaceProvider::subscribe() requires \$context['resource_type'].");
        }

        return match ($resourceType) {
            ResourceType::Message->value => $this->subscribeGmail($connection),
            ResourceType::CalendarEvent->value => $this->subscribeCalendar($connection),
            ResourceType::Document->value => $this->subscribeDrive($connection),
            default => throw new InvalidArgumentException(
                "GoogleWorkspaceProvider::subscribe() does not support resource type \"{$resourceType}\"."
            ),
        };
    }

    /**
     * Requires $context['subscription'] (an
     * IntegrationProviderWebhookSubscription, reading its own
     * `resource_type` column) or a bare $context['resource_type']
     * fallback — mirrors `Microsoft365Provider::renewSubscription()`'s
     * identical dual-acceptance shape. Google's Calendar/Drive channels
     * have NO PATCH-style in-place renewal ("you must replace it with a
     * new one by calling the watch method") — renewal is therefore
     * IDENTICAL IN SHAPE to a first-time subscribe() call for that
     * resource type, so this method simply re-issues the same watch()
     * call, skipping ONLY subscribe()'s own pre-call idempotency check
     * (a renewal must always reach Google fresh).
     *
     * @param  array<string, mixed>  $context
     */
    public function renewSubscription(array $context): array
    {
        $connection = $this->resolveConnectionFromContext($context);

        $subscriptionModel = $context['subscription'] ?? null;
        $resourceType = null;

        if ($subscriptionModel instanceof IntegrationProviderWebhookSubscription) {
            $resourceType = $subscriptionModel->resource_type;
        } elseif (is_string($context['resource_type'] ?? null) && $context['resource_type'] !== '') {
            $resourceType = $context['resource_type'];
        }

        if (! is_string($resourceType) || $resourceType === '') {
            throw new InvalidArgumentException(
                "GoogleWorkspaceProvider::renewSubscription() requires \$context['subscription'] ".
                '(an IntegrationProviderWebhookSubscription with a resource_type) or '.
                "\$context['resource_type']."
            );
        }

        return match ($resourceType) {
            ResourceType::Message->value => $this->callGmailWatch($connection),
            ResourceType::CalendarEvent->value => $this->callCalendarWatch($connection),
            ResourceType::Document->value => $this->callDriveWatch($connection),
            default => throw new InvalidArgumentException(
                "GoogleWorkspaceProvider::renewSubscription() does not support resource type \"{$resourceType}\"."
            ),
        };
    }

    /**
     * FirmsVault's own pre-call idempotency check (mirrors
     * `Microsoft365Provider::subscribe()`'s identical
     * query-before-calling-Google discipline): if an `Active`,
     * non-expired row already exists locally for this
     * (connection, provider_resource), returns it unchanged without
     * ever reaching Google again.
     */
    private function existingActiveSubscription(FirmIntegration $connection, string $providerResource): ?array
    {
        $existing = IntegrationProviderWebhookSubscription::query()
            ->where('firm_integration_id', $connection->id)
            ->where('provider_resource', $providerResource)
            ->where('status', ProviderWebhookSubscriptionStatus::Active->value)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($existing === null) {
            return null;
        }

        return [
            'subscription_id' => $existing->provider_subscription_id,
            'expires_at' => $existing->expires_at?->toIso8601String(),
            'resource' => $existing->provider_resource,
            'change_type' => $existing->provider_change_type,
        ];
    }

    private function subscribeGmail(FirmIntegration $connection): array
    {
        return $this->existingActiveSubscription($connection, 'gmail:me') ?? $this->callGmailWatch($connection);
    }

    /**
     * Gmail's `watch()` request has no token/`clientState`-equivalent
     * field — its authenticity boundary is entirely the inbound Pub/Sub
     * OIDC JWT (verifyPubSubOidcToken() above), never a per-connection
     * value set here. Calls the AUTHENTICATED `users.getProfile`
     * response (never the unverified inbound webhook `emailAddress`)
     * to obtain the mailbox address `GmailMailboxRoutingService::route()`
     * persists — satisfying checkpoint3-combined-design.md §4.7's
     * explicit requirement, on BOTH real call sites of this method
     * (first connect and renewal), automatically, by construction.
     */
    private function callGmailWatch(FirmIntegration $connection): array
    {
        // DETERMINISTIC WATCH-CYCLE IDENTITY (see watchCycleToken()'s
        // own docblock) — shared by BOTH outbound calls this method
        // makes, so the profile read and the watch() call that depend on
        // each other stay correlated across a retry.
        $watchCycleToken = $this->watchCycleToken($connection, 'gmail:me');

        $accessToken = $this->decryptAccessToken($connection, 'webhook_subscribe');

        try {
            $emailAddress = $this->fetchGmailProfileEmailAddress($connection, $accessToken, $watchCycleToken);

            $gmailBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'gmail');
            $topicName = (string) config('integrations.oauth_apps.googleworkspace.gmail_pubsub_topic_name');

            // CHECKPOINT 8.2 (§A7b) — CLAIM BEFORE CALL.
            //
            // The mailbox route used to be written AFTER the watch call
            // below, which meant a cross-firm conflict was discovered only
            // once a real Gmail watch subscription already existed, and the
            // only way to "undo" it was to roll back the caller's
            // transaction — compensating with a rollback for a provider
            // call that had already happened, while the subscription lived
            // on at Google.
            //
            // The claim now runs first, as one short autocommitted
            // statement (see GmailMailboxRoutingService::route()). A
            // mailbox already owned by another connection throws
            // GmailMailboxAlreadyRoutedException HERE, before a single
            // request leaves this process — zero provider calls, nothing to
            // compensate for, and the existing owner untouched.
            //
            // No transaction is opened around any of this: the claim
            // commits on its own, and the network call below runs outside
            // every transaction.
            $this->mailboxRouting->route($connection, $emailAddress);

            $response = $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::GoogleWorkspace,
                method: 'POST',
                url: $gmailBase.'/gmail/v1/users/me/watch',
                capability: 'webhooks',
                operationType: 'webhook_subscribe',
                direction: SyncDirection::Inbound,
                resourceType: ResourceType::Message,
                authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
                usageIdempotencyKey: 'webhook_subscribe:'.$connection->id.':gmail:'.$watchCycleToken,
                body: ['topicName' => $topicName],
                urlPurpose: 'gmail',
            );
        } finally {
            unset($accessToken);
        }

        return [
            'subscription_id' => 'gmail-watch:'.$connection->id,
            'expires_at' => $this->msEpochToIso8601($response->json['expiration'] ?? null),
            'resource' => 'gmail:me',
            'change_type' => null,
        ];
    }

    /**
     * $watchCycleToken is threaded down from callGmailWatch() (this
     * method's ONLY caller) purely to give the usage idempotency key a
     * stable identity — replacing a `now()->format('YmdHi')` component.
     * This profile read is not a free-standing operation: it exists
     * solely to obtain the mailbox address for the watch() call that
     * immediately follows it in the same method, so it belongs to the
     * same logical watch cycle and must share its identity. A distinct
     * `:gmail_profile:` key prefix keeps the two calls separately
     * traceable.
     */
    private function fetchGmailProfileEmailAddress(FirmIntegration $connection, string $accessToken, string $watchCycleToken): string
    {
        $gmailBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'gmail');

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::GoogleWorkspace,
            method: 'GET',
            url: $gmailBase.'/gmail/v1/users/me/profile',
            capability: 'webhooks',
            operationType: 'webhook_subscribe',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Message,
            authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
            usageIdempotencyKey: 'webhook_subscribe:'.$connection->id.':gmail_profile:'.$watchCycleToken,
            urlPurpose: 'gmail',
        );

        $emailAddress = $response->json['emailAddress'] ?? null;

        if (! is_string($emailAddress) || $emailAddress === '') {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE,
                null,
                'webhook_subscribe',
            );
        }

        return $emailAddress;
    }

    private function subscribeCalendar(FirmIntegration $connection): array
    {
        return $this->existingActiveSubscription($connection, 'calendar:primary') ?? $this->callCalendarWatch($connection);
    }

    /**
     * `X-Goog-Channel-Token` is set to
     * `$connection->webhook_routing_token` verbatim (§6.1) — requires
     * webhook routing to already be enabled, mirroring
     * `Microsoft365Provider::subscribe()`'s identical precondition
     * check. `expiration` is requested explicitly at this class's own
     * conservative placeholder ceiling (CALENDAR_WATCH_TTL_SECONDS —
     * see its own docblock) rather than relying on Google's shorter
     * default.
     */
    private function callCalendarWatch(FirmIntegration $connection): array
    {
        if ($connection->webhook_routing_token === null) {
            throw new RuntimeException(
                "GoogleWorkspaceProvider::subscribe() requires connection {$connection->id} to already have webhook routing enabled (webhook_routing_token is null)."
            );
        }

        $accessToken = $this->decryptAccessToken($connection, 'webhook_subscribe');

        $calendarBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'calendar');
        $channelId = (string) Str::uuid();
        $notificationUrl = route('integrations.webhooks.inbound', ['provider' => ProviderKey::GoogleWorkspace->value], true);
        $expirationMs = (int) round((now()->getTimestamp() + self::CALENDAR_WATCH_TTL_SECONDS) * 1000);

        $body = [
            'id' => $channelId,
            'type' => 'web_hook',
            'address' => $notificationUrl,
            'token' => $connection->webhook_routing_token,
            'expiration' => (string) $expirationMs,
        ];

        try {
            $response = $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::GoogleWorkspace,
                method: 'POST',
                url: $calendarBase.'/calendar/v3/calendars/primary/events/watch',
                capability: 'webhooks',
                operationType: 'webhook_subscribe',
                direction: SyncDirection::Inbound,
                resourceType: ResourceType::CalendarEvent,
                authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
                // NOT wall-clock-based, so outside this remediation's
                // scope — but flagged honestly: $channelId is a freshly
                // minted Str::uuid() per call, so this key is
                // random-per-attempt rather than time-per-attempt and
                // has the SAME retry-dedup weakness the wall-clock keys
                // did. Left unchanged deliberately (the channel id is
                // also the real provider-side identity of the channel
                // being created, so changing it is a behavioural
                // decision, not a mechanical one) — see this change's
                // report for the recommended follow-up.
                usageIdempotencyKey: 'webhook_subscribe:'.$connection->id.':calendar:'.$channelId,
                body: $body,
                urlPurpose: 'calendar',
            );
        } finally {
            unset($accessToken);
        }

        return [
            'subscription_id' => $response->json['id'] ?? $channelId,
            'expires_at' => $this->msEpochToIso8601($response->json['expiration'] ?? null)
                ?? $this->msEpochToIso8601($expirationMs),
            'resource' => 'calendar:primary',
            'change_type' => null,
        ];
    }

    private function subscribeDrive(FirmIntegration $connection): array
    {
        return $this->existingActiveSubscription($connection, 'drive:changes') ?? $this->callDriveWatch($connection);
    }

    /**
     * Drive's `changes.watch()` requires a `pageToken` QUERY parameter
     * (never a JSON body field) identifying the change-stream position
     * the watch begins from — fetched fresh via
     * `changes.getStartPageToken()` immediately before every watch()
     * call, exactly mirroring pullDriveFullList()'s own use of the same
     * endpoint. `expiration` is requested explicitly at Drive's own
     * documented `changes` resource ceiling
     * (DRIVE_CHANGES_WATCH_TTL_SECONDS).
     */
    private function callDriveWatch(FirmIntegration $connection): array
    {
        if ($connection->webhook_routing_token === null) {
            throw new RuntimeException(
                "GoogleWorkspaceProvider::subscribe() requires connection {$connection->id} to already have webhook routing enabled (webhook_routing_token is null)."
            );
        }

        // DETERMINISTIC WATCH-CYCLE IDENTITY (see watchCycleToken()).
        // Only the start-page-token fetch below consumes it today — the
        // watch() call itself is still keyed on its own freshly-minted
        // $channelId, see that call site's own note.
        $watchCycleToken = $this->watchCycleToken($connection, 'drive:changes');

        $accessToken = $this->decryptAccessToken($connection, 'webhook_subscribe');

        try {
            $startPageToken = $this->fetchDriveStartPageToken(
                $connection,
                $accessToken,
                'webhook_subscribe:'.$connection->id.':drive_start_page_token:'.$watchCycleToken,
            );

            $driveBase = (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::GoogleWorkspace, 'drive');
            $channelId = (string) Str::uuid();
            $notificationUrl = route('integrations.webhooks.inbound', ['provider' => ProviderKey::GoogleWorkspace->value], true);
            $expirationMs = (int) round((now()->getTimestamp() + self::DRIVE_CHANGES_WATCH_TTL_SECONDS) * 1000);

            $url = $driveBase.'/drive/v3/changes/watch?'.http_build_query(['pageToken' => $startPageToken]);

            $body = [
                'id' => $channelId,
                'type' => 'web_hook',
                'address' => $notificationUrl,
                'token' => $connection->webhook_routing_token,
                'expiration' => (string) $expirationMs,
            ];

            $response = $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::GoogleWorkspace,
                method: 'POST',
                url: $url,
                capability: 'webhooks',
                operationType: 'webhook_subscribe',
                direction: SyncDirection::Inbound,
                resourceType: ResourceType::Document,
                authInjector: fn (PendingRequest $request): PendingRequest => $request->withToken($accessToken),
                // Same disclosed, deliberately-unchanged $channelId
                // caveat as callCalendarWatch()'s own watch() key —
                // see that call site's note.
                usageIdempotencyKey: 'webhook_subscribe:'.$connection->id.':drive:'.$channelId,
                body: $body,
                urlPurpose: 'drive',
            );
        } finally {
            unset($accessToken);
        }

        return [
            'subscription_id' => $response->json['id'] ?? $channelId,
            'expires_at' => $this->msEpochToIso8601($response->json['expiration'] ?? null)
                ?? $this->msEpochToIso8601($expirationMs),
            'resource' => 'drive:changes',
            'change_type' => null,
        ];
    }

    /**
     * Google's `expiration` fields are Unix MILLISECONDS, not an
     * ISO8601 string like Graph's `expirationDateTime` —
     * `RenewGraphSubscriptionJob::extractSubscriptionState()`'s
     * `Carbon::parse($expiresAtRaw)` call is reused unmodified by the
     * parallel writer's own scope, so this class converts Google's
     * epoch-ms value to an ISO8601 string itself before returning it,
     * mirroring how `Microsoft365Provider` already returns ISO8601
     * strings from its own `expirationDateTime` field.
     */
    private function msEpochToIso8601(mixed $raw): ?string
    {
        if (is_string($raw) && ctype_digit($raw)) {
            $raw = (int) $raw;
        }

        if (! is_int($raw)) {
            return null;
        }

        return Carbon::createFromTimestampMs($raw)->toIso8601String();
    }

    // ---------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------

    /**
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
            'GoogleWorkspaceProvider requires $context[\'connection\'] (a FirmIntegration instance) or '.
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
     * SEPARATE `integration_usage_records` row and sent Google a
     * DIFFERENT `Idempotency-Key` header, defeating local usage
     * deduplication, provider-side idempotency, and audit correlation
     * alike.
     *
     * The anchor is the connection's CURRENTLY-Active access-token
     * credential — the structural analogue of the subscription row
     * `App\Integrations\Jobs\RenewGraphSubscriptionJob` anchors its own
     * `$renewalCycleToken` on. `refreshConnectionToken()` reads that
     * credential under `withRefreshLock()` and only calls
     * `IntegrationCredentialService::rotate()` AFTER this method
     * returns successfully; `rotate()` marks the old row `Rotated` and
     * stores a NEW row (new id, new expires_at). So the token is stable
     * across every retry of one refresh and advances exactly once a
     * refresh actually completes.
     *
     * DELIBERATELY DIFFERENT FROM Microsoft365Provider's own
     * `oauthRefreshCycleToken()`, which additionally folds in a hash of
     * the presented refresh token: Microsoft's documented MANDATORY
     * refresh-token rotation makes that value a genuine per-refresh
     * advance signal, whereas Google's refresh tokens are long-lived
     * and its refresh-grant response USUALLY carries no new one (see
     * refreshToken()'s own docblock). Folding Google's effectively
     * immutable refresh token in would therefore contribute a CONSTANT,
     * making the key look stable while providing no advance signal at
     * all — and if the access-credential anchor were ever absent, the
     * key would silently become permanently static, which
     * `integration_usage_records`' `unique(firm_integration_id,
     * idempotency_key)` index would turn into "stop recording usage
     * forever". Anchoring only on the credential that actually rotates
     * keeps the advance signal honest for this provider.
     */
    private function oauthRefreshCycleToken(FirmIntegration $connection): string
    {
        $accessCredential = $this->credentials->findActiveCredential($connection, CredentialType::OauthAccessToken);

        return hash('sha256', implode('|', [
            (string) $connection->id,
            (string) ($accessCredential?->id ?? 'no_access_credential'),
            $accessCredential?->expires_at?->toIso8601String() ?? 'no_expiry',
        ]));
    }

    /**
     * DETERMINISTIC WATCH-CYCLE IDENTITY for the Gmail/Drive watch-path
     * usage idempotency keys (replaces `now()->format('YmdHi')`
     * components).
     *
     * Google's Calendar/Drive channels and Gmail's watch() have no
     * PATCH-style in-place renewal, so a "renewal" here is literally a
     * re-issued watch() call — meaning subscribe() and renewSubscription()
     * share these call paths, and BOTH of their traced callers can
     * genuinely re-run one logical watch:
     * `ProviderConnectionService::bootstrapWebhookSubscriptions()` (a
     * re-entered connect flow) and the retrying
     * `App\Integrations\Jobs\RenewGraphSubscriptionJob` ($tries = 5,
     * backoff() = [30, 60, 120, 240] — so retries reliably cross
     * wall-clock minute boundaries).
     *
     * The anchor is this connection's own most recent webhook
     * subscription row for the given $providerResource, hashed over the
     * same durable triple RenewGraphSubscriptionJob's own
     * `$renewalCycleToken` uses: row id, provider-side subscription id,
     * and expires_at.
     *
     *   - FIRST subscribe: no row exists yet, so every retry of one
     *     failed bootstrap derives the identical 'no_subscription'
     *     token (no row is written until subscribe() actually
     *     succeeds), and the token advances the moment the row is
     *     created.
     *   - RENEWAL: RenewGraphSubscriptionJob updates the EXISTING row in
     *     place, so the row id alone would never advance — `expires_at`
     *     is what carries the advance signal, and it is rewritten only
     *     once a renewal genuinely succeeds. Hence all three fields,
     *     not just the id.
     *
     * Matched to $providerResource (not resource_type) because that is
     * the value `existingActiveSubscription()` in this same class
     * already keys its own pre-call idempotency check on, and the value
     * each callXWatch() returns as `resource`.
     */
    private function watchCycleToken(FirmIntegration $connection, string $providerResource): string
    {
        $latest = IntegrationProviderWebhookSubscription::query()
            ->where('firm_integration_id', $connection->id)
            ->where('provider_resource', $providerResource)
            ->orderByDesc('id')
            ->first();

        return hash('sha256', implode('|', [
            (string) $connection->id,
            $providerResource,
            (string) ($latest?->id ?? 'no_subscription'),
            (string) ($latest?->provider_subscription_id ?? 'no_provider_subscription'),
            $latest?->expires_at?->toIso8601String() ?? 'no_expiry',
        ]));
    }

    /**
     * Decrypts the connection's Active OAuth access-token credential
     * immediately before use — the caller MUST hold the returned
     * plaintext only for the duration of building the auth-injector
     * closure and unset() it as soon as the HTTP call returns, exactly
     * mirroring Microsoft365Provider::decryptAccessToken()'s identical
     * discipline.
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
            'googleworkspace '.$operationSuffix.' connection '.$connection->id,
            $operationSuffix,
        );
    }

    /**
     * Base64url-decodes an ID token's middle (payload) JWS segment only
     * — no independent JWS signature verification is performed here
     * (this trust-boundary reasoning is IDENTICAL to, and explicitly
     * scoped the same way as, Microsoft365Provider::decodeAndValidateIdToken()'s
     * own justification: this ID token arrives directly in the
     * server-to-server HTTPS response body from Google's own token
     * endpoint — back-channel, TLS-authenticated, client-secret-
     * authenticated — never a front-channel-relayed token. Google's own
     * documentation independently confirms the identical reasoning for
     * its own flow, checkpoint3-design-oauth-capabilities.md §4. This
     * reasoning is explicitly NEVER generalized to the Gmail webhook
     * JWT above, which requires real signature verification precisely
     * because that reasoning does not apply there — a structurally
     * different, inbound, attacker-reachable trust boundary).
     *
     * `aud` must equal the configured client_id exactly (`hash_equals()`).
     * `iss` must equal `https://accounts.google.com` OR the bare string
     * `accounts.google.com` (both documented as valid by Google — a
     * fixed two-value check, `hash_equals()` against either literal,
     * never a partial/`str_contains` match). `exp` must not have
     * already passed. Every failure branch throws the same typed
     * `SanitizedProviderHttpException` (category `authentication_failed`)
     * — fail closed, never a soft pass with null/partial claims.
     *
     * @return array<string, mixed>
     */
    private function decodeAndValidateIdToken(mixed $idToken, string $expectedClientId): array
    {
        if (! is_string($idToken) || $idToken === '') {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, null, 'token_exchange');
        }

        $segments = explode('.', $idToken);

        if (count($segments) !== 3) {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, null, 'token_exchange');
        }

        $payloadJson = $this->base64UrlDecode($segments[1]);

        if ($payloadJson === null) {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, null, 'token_exchange');
        }

        $claims = json_decode($payloadJson, true);

        if (! is_array($claims)) {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, null, 'token_exchange');
        }

        $aud = $claims['aud'] ?? null;

        if (! is_string($aud) || ! hash_equals($expectedClientId, $aud)) {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, null, 'token_exchange');
        }

        $iss = $claims['iss'] ?? null;

        if (! is_string($iss) || (! hash_equals('https://accounts.google.com', $iss) && ! hash_equals('accounts.google.com', $iss))) {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, null, 'token_exchange');
        }

        $exp = $claims['exp'] ?? null;

        if (! is_int($exp) || $exp < time()) {
            throw new SanitizedProviderHttpException(SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, null, 'token_exchange');
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
     * Case-insensitive header lookup — Symfony's `HeaderBag` normalizes
     * header names internally and this codebase's own
     * `InboundWebhookController::extractHeaders()` does not guarantee
     * any particular casing to a `SupportsWebhooksContract` implementer,
     * so every header read in this class goes through this helper,
     * mirroring `TestProvider::findHeaderCaseInsensitive()`'s identical,
     * already-established pattern verbatim. A header key that is not a
     * string (e.g. the synthetic `SupportsWebhooksContract::SECRET_CANDIDATES_HEADER_KEY`
     * entry, whose VALUE is an array, never a plain header string) is
     * simply never matched by this loop — this provider has no
     * secret-candidate concept and never reads that key.
     *
     * @param  array<string, mixed>  $headers
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
