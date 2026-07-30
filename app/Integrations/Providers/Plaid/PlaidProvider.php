<?php

declare(strict_types=1);

namespace App\Integrations\Providers\Plaid;

use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\RequiresBillableCallPipelineContract;
use App\Integrations\Contracts\SupportsBalanceContract;
use App\Integrations\Contracts\SupportsDisconnectContract;
use App\Integrations\Contracts\SupportsIncrementalSyncContract;
use App\Integrations\Contracts\SupportsLinkTokenContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Enums\AuthMethod;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationSyncCursor;
use App\Integrations\Models\ProviderBalanceSnapshot;
use App\Integrations\Services\IntegrationCredentialService;
use App\Integrations\Services\SyncCursorService;
use App\Integrations\Support\PlaidItemRoutingService;
use App\Integrations\Support\ProviderEnvironmentResolver;
use App\Integrations\Support\ProviderRequestExecutor;
use App\Services\TenantContextService;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * PlaidProvider — FirmsVault Live Integrations, Checkpoint 4 ("Plaid
 * financial evidence add-on"; checkpoint4-design-plaid-provider-core.md,
 * the frozen design this class implements; checkpoint4-combined-design.md
 * §6, reconciled/binding). The THIRD real (non-simulated) provider
 * adapter in this codebase.
 *
 * Structural template: `App\Integrations\Providers\GoogleWorkspace\GoogleWorkspaceProvider`
 * — this class follows the exact same method-by-method shape wherever
 * the mechanics are analogous (`resolveConnectionFromContext()`/
 * `decryptAccessToken()` helpers, the `Cache`-backed webhook-verification-key
 * lookup pattern, the per-resource-type `pull()` dispatch), and diverges
 * only where Plaid's own API is genuinely different — see each method's
 * own docblock for the specific divergence.
 *
 * Deliberately does NOT implement `SupportsOAuthContract` (Plaid never
 * issues a redirect-based authorization code — see
 * `SupportsLinkTokenContract`'s own docblock) or `SupportsApiKeyContract`
 * (the per-connection identity is obtained via Link-token exchange, not
 * a firm-entered static key — Plaid's `client_id`/`secret` are a single,
 * platform-level credential pair, identical in shape to every other
 * provider's own `oauth_apps.*` config block, not a per-connection API
 * key). Deliberately does NOT implement `SupportsHealthCheckContract`,
 * consistent with both existing real providers.
 *
 * Auth-injection shape — a third distinct pattern in this codebase:
 * Plaid authenticates every call via `client_id`/`secret` (plus, for a
 * per-Item call, the decrypted `access_token`) sent as JSON BODY fields
 * — never an `Authorization` header. Every call below therefore uses a
 * no-op `authInjector` closure and instead merges platform credentials
 * directly into `$body` via `withPlatformCredentials()`.
 *
 * DISCLOSED, NOT FIXED HERE (mirrors both existing real providers' own
 * precedent of disclosing a gap outside a narrow file's scope rather
 * than silently working around it): `downloadStatement()` routes through
 * `ProviderRequestExecutor::send()`, which unconditionally attempts to
 * JSON-decode the response body — `/statements/download`'s real, binary
 * PDF response will therefore currently always fail this call with
 * `CATEGORY_MALFORMED_RESPONSE`. Fixing this requires either a
 * binary-response mode on the shared executor (a framework change
 * touching every other provider) or a disclosed, narrower bypass — both
 * explicitly left open by `checkpoint4-design-plaid-provider-core.md`
 * §15 item 3, not resolved by this file.
 *
 * `RequiresBillableCallPipelineContract` (empty marker) added by the
 * cost-control/provider-core wiring pass (checkpoint4-design-cost-control.md
 * §2.1, resolving Finding 1 of checkpoint4-security-review.md) — every
 * method above is UNCHANGED by that pass (still calls
 * `$this->executor->send(...)` directly, exactly as documented at each
 * call site); this marker only signals to the shared job/service call
 * sites (`PullSyncJob`, `ProviderConnectionService::bootstrapWebhookSubscriptions()`,
 * `RenewGraphSubscriptionJob`) that THEY must additionally route their
 * existing `OutboundProviderHttpClient::execute()` calls through
 * `App\Integrations\Billing\ProviderBillableCallPipeline::execute()`.
 *
 * `SupportsBalanceContract` — added post-wiring-pass, fixing a genuine
 * contract mismatch the wiring pass found and correctly flagged rather
 * than silently improvised past: `fetchBalance()` originally took a
 * caller-decrypted `$accessToken` string (see that method's own,
 * now-superseded docblock reasoning), but `SupportsBalanceContract`'s
 * own docblock is explicit that a provider implementing it "resolves
 * and decrypts its own access credential internally... exactly as
 * every other capability method already does" — the same discipline
 * `pull()`/`revokeAtProvider()` above already follow via
 * `decryptAccessToken()`. The original "let a future cost-control layer
 * gate the decrypt" rationale never materialized: the real
 * `ProviderLiveBalanceConfirmationService` operates entirely above the
 * credential boundary (cooldown/reservation/confirmation) and never
 * touches a token itself. Reverting to the codebase-wide internal-decrypt
 * convention removes an unnecessary plaintext-credential exposure
 * across a method boundary, not just a signature-compatibility fix.
 *
 * DETERMINISTIC `usageIdempotencyKey`s (release-candidate security
 * review, "secondary hardening opportunity" follow-up to
 * checkpoint8-remediation-report.md §"Dispositioned, not a defect"):
 * every one of this class's idempotency keys used to end in
 * `now()->format('YmdHi')` or `now()->getTimestampMs()`. Those keys feed
 * BOTH `ProviderRequestExecutor::send()`'s outbound `Idempotency-Key`
 * header (a real anti-double-charge mechanism on Plaid's own side) and
 * `IntegrationUsageRecorderService::recordOnce()`'s local
 * usage-record dedup — and a wall-clock key changes on every retry
 * (every single invocation, for `getTimestampMs()`), so neither
 * mechanism could ever recognize a retry. Each one is now a
 * `hash('sha256', implode('|', [...]))` over STABLE, DURABLE state that
 * changes only once the operation the key represents has actually
 * completed successfully — the identical shape
 * `App\Integrations\Jobs\RenewGraphSubscriptionJob`'s
 * `$renewalCycleToken` and `App\Jobs\PullSyncJob`'s own pull key already
 * established. Each site's own comment names the caller that was traced
 * and the durable row the identity is read from. `createLinkToken()` is
 * the ONE deliberate, documented exception (see its own comment):
 * `/link/token/create` mints a new short-lived artifact per user click
 * and has no idempotency identity to collapse onto.
 */
final class PlaidProvider implements IntegrationProviderContract, RequiresBillableCallPipelineContract, SupportsBalanceContract, SupportsDisconnectContract, SupportsIncrementalSyncContract, SupportsLinkTokenContract, SupportsPullSyncContract, SupportsWebhooksContract
{
    public function __construct(
        private readonly ProviderRequestExecutor $executor,
        private readonly IntegrationCredentialService $credentials,
        private readonly SyncCursorService $cursors,
        private readonly PlaidItemRoutingService $itemRouting,
    ) {}

    // ---------------------------------------------------------------
    // IntegrationProviderContract
    // ---------------------------------------------------------------

    public function key(): ProviderKey
    {
        return ProviderKey::Plaid;
    }

    public function displayName(): string
    {
        return 'Plaid';
    }

    public function description(): string
    {
        return 'Connect bank accounts and financial data (transactions, balances, income, liabilities, investments, statements, identity) via Plaid.';
    }

    public function isConfigured(): bool
    {
        $clientId = config('integrations.oauth_apps.plaid.client_id');
        $secret = config('integrations.oauth_apps.plaid.secret');

        return is_string($clientId) && trim($clientId) !== ''
            && is_string($secret) && trim($secret) !== '';
    }

    /**
     * @return AuthMethod[]
     */
    public function supportedAuthMethods(): array
    {
        return [AuthMethod::LinkToken];
    }

    // ---------------------------------------------------------------
    // SupportsLinkTokenContract
    // ---------------------------------------------------------------

    /**
     * Exactly one of $context['requested_capabilities'] (new Item) or
     * $context['update_access_token'] (update-mode re-authentication of
     * an existing Item) must be present — throws on neither/both, per
     * this contract's own docblock.
     *
     * `user.client_user_id` is a REQUIRED Plaid parameter with no
     * existing analog on this codebase's connection model — the
     * connection's own public `uuid` is used (a stable, non-secret,
     * per-connection identifier, never the internal bigint `id`).
     */
    public function createLinkToken(array $context): array
    {
        $connection = $this->resolveConnectionFromContext($context);

        $hasRequestedCapabilities = array_key_exists('requested_capabilities', $context);
        $hasUpdateAccessToken = array_key_exists('update_access_token', $context);

        if ($hasRequestedCapabilities === $hasUpdateAccessToken) {
            throw new InvalidArgumentException(
                'PlaidProvider::createLinkToken() requires exactly one of $context[\'requested_capabilities\'] '.
                'or $context[\'update_access_token\'].'
            );
        }

        $body = [
            'client_name' => (string) config('app.name', 'FirmsVault'),
            'user' => ['client_user_id' => (string) $connection->uuid],
            'language' => 'en',
            'country_codes' => ['US'],
            'webhook' => (string) config('integrations.oauth_apps.plaid.webhook_url'),
        ];

        if ($hasUpdateAccessToken) {
            $updateAccessToken = $context['update_access_token'];

            if (! is_string($updateAccessToken) || $updateAccessToken === '') {
                throw new InvalidArgumentException(
                    'PlaidProvider::createLinkToken() requires a non-empty $context[\'update_access_token\'].'
                );
            }

            $body['access_token'] = $updateAccessToken;
        } else {
            $requestedCapabilities = $context['requested_capabilities'];

            if (! is_array($requestedCapabilities) || $requestedCapabilities === []) {
                throw new InvalidArgumentException(
                    'PlaidProvider::createLinkToken() requires a non-empty $context[\'requested_capabilities\'] array.'
                );
            }

            $body['products'] = $this->translateCapabilitiesToProducts($requestedCapabilities);
        }

        // DELIBERATELY TIME-BASED — INTENTIONALLY NOT AN IDEMPOTENCY
        // IDENTITY (documented here per the release-candidate security
        // review's own instruction to "document each remaining
        // time-based use that is intentionally not an idempotency
        // identity"; every OTHER wall-clock key in this class was
        // replaced with a deterministic hash of durable state in the
        // same pass).
        //
        // Callers traced: ProviderConnectionService::initiateLinkTokenConnection()
        // and ::initiateLinkTokenUpdateMode(). BOTH are synchronous,
        // user-initiated (a firm user clicking "Connect"/"Reconnect"),
        // wrapped only in OutboundProviderHttpClient::execute() — which
        // contains no retry logic whatsoever — and are NOT routed
        // through ProviderBillableCallPipeline, so no reservation ledger
        // is involved. There is therefore no retry of "the same logical
        // request" to collapse in the first place.
        //
        // All three of those facts re-verified for Checkpoint 8.2 Phase
        // A10: (1) neither `link_token` nor `link_token_create` appears
        // anywhere in ProviderBillingClassifier — its
        // ENDPOINT_CATEGORY_MAP covers only item/transactions/balance/
        // auth/liabilities/identity/identity_match/
        // identity_verification/investments/income/statements/enrich/
        // monitor, and no ProviderBillableCallPipeline call site passes
        // such a product; (2) both call sites above invoke this method
        // inside `$this->httpClient->execute(...)`, never
        // `app(ProviderBillableCallPipeline::class)->execute(...)`; and
        // (3) the only `->reserve(` call site in app/ lives inside
        // ProviderBillableCallPipeline itself, which (2) shows this path
        // never reaches — so no ProviderBillableCallReservation row is
        // ever created for a link-token issuance.
        //
        // More importantly, collapsing would be actively WRONG here.
        // /link/token/create MINTS a new, short-lived, single-use
        // artifact: neither mode persists anything at all (this flow
        // deliberately creates no IntegrationOAuthState row — see
        // initiateLinkTokenConnection()'s own docblock), so there is no
        // durable row whose mutation marks "this link token has been
        // used up." Every user click is genuinely a NEW logical request
        // for a NEW token. A deterministic key would make the outbound
        // Idempotency-Key header identical across two genuinely separate
        // Link launches, inviting Plaid to replay the FIRST (by then
        // expired) link_token and breaking the user's Link session —
        // trading a real functional break for a dedup that has no
        // correct meaning at this endpoint.
        //
        // The mode discriminator IS a real logical distinction (new-Item
        // vs. update-mode re-authentication are two different
        // operations against the same connection) and is folded in
        // deterministically; only the per-issuance component is
        // wall-clock.
        $linkTokenMode = $hasUpdateAccessToken ? 'update_mode' : 'new_item';

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/link/token/create',
            capability: 'link_token_create',
            operationType: 'token_exchange',
            direction: SyncDirection::Outbound,
            resourceType: null,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_link_token_create:'.$connection->id.':'.$linkTokenMode.':'.now()->getTimestampMs(),
            body: $this->withPlatformCredentials($body),
        );

        $linkToken = $response->json['link_token'] ?? null;
        $expiration = $response->json['expiration'] ?? null;

        if (! is_string($linkToken) || $linkToken === '' || ! is_string($expiration) || $expiration === '') {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE, null, 'token_exchange',
            );
        }

        return ['link_token' => $linkToken, 'expiration' => $expiration];
    }

    /**
     * Only called on a NEW-Item connect. `institution_id` is a
     * best-effort, disclosed follow-up (`/item/public_token/exchange`'s
     * own response never includes it) — see
     * bestEffortFetchInstitutionId()'s own docblock.
     */
    public function exchangePublicToken(string $publicToken, array $context): array
    {
        $connection = $this->resolveConnectionFromContext($context);

        if (trim($publicToken) === '') {
            throw new InvalidArgumentException('PlaidProvider::exchangePublicToken() requires a non-empty publicToken.');
        }

        // The stable identity of THIS exchange. Already the (correct,
        // untouched) basis of the exchange call's own key below; hoisted
        // into a variable so the best-effort /item/get follow-up can
        // share the same durable identity instead of a wall clock — the
        // two calls are two halves of ONE logical operation (see
        // bestEffortFetchInstitutionId()'s own docblock). A public_token
        // is single-use and Link-session-scoped, so it is exactly the
        // "changes only once the operation this key represents actually
        // completes" marker the deterministic-key discipline asks for:
        // a retried exchange re-presents the same public_token, while a
        // genuinely new Link session always produces a different one.
        $exchangeIdentity = hash('sha256', $publicToken);

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/item/public_token/exchange',
            capability: 'link_token_exchange',
            operationType: 'token_exchange',
            direction: SyncDirection::Outbound,
            resourceType: null,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_public_token_exchange:'.$connection->id.':'.$exchangeIdentity,
            body: $this->withPlatformCredentials(['public_token' => $publicToken]),
        );

        $accessToken = $response->json['access_token'] ?? null;
        $itemId = $response->json['item_id'] ?? null;

        if (! is_string($accessToken) || $accessToken === '' || ! is_string($itemId) || $itemId === '') {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE, null, 'token_exchange',
            );
        }

        return [
            'access_token' => $accessToken,
            'item_id' => $itemId,
            'institution_id' => $this->bestEffortFetchInstitutionId($connection, $accessToken, $exchangeIdentity),
        ];
    }

    /**
     * `/item/public_token/exchange`'s own response never includes
     * `institution_id` (Plaid does not document one there) — this is a
     * best-effort, disclosed follow-up `/item/get` call so
     * `ProviderConnectionService::finishLinkTokenCallback()`'s
     * `external_tenant_id` capture has a real value on first connect.
     * Never blocks the primary exchange: any failure here is swallowed
     * and simply leaves `institution_id` uncaptured for this attempt
     * (`SupportsLinkTokenContract`'s own docblock already documents this
     * field as optional/MAY).
     *
     * $exchangeIdentity is the caller's own stable identity for the
     * exchange this call is a follow-up to (see exchangePublicToken()'s
     * own hoisted `$exchangeIdentity`) — this private method's ONLY
     * caller, so widening it touches nothing outside this class. It
     * replaces a `now()->getTimestampMs()` idempotency key that changed
     * on literally every invocation, defeating both the outbound
     * `Idempotency-Key` header and local usage-record dedup.
     */
    private function bestEffortFetchInstitutionId(FirmIntegration $connection, string $accessToken, string $exchangeIdentity): ?string
    {
        try {
            $response = $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::Plaid,
                method: 'POST',
                url: $this->baseUrl().'/item/get',
                capability: 'item_get',
                operationType: 'token_exchange',
                direction: SyncDirection::Inbound,
                resourceType: null,
                authInjector: fn (PendingRequest $request): PendingRequest => $request,
                usageIdempotencyKey: 'plaid_item_get:'.$connection->id.':exchange:'.hash('sha256', implode('|', [
                    (string) $connection->id,
                    'public_token_exchange_institution_lookup',
                    $exchangeIdentity,
                ])),
                body: $this->withPlatformCredentials(['access_token' => $accessToken]),
            );
        } catch (SanitizedProviderHttpException) {
            return null;
        }

        $institutionId = $response->json['item']['institution_id'] ?? null;

        return (is_string($institutionId) && $institutionId !== '') ? $institutionId : null;
    }

    /**
     * Plaid `products` vocabulary — translates this codebase's
     * provider-neutral `ResourceType` values into Plaid's own product
     * strings. Unknown/unmapped capability values are silently ignored
     * (never fabricate a product string) — a non-empty resulting list is
     * required regardless.
     *
     * @param  array<int, mixed>  $capabilities
     * @return string[]
     */
    private function translateCapabilitiesToProducts(array $capabilities): array
    {
        $map = [
            ResourceType::BankAccount->value => 'auth',
            ResourceType::Transaction->value => 'transactions',
            ResourceType::Income->value => 'income_verification',
            ResourceType::Liability->value => 'liabilities',
            ResourceType::Investment->value => 'investments',
            ResourceType::Statement->value => 'statements',
            ResourceType::Identity->value => 'identity',
        ];

        $products = [];

        foreach ($capabilities as $capability) {
            if (is_string($capability) && isset($map[$capability])) {
                $products[] = $map[$capability];
            }
        }

        $products = array_values(array_unique($products));

        if ($products === []) {
            throw new InvalidArgumentException(
                'PlaidProvider::createLinkToken() could not translate any requested_capabilities into a Plaid product.'
            );
        }

        return $products;
    }

    // ---------------------------------------------------------------
    // SupportsDisconnectContract
    // ---------------------------------------------------------------

    /**
     * `/item/remove` — a real self-service revoke endpoint. Best-effort,
     * per `ProviderConnectionService::disconnect()`'s existing,
     * unmodified discipline — local teardown proceeds regardless of
     * whether this call succeeds.
     */
    public function revokeAtProvider(array $context): bool
    {
        $connection = $this->resolveConnectionFromContext($context);
        $credential = $this->credentials->findActiveCredential($connection, CredentialType::ProviderAccessToken);

        if ($credential === null) {
            return false;
        }

        $accessToken = $this->credentials->decryptForOperation(
            $connection, $credential, 'plaid oauth_disconnect connection '.$connection->id, 'oauth_disconnect',
        );

        // DETERMINISTIC REVOCATION IDENTITY (replaces
        // `now()->format('YmdHi')`, which changed on every retry that
        // crossed a minute boundary).
        //
        // Caller traced: ProviderConnectionService::disconnect() (the
        // sole caller), which invokes this inside a best-effort
        // try/catch and then immediately revokes every Active credential
        // locally. `/item/remove` removes exactly ONE Plaid Item,
        // identified by exactly ONE access-token credential row — so the
        // credential row's own primary key IS the durable identity of
        // "this specific revocation", already resolved above. It only
        // changes once the operation this key represents has actually
        // completed: a later re-connect mints a brand-new
        // IntegrationCredential (a strictly higher id) and a new Plaid
        // Item, so a subsequent, genuinely different disconnect gets a
        // different key, while any re-attempt of THIS disconnect
        // (same credential, same Item) collapses onto one.
        $revocationIdentity = hash('sha256', implode('|', [
            (string) $connection->id,
            (string) $credential->id,
            (string) ($connection->external_account_id ?? 'no_item_id'),
        ]));

        try {
            $response = $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::Plaid,
                method: 'POST',
                url: $this->baseUrl().'/item/remove',
                capability: 'oauth_disconnect',
                operationType: 'token_exchange',
                direction: SyncDirection::Outbound,
                resourceType: null,
                authInjector: fn (PendingRequest $request): PendingRequest => $request,
                usageIdempotencyKey: 'oauth_revoke:'.$connection->id.':'.$revocationIdentity,
                body: $this->withPlatformCredentials(['access_token' => $accessToken]),
            );
        } catch (SanitizedProviderHttpException) {
            return false;
        } finally {
            unset($accessToken);
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
        return [
            ResourceType::BankAccount->value,
            ResourceType::Transaction->value,
            ResourceType::Income->value,
            ResourceType::Liability->value,
            ResourceType::Investment->value,
            ResourceType::Statement->value,
            ResourceType::Identity->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function pull(array $context, string $resourceType, ?string $cursor): array
    {
        $connection = $this->resolveConnectionFromContext($context);
        $accessToken = $this->decryptAccessToken($connection, 'pull_sync');

        try {
            return match ($resourceType) {
                ResourceType::Transaction->value => $this->pullTransactions($connection, $accessToken, $cursor),
                ResourceType::BankAccount->value => $this->pullBankAccounts($connection, $accessToken),
                ResourceType::Identity->value => $this->pullIdentity($connection, $accessToken),
                ResourceType::Liability->value => $this->pullLiabilities($connection, $accessToken),
                ResourceType::Investment->value => $this->pullInvestments($connection, $accessToken),
                ResourceType::Income->value => $this->pullIncome($connection, $accessToken),
                ResourceType::Statement->value => $this->pullStatements($connection, $accessToken),
                default => throw new InvalidArgumentException(
                    "PlaidProvider::pull() does not support resource type \"{$resourceType}\"."
                ),
            };
        } finally {
            unset($accessToken);
        }
    }

    /**
     * DETERMINISTIC PULL-PAGE IDENTITY — the shared replacement for the
     * `now()->format('YmdHi')` suffix every pull* method below used to
     * carry (release-candidate security review: a wall-clock idempotency
     * key changes on every retry that crosses a minute boundary,
     * defeating BOTH the outbound `Idempotency-Key` header Plaid itself
     * honours and `IntegrationUsageRecorderService::recordOnce()`'s
     * local usage-record dedup).
     *
     * Caller traced: `App\Jobs\PullSyncJob` is the ONLY production
     * caller of `pull()`. Before it ever invokes the provider it has
     * already persisted this operation's stable identity onto a durable
     * row: `SyncCursorService::firstOrCreate()` resolves (or creates)
     * the one `integration_sync_cursors` row for
     * (connection, resource_type, inbound), and `claim()` locks it to
     * the run. This method re-reads that SAME durable row — it never
     * guesses and never invents state of its own.
     *
     * The identity is `(connection, resource_type, cursor_version,
     * page cursor)` — deliberately the same shape as PullSyncJob's OWN
     * already-deterministic billing-pipeline key (`plaid_pull:` …
     * `hash('sha256', [run id, cursor_version, page cursor])`), minus
     * the run id. Omitting the run id is not an oversight, it is the
     * correction that makes retries actually collapse:
     * `SyncCursorService::advance()` clears `locked_by_sync_run_id` at
     * the end of every page, so a run id read back mid-loop is NOT
     * stable across a resumed run, whereas `cursor_version` is —
     * `advance()` is the only thing that bumps it (once per successfully
     * committed page, including the single page of a full-snapshot
     * resource type) and `markFailed()` deliberately leaves it alone.
     *
     * Consequences, all intended:
     *   - the SAME page re-fetched after a crash/redelivery sees an
     *     unchanged `cursor_version` and page cursor → IDENTICAL key;
     *   - page N+1 of the same run has an advanced `cursor_version`
     *     (and, for `/transactions/sync`, a different page cursor) →
     *     DIFFERENT key;
     *   - the next scheduled run of a full-snapshot resource type runs
     *     against a `cursor_version` the previous run advanced →
     *     DIFFERENT key;
     *   - a re-dispatch of a page that has never yet succeeded (the
     *     previous run failed without advancing) is the same logical
     *     provider call and correctly collapses onto the same key;
     *   - each resource type has its own cursor row → never collapsed
     *     with another resource type, and never with another connection.
     *
     * `no_cursor` is only ever reached by a caller that invokes `pull()`
     * without going through `PullSyncJob` (no such production caller
     * exists today — see `SupportsPullSyncContract`). Deliberately a
     * fixed literal rather than a wall-clock fallback: with no durable
     * progress state to key on, collapsing onto one key is the safe
     * direction (it can only ever suppress a duplicate), whereas
     * reintroducing `now()` would silently restore exactly the defect
     * this method exists to remove.
     */
    private function pullOperationIdentity(FirmIntegration $connection, string $resourceType, ?string $pageCursor = null): string
    {
        $cursor = IntegrationSyncCursor::query()
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', $resourceType)
            ->where('sync_direction', SyncDirection::Inbound->value)
            ->first();

        return hash('sha256', implode('|', [
            (string) $connection->id,
            $resourceType,
            $cursor === null ? 'no_cursor' : 'cursor_version:'.$cursor->cursor_version,
            $pageCursor ?? 'initial',
        ]));
    }

    /**
     * `/transactions/sync` — the ONLY truly incremental resource type
     * this provider offers. First call passes `cursor: null` (full
     * history). `removed` transactions (id-only objects) are included as
     * ordinary items carrying a `_removed: true` marker in `raw`, since
     * no generic delete/tombstone signal exists in
     * `SupportsPullSyncContract`'s return shape today.
     */
    private function pullTransactions(FirmIntegration $connection, string $accessToken, ?string $cursor): array
    {
        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/transactions/sync',
            capability: ResourceType::Transaction->value,
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Transaction,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_transactions_sync:'.$connection->id.':'.$this->pullOperationIdentity($connection, ResourceType::Transaction->value, $cursor),
            body: $this->withPlatformCredentials(['access_token' => $accessToken, 'cursor' => $cursor]),
        );

        $items = [];

        foreach (array_merge($response->json['added'] ?? [], $response->json['modified'] ?? []) as $tx) {
            $tx = is_array($tx) ? $tx : [];

            $items[] = [
                'external_id' => (string) ($tx['transaction_id'] ?? ''),
                'version_token' => hash('sha256', json_encode([
                    $tx['amount'] ?? null, $tx['pending'] ?? null, $tx['date'] ?? null,
                    $tx['merchant_name'] ?? null, $tx['personal_finance_category'] ?? null,
                ], JSON_THROW_ON_ERROR)),
                'raw' => $tx,
            ];
        }

        foreach ($response->json['removed'] ?? [] as $tx) {
            $tx = is_array($tx) ? $tx : [];

            $items[] = [
                'external_id' => (string) ($tx['transaction_id'] ?? ''),
                'version_token' => 'removed',
                'raw' => ['_removed' => true] + $tx,
            ];
        }

        return [
            'items' => $items,
            'next_cursor' => $response->json['next_cursor'] ?? null,
            'has_more' => (bool) ($response->json['has_more'] ?? false),
        ];
    }

    /**
     * `/auth/get` — full-snapshot only. `version_token` correlates the
     * `accounts[]` entry with its matching `numbers.ach[]` entry by
     * `account_id`, per the spec's own "hash of account_number/
     * routing_number/verification_status" definition — those two fields
     * live under the response's separate `numbers` object, not on the
     * `accounts[]` item itself.
     */
    private function pullBankAccounts(FirmIntegration $connection, string $accessToken): array
    {
        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/auth/get',
            capability: ResourceType::BankAccount->value,
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::BankAccount,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_auth_get:'.$connection->id.':'.$this->pullOperationIdentity($connection, ResourceType::BankAccount->value),
            body: $this->withPlatformCredentials(['access_token' => $accessToken]),
        );

        $numbersByAccountId = [];

        foreach (($response->json['numbers']['ach'] ?? []) as $number) {
            $number = is_array($number) ? $number : [];
            $accountId = (string) ($number['account_id'] ?? '');

            if ($accountId !== '') {
                $numbersByAccountId[$accountId] = $number;
            }
        }

        $items = [];

        foreach ($response->json['accounts'] ?? [] as $account) {
            $account = is_array($account) ? $account : [];
            $accountId = (string) ($account['account_id'] ?? '');
            $numbers = $numbersByAccountId[$accountId] ?? [];

            $items[] = [
                'external_id' => $accountId,
                'version_token' => hash('sha256', json_encode([
                    $numbers['account'] ?? null,
                    $numbers['routing'] ?? null,
                    $account['verification_status'] ?? null,
                ], JSON_THROW_ON_ERROR)),
                'raw' => $account,
            ];
        }

        return ['items' => $items, 'next_cursor' => null, 'has_more' => false];
    }

    /**
     * `/identity/get` — full-snapshot only. `version_token` hashes the
     * account's whole `owners` array (name/email/phone/address).
     */
    private function pullIdentity(FirmIntegration $connection, string $accessToken): array
    {
        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/identity/get',
            capability: ResourceType::Identity->value,
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Identity,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_identity_get:'.$connection->id.':'.$this->pullOperationIdentity($connection, ResourceType::Identity->value),
            body: $this->withPlatformCredentials(['access_token' => $accessToken]),
        );

        $items = [];

        foreach ($response->json['accounts'] ?? [] as $account) {
            $account = is_array($account) ? $account : [];
            $owners = is_array($account['owners'] ?? null) ? $account['owners'] : [];

            $items[] = [
                'external_id' => (string) ($account['account_id'] ?? ''),
                'version_token' => hash('sha256', json_encode($owners, JSON_THROW_ON_ERROR)),
                'raw' => $account,
            ];
        }

        return ['items' => $items, 'next_cursor' => null, 'has_more' => false];
    }

    /**
     * `/liabilities/get` — full-snapshot only. Plaid's three liability
     * types (`credit`/`mortgage`/`student`) are returned as three
     * separate arrays, never self-identifying their own type on a single
     * field — this method injects a synthetic, top-level
     * `liability_type` key onto each item (a SIBLING of `raw`, never
     * mixed into it) so `FinancialEvidenceMaterializerService` can
     * dispatch correctly without guessing. `raw` itself stays the
     * genuine, unmodified Plaid object.
     */
    private function pullLiabilities(FirmIntegration $connection, string $accessToken): array
    {
        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/liabilities/get',
            capability: ResourceType::Liability->value,
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Liability,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_liabilities_get:'.$connection->id.':'.$this->pullOperationIdentity($connection, ResourceType::Liability->value),
            body: $this->withPlatformCredentials(['access_token' => $accessToken]),
        );

        $liabilities = is_array($response->json['liabilities'] ?? null) ? $response->json['liabilities'] : [];
        $items = [];

        foreach (['credit', 'mortgage', 'student'] as $liabilityType) {
            foreach (($liabilities[$liabilityType] ?? []) as $entry) {
                $entry = is_array($entry) ? $entry : [];

                $items[] = [
                    'external_id' => (string) ($entry['account_id'] ?? ''),
                    'version_token' => hash('sha256', json_encode($entry, JSON_THROW_ON_ERROR)),
                    'raw' => $entry,
                    'liability_type' => $liabilityType,
                ];
            }
        }

        return ['items' => $items, 'next_cursor' => null, 'has_more' => false];
    }

    /**
     * `/investments/holdings/get` + `/investments/transactions/get` —
     * full-snapshot only, merged into one `items` array with a synthetic
     * top-level `record_type` (`holding`|`transaction`) discriminator,
     * mirroring `pullLiabilities()`'s identical convention.
     * `/investments/transactions/get` requires a date range Plaid does
     * not itself default — a conservative, disclosed two-year window is
     * used (not a researched, Plaid-confirmed value).
     */
    private function pullInvestments(FirmIntegration $connection, string $accessToken): array
    {
        $syncIdentity = $this->pullOperationIdentity($connection, ResourceType::Investment->value);

        // Hoisted out of the request body below, where the two bounds
        // used to be two SEPARATE `now()` reads — a pull that happened
        // to straddle a UTC midnight could therefore compute
        // `start_date` from one day and `end_date` from the next. One
        // read, one window, and the SAME two values are folded into this
        // call's idempotency key so the key provably describes the exact
        // window that was actually requested: two genuinely different
        // date ranges can never share a key, and a retry within the same
        // UTC day (the overwhelmingly common case) keeps the identical
        // one.
        $windowStartDate = now()->subYears(2)->toDateString();
        $windowEndDate = now()->toDateString();

        $holdingsResponse = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/investments/holdings/get',
            capability: ResourceType::Investment->value,
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Investment,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_investments_holdings_get:'.$connection->id.':'.$syncIdentity,
            body: $this->withPlatformCredentials(['access_token' => $accessToken]),
        );

        $transactionsResponse = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/investments/transactions/get',
            capability: ResourceType::Investment->value,
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Investment,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_investments_transactions_get:'.$connection->id.':'.hash('sha256', implode('|', [
                $syncIdentity,
                $windowStartDate,
                $windowEndDate,
            ])),
            body: $this->withPlatformCredentials([
                'access_token' => $accessToken,
                'start_date' => $windowStartDate,
                'end_date' => $windowEndDate,
            ]),
        );

        $items = [];

        foreach ($holdingsResponse->json['holdings'] ?? [] as $holding) {
            $holding = is_array($holding) ? $holding : [];

            $items[] = [
                'external_id' => (string) ($holding['security_id'] ?? ''),
                'version_token' => hash('sha256', json_encode([
                    $holding['quantity'] ?? null, $holding['institution_value'] ?? null,
                ], JSON_THROW_ON_ERROR)),
                'raw' => $holding,
                'record_type' => 'holding',
            ];
        }

        foreach ($transactionsResponse->json['investment_transactions'] ?? [] as $tx) {
            $tx = is_array($tx) ? $tx : [];

            $items[] = [
                'external_id' => (string) ($tx['investment_transaction_id'] ?? ''),
                'version_token' => hash('sha256', json_encode([
                    $tx['amount'] ?? null, $tx['price'] ?? null,
                ], JSON_THROW_ON_ERROR)),
                'raw' => $tx,
                'record_type' => 'transaction',
            ];
        }

        return ['items' => $items, 'next_cursor' => null, 'has_more' => false];
    }

    /**
     * `/credit/bank_income/get` — full-snapshot only. Plaid's Bank
     * Income product does not expose one single, confirmed-stable
     * per-stream identifier (a disclosed gap,
     * checkpoint4-design-plaid-provider-core.md §9.3's own table) — the
     * `external_id`/`income_stream_hash` is therefore synthesized from
     * whichever identifying field is actually present
     * (`bank_income_id`, else a hash of the stream object itself), never
     * fabricated.
     */
    private function pullIncome(FirmIntegration $connection, string $accessToken): array
    {
        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/credit/bank_income/get',
            capability: ResourceType::Income->value,
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Income,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_bank_income_get:'.$connection->id.':'.$this->pullOperationIdentity($connection, ResourceType::Income->value),
            body: $this->withPlatformCredentials(['access_token' => $accessToken]),
        );

        $streams = is_array($response->json['bank_income'] ?? null)
            ? $response->json['bank_income']
            : (is_array($response->json['income_streams'] ?? null) ? $response->json['income_streams'] : []);

        $items = [];

        foreach ($streams as $stream) {
            $stream = is_array($stream) ? $stream : [];
            $streamIdentifier = $stream['bank_income_id'] ?? $stream['income_stream_id'] ?? null;

            $incomeStreamHash = (is_string($streamIdentifier) && $streamIdentifier !== '')
                ? hash('sha256', $streamIdentifier)
                : hash('sha256', json_encode($stream, JSON_THROW_ON_ERROR));

            $items[] = [
                'external_id' => $incomeStreamHash,
                'version_token' => hash('sha256', json_encode([
                    $stream['income_category'] ?? ($stream['category'] ?? null),
                    $stream['pay_frequency'] ?? null,
                ], JSON_THROW_ON_ERROR)),
                'raw' => $stream,
            ];
        }

        return ['items' => $items, 'next_cursor' => null, 'has_more' => false];
    }

    /**
     * `/statements/list` — full-snapshot only, US institutions only per
     * Plaid's own documented geographic limitation.
     */
    private function pullStatements(FirmIntegration $connection, string $accessToken): array
    {
        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/statements/list',
            capability: ResourceType::Statement->value,
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Statement,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_statements_list:'.$connection->id.':'.$this->pullOperationIdentity($connection, ResourceType::Statement->value),
            body: $this->withPlatformCredentials(['access_token' => $accessToken]),
        );

        $items = [];

        foreach ($response->json['statements'] ?? [] as $statement) {
            $statement = is_array($statement) ? $statement : [];

            $items[] = [
                'external_id' => (string) ($statement['statement_id'] ?? ''),
                'version_token' => hash('sha256', json_encode([
                    $statement['month'] ?? null,
                    $statement['year'] ?? null,
                    $statement['available_date'] ?? ($statement['availableDate'] ?? null),
                ], JSON_THROW_ON_ERROR)),
                'raw' => $statement,
            ];
        }

        return ['items' => $items, 'next_cursor' => null, 'has_more' => false];
    }

    // ---------------------------------------------------------------
    // Balance — deliberately excluded from SupportsPullSyncContract
    // ---------------------------------------------------------------

    /**
     * `/accounts/balance/get` — real-time, tightly rate-limited, billed
     * per-request per Plaid's own documented guidance against a polling
     * cadence. On-demand-only: never called from `pull()`, never called
     * from a scheduler. Implements `SupportsBalanceContract`: decrypts
     * its own access token internally via `decryptAccessToken()`,
     * exactly like `pull()`/`revokeAtProvider()` above — never a
     * caller-decrypted token across this method's boundary. Filters to
     * the single requested account via Plaid's documented
     * `options.account_ids` request field, matching this contract's
     * one-account-per-call shape.
     */
    public function fetchBalance(FirmIntegration $connection, string $accountId, array $context): array
    {
        $accessToken = $this->decryptAccessToken($connection, 'fetch_balance');

        // DETERMINISTIC LIVE-BALANCE IDENTITY (replaces
        // `now()->getTimestampMs()`, which changed on literally every
        // invocation — the worst of the wall-clock shapes the
        // release-candidate security review flagged).
        //
        // Caller traced: ProviderLiveBalanceConfirmationService::confirm()
        // (the sole caller), which runs this inside
        // ProviderBillableCallPipeline::execute() and — only when the
        // pipeline reports a certain, billable outcome — calls
        // persistBalanceSnapshot(), an updateOrCreate() on
        // provider_balance_snapshots keyed by exactly
        // (firm_integration_id, account_id) that rewrites `retrieved_at`.
        //
        // That `retrieved_at` is therefore the durable "this specific
        // live-balance retrieval has actually completed successfully"
        // marker — structurally the same thing
        // `RenewGraphSubscriptionJob`'s `$renewalCycleToken` uses
        // `integration_provider_webhook_subscriptions.expires_at` for.
        // Every re-attempt of a retrieval that has NOT yet succeeded
        // reads the same (or absent) snapshot and collapses onto one
        // key; the next genuinely new confirmed retrieval reads the
        // snapshot the previous one wrote and gets a different key.
        // The account id is part of the identity, so two different
        // accounts can never share a key.
        //
        // Reading a FORCE-RLS'd table here is safe: every
        // ProviderRequestExecutor::send() call in this class already
        // REQUIRES an active `app.current_firm_id` context to write its
        // own integration_usage_records row (see
        // resolveVerificationKeyWithAttribution()'s docblock for the
        // confirmed defect that proved this), so this read runs under
        // exactly the context the call below already depends on.
        //
        // NOTE for reviewers: the strictly better identity is
        // ProviderLiveBalanceConfirmationService's own single-use
        // `$confirmationToken` (already the basis of that service's
        // deterministic pipeline key). It is not threaded down here
        // because doing so requires editing that service's own
        // `fetchBalance(..., ['connection' => ..., 'account_id' => ...])`
        // context array, which is outside this change's file scope. If
        // it is ever added as `$context['confirmation_token']`, prefer
        // it over the snapshot below.
        $lastConfirmedRetrievalAt = ProviderBalanceSnapshot::query()
            ->where('firm_integration_id', $connection->id)
            ->where('account_id', $accountId)
            ->value('retrieved_at');

        $balanceIdentity = hash('sha256', implode('|', [
            (string) $connection->id,
            $accountId,
            $lastConfirmedRetrievalAt === null
                ? 'no_prior_confirmed_retrieval'
                : (string) $lastConfirmedRetrievalAt,
        ]));

        try {
            $response = $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::Plaid,
                method: 'POST',
                url: $this->baseUrl().'/accounts/balance/get',
                capability: ResourceType::BankAccount->value,
                operationType: 'pull',
                direction: SyncDirection::Inbound,
                resourceType: ResourceType::BankAccount,
                authInjector: fn (PendingRequest $request): PendingRequest => $request,
                usageIdempotencyKey: 'plaid_balance:'.$connection->id.':'.$accountId.':'.$balanceIdentity,
                body: $this->withPlatformCredentials([
                    'access_token' => $accessToken,
                    'options' => ['account_ids' => [$accountId]],
                ]),
            );
        } finally {
            unset($accessToken);
        }

        return $response->json['accounts'] ?? [];
    }

    // ---------------------------------------------------------------
    // Statements — binary download, deliberately outside pull()
    // ---------------------------------------------------------------

    /**
     * `/statements/download` — a binary PDF response, which does not fit
     * `SupportsPullSyncContract`'s JSON-item shape. See this class's own
     * top-of-file docblock for the disclosed, unresolved
     * `ProviderRequestExecutor` JSON-only-response gap this call
     * currently runs into (`checkpoint4-design-plaid-provider-core.md`
     * §15 item 3) — this method still routes through the shared
     * executor rather than bypassing it, so it correctly benefits from
     * rate-limiting/health-recording/usage-metering up to the point
     * where JSON-decoding the binary body fails.
     */
    public function downloadStatement(FirmIntegration $connection, string $accessToken, string $statementId): array
    {
        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/statements/download',
            capability: ResourceType::Statement->value,
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Statement,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_statement_download:'.$connection->id.':'.$statementId,
            body: $this->withPlatformCredentials(['access_token' => $accessToken, 'statement_id' => $statementId]),
        );

        return ['pdf_bytes' => null, 'content_hash' => $response->header('Plaid-Content-Hash')];
    }

    // ---------------------------------------------------------------
    // Identity Match / Enrich — stateless utilities outside the
    // Item/pull/webhook lifecycle
    // ---------------------------------------------------------------

    /**
     * `/identity/match` — operates on caller-supplied identity data
     * against a specific, already-connected Item. Returns Plaid's
     * per-field match-score response verbatim.
     *
     * @param  array<string, mixed>  $callerSuppliedIdentity  legal_name/
     *                                                        phone_number/
     *                                                        email_address/
     *                                                        address, per
     *                                                        Plaid's
     *                                                        documented
     *                                                        request
     *                                                        shape.
     */
    public function matchIdentity(FirmIntegration $connection, string $accessToken, array $callerSuppliedIdentity): array
    {
        // DETERMINISTIC MATCH IDENTITY (replaces
        // `now()->getTimestampMs()`). Callers traced: this method has NO
        // caller anywhere under app/ today — it is a stateless utility
        // deliberately outside the Item/pull/webhook lifecycle (see this
        // method's own docblock), so there is no caller-side run/job/
        // request row to key on and none can be invented.
        //
        // The correct identity is instead intrinsic to the call:
        // `/identity/match` is a pure query — the SAME identity payload
        // scored against the SAME Item is, by definition, the same
        // logical request, and any two different payloads (a different
        // name, phone, email or address) or any two different
        // connections are different logical requests. Retried verbatim
        // it now collapses onto one key instead of minting a fresh one
        // per millisecond.
        //
        // ksort() so a caller that happens to build the array in a
        // different key order still produces the same identity; only
        // a SHA-256 of the payload is ever emitted, never the payload
        // itself (this is PII), matching the hash-the-payload discipline
        // the `version_token` construction in every pull* method above
        // already uses.
        $identityForKey = $callerSuppliedIdentity;
        ksort($identityForKey);

        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/identity/match',
            capability: 'identity_match',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: ResourceType::Identity,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            usageIdempotencyKey: 'plaid_identity_match:'.$connection->id.':'.hash('sha256', implode('|', [
                (string) $connection->id,
                json_encode($identityForKey, JSON_THROW_ON_ERROR),
            ])),
            body: $this->withPlatformCredentials(array_merge(['access_token' => $accessToken], $callerSuppliedIdentity)),
        );

        return $response->json;
    }

    /**
     * `/transactions/enrich` — operates ONLY on caller-supplied
     * transaction data, never on Plaid-connected Item data. The ONLY
     * `PlaidProvider` method that calls the executor without decrypting
     * any credential first — Plaid's `client_id`/`secret` alone
     * authorize this call.
     *
     * @param  array<int, array<string, mixed>>  $transactions  up to 100
     *                                                          caller-supplied
     *                                                          transaction
     *                                                          objects,
     *                                                          per
     *                                                          Plaid's
     *                                                          documented
     *                                                          request
     *                                                          shape.
     */
    public function enrichTransactions(FirmIntegration $connection, array $transactions): array
    {
        $response = $this->executor->send(
            connection: $connection,
            providerKey: ProviderKey::Plaid,
            method: 'POST',
            url: $this->baseUrl().'/transactions/enrich',
            capability: 'enrich',
            operationType: 'pull',
            direction: SyncDirection::Inbound,
            resourceType: null,
            authInjector: fn (PendingRequest $request): PendingRequest => $request,
            // DETERMINISTIC ENRICH IDENTITY (replaces
            // `now()->getTimestampMs()`). Callers traced: like
            // matchIdentity() above, this method has NO caller anywhere
            // under app/ today, and by its own docblock it operates
            // ONLY on caller-supplied transaction data, never on
            // Plaid-connected Item data — so the submitted batch IS the
            // whole logical request, and enrichment is a pure function
            // of it. The same batch resubmitted (the exact shape a
            // retry takes) now reuses one key; any different batch —
            // one more transaction, one changed amount — hashes
            // differently and is correctly treated as a new request.
            usageIdempotencyKey: 'plaid_transactions_enrich:'.$connection->id.':'.hash('sha256', implode('|', [
                (string) $connection->id,
                json_encode($transactions, JSON_THROW_ON_ERROR),
            ])),
            body: $this->withPlatformCredentials(['transactions' => $transactions]),
        );

        return $response->json['enriched_transactions'] ?? [];
    }

    // ---------------------------------------------------------------
    // Identity Verification / Monitor — capability-declared, not
    // implemented (access-gated per Plaid's own documentation)
    // ---------------------------------------------------------------

    /**
     * This class contains ZERO code calling `/identity_verification/*`
     * or `/watchlist_screening/*` — a structural absence, not a stub
     * that silently no-ops. Both products require a Production-access
     * grant beyond standard Sandbox signup
     * (checkpoint4-plaid-official-documentation-research.md §11).
     *
     * @return array<string, string>
     */
    public function declaredUnavailableCapabilities(): array
    {
        return [
            'identity_verification' => 'Requires Plaid Production access (or a sales-arranged Sandbox grant) — not available by default in Sandbox. See checkpoint4-plaid-official-documentation-research.md §11.',
            'monitor' => 'Requires Plaid Production access (or a sales-arranged Sandbox grant); integrating company must be US/Canada/UK-based. See checkpoint4-plaid-official-documentation-research.md §11.',
        ];
    }

    // ---------------------------------------------------------------
    // SupportsIncrementalSyncContract
    // ---------------------------------------------------------------

    public function supportsIncrementalFor(string $resourceType): bool
    {
        return $resourceType === ResourceType::Transaction->value;
    }

    /**
     * Per checkpoint4-design-plaid-provider-core.md §8 (mirroring
     * checkpoint3's identical precedent for GoogleWorkspaceProvider):
     * this contract has zero production callers today — `PullSyncJob`
     * decides delta-vs-full purely by cursor-value presence via
     * `SyncCursorService`, never consulting this method. Implemented
     * here as a correct, honest, currently-dead-code-path read.
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
     * The closed set of BASE inbound event vocabulary this provider may
     * emit — mirrors `Microsoft365Provider::webhookEventTypes()`'s own
     * documentation-level (not literally exhaustive of every dynamic
     * `lifecycle:item_<code>` suffix `parseInboundEvent()` can produce)
     * convention.
     *
     * @return string[]
     */
    public function webhookEventTypes(): array
    {
        return [
            'transaction:sync_updates_available',
            'transaction:recurring_transactions_update',
            'lifecycle:item_error',
            'lifecycle:item_login_repaired',
            'lifecycle:item_new_accounts_available',
            'lifecycle:item_pending_expiration',
            'lifecycle:item_pending_disconnect',
            'lifecycle:item_user_permission_revoked',
            'lifecycle:item_user_account_revoked',
            'lifecycle:item_webhook_update_acknowledged',
            'lifecycle:unrecognized_webhook',
        ];
    }

    /**
     * Plaid's `Plaid-Verification` header JWT (ES256), verified via
     * `firebase/php-jwt` — never a hand-rolled `openssl_verify`/JWKS
     * implementation, per this mission's established discipline against
     * hand-rolled crypto. Every check below is a FAIL-CLOSED AND:
     *
     *   1. `alg` MUST be ES256 before anything else is inspected.
     *   2. The JWK verification key is fetched (cached, re-fetched only
     *      on a `kid` cache-miss — see resolveVerificationKeyWithAttribution()).
     *   3. Real ES256 signature verification via
     *      `Firebase\JWT\JWT::decode()`.
     *   4. `iat` freshness: reject anything more than 5 minutes old.
     *   5. `request_body_sha256`: constant-time compare (`hash_equals()`)
     *      against a SHA-256 of the RAW bytes actually received — never
     *      a re-serialized form.
     */
    public function verifyInboundSignature(string $rawBody, array $headers): bool
    {
        $jwt = $this->findHeaderCaseInsensitive($headers, 'Plaid-Verification');

        if (! is_string($jwt) || $jwt === '') {
            return false;
        }

        $unverifiedHeader = $this->decodeJwtHeaderWithoutVerifying($jwt);

        if ($unverifiedHeader === null) {
            return false;
        }

        if (($unverifiedHeader['alg'] ?? null) !== 'ES256') {
            return false;
        }

        $kid = $unverifiedHeader['kid'] ?? null;

        if (! is_string($kid) || $kid === '') {
            return false;
        }

        $jwk = $this->resolveVerificationKeyWithAttribution($rawBody, $kid);

        if ($jwk === null) {
            return false;
        }

        try {
            $keySet = JWK::parseKeySet(['keys' => [$jwk]]);
            $claims = (array) JWT::decode($jwt, $keySet);
        } catch (Throwable) {
            return false;
        }

        $iat = $claims['iat'] ?? null;

        if (! is_int($iat) || (time() - $iat) > 300) {
            return false;
        }

        $expectedHash = $claims['request_body_sha256'] ?? null;

        if (! is_string($expectedHash) || ! hash_equals($expectedHash, hash('sha256', $rawBody))) {
            return false;
        }

        return true;
    }

    /**
     * `SupportsWebhooksContract::verifyInboundSignature()` deliberately
     * has no connection parameter (a wire-location-agnostic interface
     * constraint shared by every provider), yet the
     * `/webhook_verification_key/get` JWK-fetch call below must route
     * through `ProviderRequestExecutor::send()`, whose signature
     * requires a real `FirmIntegration` for rate-limiting/health-recording
     * attribution — a disclosed, previously-unresolved open item
     * (checkpoint4-design-plaid-provider-core.md §15 item 4). Resolved
     * here, narrowly: independently re-derive the SAME `item_id` identity
     * `WebhookConnectionResolverService::resolveConnectionIdentity()`
     * already resolved moments earlier, via the identical
     * `PlaidItemRoutingService::resolveByItemId()` lookup — not a new
     * trust boundary, only a second read of the same pre-tenant-context,
     * anti-enumeration-safe routing table already consulted for this
     * same request. Fails closed (returns null) on any resolution
     * failure.
     *
     * CONFIRMED DEFECT, FIXED (Checkpoint 4 test-gate;
     * `PlaidWebhookJwtVerificationTest`'s own "SUSPECTED PRODUCTION
     * DEFECT" docblocks document the full empirical reproduction). The
     * earlier shipped code resolved the `FirmIntegration` inside its own
     * short-lived `runWithFirmContext()` call, then invoked the JWK-fetch
     * (whose `ProviderRequestExecutor::send()` writes an
     * `integration_usage_records` row under standard FORCE RLS) AFTER
     * that context had already been torn down — the insert was silently
     * rejected by Postgres, and the resulting exception was swallowed by
     * a blanket `catch (Throwable)`, converting every genuinely valid
     * signature into a rejected one. Fixed by merging the connection
     * lookup and the JWK network fetch into ONE `runWithFirmContext()`
     * scope below. The cache lookup itself still runs FIRST, with no
     * tenant context at all — a cache hit (the common case for a `kid`
     * already seen once) never needs the DB or network path in the first
     * place, so it is unaffected by this fix either way.
     */
    private function resolveVerificationKeyWithAttribution(string $rawBody, string $kid): ?array
    {
        $cacheKey = "plaid_webhook_jwk:{$kid}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $decoded = json_decode($rawBody, true);
        $itemId = is_array($decoded) ? ($decoded['item_id'] ?? null) : null;

        if (! is_string($itemId) || $itemId === '') {
            return null;
        }

        $route = $this->itemRouting->resolveByItemId($itemId);

        if ($route === null) {
            return null;
        }

        return (new TenantContextService)->runWithFirmContext(
            $route->firmId,
            function () use ($route, $kid): ?array {
                $connection = FirmIntegration::query()->where('id', $route->firmIntegrationId)->first();

                if ($connection === null) {
                    return null;
                }

                return $this->fetchVerificationKey($connection, $kid);
            },
        );
    }

    /**
     * The actual JWK network fetch. MUST be called from within an
     * already-active tenant (`app.current_firm_id`) context — its
     * `ProviderRequestExecutor::send()` call writes an
     * `integration_usage_records` row under standard FORCE RLS. The sole
     * caller, `resolveVerificationKeyWithAttribution()`, guarantees this.
     *
     * `rememberForever()`-shaped (no TTL) is deliberate, not an
     * oversight — Plaid documents no rotation cadence, so a cached entry
     * never goes stale by definition; a genuinely new key is picked up
     * transparently the first time its (previously-unseen) `kid` appears
     * and misses the cache. UNLIKE a literal `Cache::rememberForever()`
     * call, a failed fetch is deliberately NEVER written to cache —
     * `Cache::rememberForever()`'s own implementation would permanently
     * cache a `null` result from a merely transient network failure,
     * silently breaking webhook verification for that `kid` forever
     * until the cache is manually cleared.
     */
    private function fetchVerificationKey(FirmIntegration $connection, string $kid): ?array
    {
        try {
            $response = $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::Plaid,
                method: 'POST',
                url: $this->baseUrl().'/webhook_verification_key/get',
                capability: 'webhook_verification',
                operationType: 'webhook_subscribe',
                direction: SyncDirection::Outbound,
                resourceType: null,
                authInjector: fn (PendingRequest $request): PendingRequest => $request,
                usageIdempotencyKey: 'plaid_webhook_jwk:'.$kid,
                body: $this->withPlatformCredentials(['key_id' => $kid]),
            );
        } catch (Throwable) {
            return null;
        }

        $key = $response->json['key'] ?? null;

        if (! is_array($key)) {
            return null;
        }

        Cache::forever("plaid_webhook_jwk:{$kid}", $key);

        return $key;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJwtHeaderWithoutVerifying(string $jwt): ?array
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            return null;
        }

        $decoded = $this->base64UrlDecode($segments[0]);

        if ($decoded === null) {
            return null;
        }

        $header = json_decode($decoded, true);

        return is_array($header) ? $header : null;
    }

    /**
     * Plaid's webhook payload always carries `item_id` in the JSON
     * body — never a header, never a `clientState`-equivalent.
     * `SupportsWebhooksContract::extractRoutingIdentifier()`'s own
     * docblock already names Plaid's `item_id` explicitly as an example
     * of a routing identifier arriving in the JSON body.
     */
    public function parseInboundEvent(string $rawBody, array $headers): array
    {
        $decoded = json_decode($rawBody, true);
        $decoded = is_array($decoded) ? $decoded : [];

        $webhookType = $decoded['webhook_type'] ?? null;
        $webhookCode = $decoded['webhook_code'] ?? null;
        $itemId = $decoded['item_id'] ?? null;

        // Plaid webhooks carry no stable per-delivery id field of their
        // own — a content-fingerprint id, the same category of synthesis
        // Microsoft365Provider::parseInboundEvent() already uses for
        // Graph's own id-less batched notifications.
        //
        // CORRECTED (found during Checkpoint 6's cross-provider webhook
        // idempotency review): an earlier version folded in
        // now()->getTimestampMs() specifically because Plaid CAN
        // legitimately redeliver the identical (webhook_type,
        // webhook_code, item_id) tuple for two genuinely different
        // events in quick succession (e.g. two separate
        // SYNC_UPDATES_AVAILABLE firings). That reasoning was correct,
        // but the fix was wrong in the other direction: a timestamp
        // guarantees every event_id is unique, so
        // InboundWebhookEventService::recordVerifiedEvent()'s own
        // UNIQUE(firm_integration_id, provider_key, provider_event_id)
        // dedup constraint could NEVER recognize a genuine Plaid
        // redelivery (identical raw body, resent because the original
        // response wasn't a timely 2xx) as a duplicate — every retry
        // re-dispatched DispatchPullSyncOnVerifiedWebhookEvent and
        // DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent.
        // Hashing the full raw body instead solves both halves at once:
        // a byte-identical redelivery (the actual shape of a Plaid
        // retry) now produces the same event_id and is correctly
        // deduped, while two genuinely different events — which will
        // differ in body content even when the (webhook_type,
        // webhook_code, item_id) tuple repeats — still produce distinct
        // event_ids, exactly the same "same bytes = same delivery"
        // notion InboundWebhookReceiptService's own body_hash dedup
        // already uses for this identical purpose.
        $eventId = hash('sha256', implode('|', [
            (string) $itemId, (string) $webhookType, (string) $webhookCode, $rawBody,
        ]));

        $eventType = match (true) {
            $webhookType === 'TRANSACTIONS' && $webhookCode === 'SYNC_UPDATES_AVAILABLE' => ResourceType::Transaction->value.':sync_updates_available',
            $webhookType === 'TRANSACTIONS' && $webhookCode === 'RECURRING_TRANSACTIONS_UPDATE' => ResourceType::Transaction->value.':recurring_transactions_update',
            $webhookType === 'TRANSACTIONS' => 'lifecycle:transaction_legacy_'.strtolower((string) $webhookCode),
            $webhookType === 'ITEM' => 'lifecycle:item_'.strtolower((string) $webhookCode),
            default => 'lifecycle:unrecognized_webhook',
        };

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => ['webhook_type' => $webhookType, 'webhook_code' => $webhookCode],
        ];
    }

    /**
     * Plaid registers the webhook URL as an attribute of the Item
     * itself, set via `/link/token/create`'s own `webhook` parameter —
     * there is no separate "create a subscription" endpoint the way
     * Graph's `/subscriptions` or Google's `watch()` have. `subscribe()`
     * therefore creates nothing new; it defensively re-asserts the
     * registration via `/item/webhook/update` (idempotent) and performs
     * the `integration_plaid_item_routes` write.
     *
     * @param  array<string, mixed>  $context
     */
    public function subscribe(array $context): array
    {
        $connection = $this->resolveConnectionFromContext($context);
        $accessToken = $this->decryptAccessToken($connection, 'webhook_subscribe');

        try {
            $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::Plaid,
                method: 'POST',
                url: $this->baseUrl().'/item/webhook/update',
                capability: 'webhook_subscribe',
                operationType: 'webhook_subscribe',
                direction: SyncDirection::Outbound,
                resourceType: null,
                authInjector: fn (PendingRequest $request): PendingRequest => $request,
                usageIdempotencyKey: 'plaid_webhook_update:'.$connection->id,
                body: $this->withPlatformCredentials([
                    'access_token' => $accessToken,
                    'webhook' => (string) config('integrations.oauth_apps.plaid.webhook_url'),
                ]),
            );
        } finally {
            unset($accessToken);
        }

        $itemId = $connection->external_account_id;

        if (is_string($itemId) && $itemId !== '') {
            $this->itemRouting->route($connection, $itemId);
        }

        // Plaid's Item-level webhook registration does not expire the
        // way Graph/Calendar channel subscriptions do — there is no
        // remote-subscription lifetime to report. A conservative,
        // honestly long-dated expires_at satisfies
        // integration_provider_webhook_subscriptions' NOT NULL column,
        // mirroring the same disclosed-placeholder posture
        // Microsoft365Provider/GoogleWorkspaceProvider already carry for
        // their own genuinely-undocumented values.
        return [
            'subscription_id' => is_string($itemId) && $itemId !== '' ? $itemId : ('plaid-item:'.$connection->id),
            'expires_at' => now()->addYears(10)->toIso8601String(),
            'resource' => 'item',
            'change_type' => 'webhook',
        ];
    }

    /**
     * Idempotent re-assert — nothing to actually renew.
     *
     * @param  array<string, mixed>  $context
     */
    public function renewSubscription(array $context): array
    {
        return $this->subscribe($context);
    }

    /**
     * Plaid's push model has no synchronous validation-echo handshake —
     * `SupportsWebhooksContract::detectSubscriptionValidationChallenge()`'s
     * own docblock already names "Google, Plaid, TestProvider" as always
     * returning null here.
     *
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, mixed>  $headers
     */
    public function detectSubscriptionValidationChallenge(array $queryParams, array $headers): ?array
    {
        return null;
    }

    /**
     * Plaid's webhook payload always carries `item_id` in the JSON
     * body — never a header, never a `clientState`-equivalent.
     */
    public function extractRoutingIdentifier(string $rawBody, array $headers): ?string
    {
        $decoded = json_decode($rawBody, true);
        $itemId = is_array($decoded) ? ($decoded['item_id'] ?? null) : null;

        return (is_string($itemId) && $itemId !== '') ? $itemId : null;
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
            'PlaidProvider requires $context[\'connection\'] (a FirmIntegration instance) or '.
            '$context[\'firm_integration_id\'] to resolve the active connection for this operation.'
        );
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 4 test-gate fix ("Plaid
     * financial evidence add-on" §6 — Item error-state handling). The
     * item-lifecycle webhook listener's authoritative source for a Plaid
     * error code, deliberately NOT read out of the raw webhook body: this
     * codebase's own established discipline (see
     * `DispatchPullSyncOnVerifiedWebhookEvent`'s own docblock, "never
     * trust anything carried in the job payload itself") is to
     * re-verify state fresh, from the provider itself, rather than trust
     * webhook-delivered content — and no per-provider payload-field
     * allowlist mechanism exists yet to safely persist/thread a raw
     * `error.error_code` value through the shared, provider-agnostic
     * `InboundWebhookController` in the first place
     * (`InboundWebhookController::handleVerifiedDelivery()`'s own
     * `sanitizedPayloadReference: []` docblock is explicit about this).
     * A real `/item/get` call (Plaid's own documented Item-status
     * endpoint) is the correct, already-precedented alternative — mirrors
     * `bestEffortFetchInstitutionId()`'s identical call shape immediately
     * below. Returns null on any failure or on a genuinely errorless Item
     * (fail-closed: the listener must never guess a error code that was
     * not actually confirmed by Plaid).
     */
    public function fetchItemErrorCode(FirmIntegration $connection, ?int $inboundWebhookEventId = null): ?string
    {
        $accessToken = $this->decryptAccessToken($connection, 'item_error_state');

        // DETERMINISTIC ITEM-STATE IDENTITY (replaces
        // `now()->getTimestampMs()`). This is the ONE site in this class
        // whose caller genuinely retries: the sole caller,
        // DispatchPlaidItemLifecycleTransitionOnVerifiedWebhookEvent, is
        // a ShouldQueue listener with `$tries = 3`, so the old key
        // minted three different values — and three separately-headered
        // outbound calls plus three usage rows — for ONE logical
        // "re-verify this Item's error state" operation.
        //
        // $inboundWebhookEventId is that caller's own durable
        // integration_inbound_webhook_events id — the exact,
        // per-delivery identity this /item/get re-verifies, already
        // carried on the listener as `$this->webhookEventId` and
        // constant across all three of its attempts. Additive and
        // nullable so no call site is forced to change; the listener
        // adopting it is a one-line follow-up
        // (`$provider->fetchItemErrorCode($connection, $this->webhookEventId)`)
        // and is the preferred identity whenever supplied.
        //
        // Fallback when it is not supplied: the connection's own durable
        // lifecycle state, which markItemErrorState() — the very thing
        // this call's result feeds — rewrites (status →
        // ReauthorizationRequired, error_reason → the Plaid error code)
        // once the operation completes successfully. Deliberately does
        // NOT include `updated_at`/`last_health_check_at`: HealthStateService
        // rewrites both on EVERY provider call, so folding them in would
        // silently reintroduce a per-attempt key. Disclosed limitation
        // of the fallback: connection state is cyclic (Active/null →
        // ReauthorizationRequired/code → Active/null after a repair), so
        // two error episodes separated by a successful repair can
        // reuse a key, costing one usage-evidence row on a free,
        // non-mutating Plaid read. That is strictly better than the
        // per-millisecond key it replaces, and disappears entirely once
        // the caller passes the webhook event id.
        $itemStateIdentity = hash('sha256', implode('|', $inboundWebhookEventId !== null
            ? [(string) $connection->id, 'inbound_webhook_event', (string) $inboundWebhookEventId]
            : [
                (string) $connection->id,
                'connection_lifecycle_state',
                $connection->status->value,
                (string) ($connection->error_reason ?? 'no_error_reason'),
            ]));

        try {
            $response = $this->executor->send(
                connection: $connection,
                providerKey: ProviderKey::Plaid,
                method: 'POST',
                url: $this->baseUrl().'/item/get',
                capability: 'item_get',
                operationType: 'health_check',
                direction: SyncDirection::Inbound,
                resourceType: null,
                authInjector: fn (PendingRequest $request): PendingRequest => $request,
                usageIdempotencyKey: 'plaid_item_get:'.$connection->id.':item_state:'.$itemStateIdentity,
                body: $this->withPlatformCredentials(['access_token' => $accessToken]),
            );
        } catch (Throwable) {
            return null;
        } finally {
            unset($accessToken);
        }

        $errorCode = $response->json['error']['error_code'] ?? null;

        return (is_string($errorCode) && $errorCode !== '') ? $errorCode : null;
    }

    /**
     * Decrypts the connection's Active `ProviderAccessToken` credential
     * immediately before use — the caller MUST hold the returned
     * plaintext only for the duration of building the auth-injector
     * closure and unset() it as soon as the HTTP call returns, exactly
     * mirroring `GoogleWorkspaceProvider::decryptAccessToken()`'s
     * identical discipline.
     */
    private function decryptAccessToken(FirmIntegration $connection, string $operationSuffix): string
    {
        $credential = $this->credentials->findActiveCredential($connection, CredentialType::ProviderAccessToken);

        if ($credential === null) {
            throw new SanitizedProviderHttpException(
                SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED, null, $operationSuffix,
            );
        }

        return $this->credentials->decryptForOperation(
            $connection, $credential, 'plaid '.$operationSuffix.' connection '.$connection->id, $operationSuffix,
        );
    }

    private function baseUrl(): string
    {
        return (new ProviderEnvironmentResolver)->baseUrlFor(ProviderKey::Plaid);
    }

    /**
     * Merges Plaid's platform-level `client_id`/`secret` into every
     * outbound body (Plaid's own documented, supported authentication
     * mechanism — a JSON body-field pair, never an `Authorization`
     * header). Null-valued entries are filtered out (an optional field
     * explicitly set to null is omitted from the request entirely,
     * rather than sent as a literal JSON `null`).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function withPlatformCredentials(array $body): array
    {
        $filtered = array_filter($body, static fn (mixed $value): bool => $value !== null);

        return array_merge([
            'client_id' => (string) config('integrations.oauth_apps.plaid.client_id'),
            'secret' => (string) config('integrations.oauth_apps.plaid.secret'),
        ], $filtered);
    }

    /**
     * Case-insensitive header lookup — mirrors
     * `GoogleWorkspaceProvider::findHeaderCaseInsensitive()`'s identical,
     * already-established pattern verbatim.
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

    private function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;

        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
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
