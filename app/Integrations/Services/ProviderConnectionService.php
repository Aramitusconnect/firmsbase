<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Enums\FirmUserStatus;
use App\Integrations\Billing\ProviderBillableCallPipeline;
use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\RequiresBillableCallPipelineContract;
use App\Integrations\Contracts\SupportsDisconnectContract;
use App\Integrations\Contracts\SupportsLinkTokenContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\ConsumedOAuthState;
use App\Integrations\Data\LinkTokenInitiationResult;
use App\Integrations\Data\OAuthCallbackResult;
use App\Integrations\Data\OAuthInitiationResult;
use App\Integrations\Data\ProviderMetadata;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Enums\ResourceType;
use App\Integrations\Enums\SyncDirection;
use App\Integrations\Enums\WebhookBootstrapState;
use App\Integrations\Exceptions\GmailMailboxAlreadyRoutedException;
use App\Integrations\Exceptions\OAuthAccountMismatchException;
use App\Integrations\Exceptions\OAuthRedirectUriMismatchException;
use App\Integrations\Exceptions\OAuthTenantMismatchException;
use App\Integrations\Exceptions\ProviderOperationRequiresReconciliationException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Jobs\BootstrapWebhookSubscriptionsJob;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Models\IntegrationWebhookRoutingIndex;
use App\Integrations\Support\GmailMailboxRoutingService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\PlaidItemRoutingService;
use App\Integrations\Support\ProviderEnvironmentResolver;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\IntegrationEntitlementPolicyService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * ProviderConnectionService — the SOLE writer of
 * `firm_integrations.status` (frozen name, per checkpoint-00-final-specification.md
 * §6's explicit namespace reservation and Agent H's naming
 * reconciliation — NOT `ConnectionLifecycleService`; see
 * agent-h-security-architecture-review.md's "Naming reconciliation"
 * section). The real orchestrator: owns provider resolution (via
 * ProviderRegistry — App\Integrations\Services\IntegrationOAuthStateService
 * deliberately does not), the OAuth initiate/callback lifecycle, token
 * refresh, and disconnect — folding Agent E's transition-table/guard
 * logic in as private methods/state of this class, per Agent H's
 * binding correction (only the class name changed from Agent E's
 * original proposal).
 *
 * Internal transition table: a structural guard layered ON TOP OF
 * (never a replacement for) the specific business-rule guards below
 * (atomic state claim, redirect_uri re-validation, scope check,
 * account-mismatch check) that are not expressible as a bare edge
 * lookup. No DB CHECK constraint exists on firm_integrations.status
 * (frozen-design-post-review.md item 13 — out of this checkpoint's
 * migration scope; firm_integrations is a Checkpoint 3 table). No DB
 * trigger — this codebase uses none anywhere.
 *
 * Callback-after-disconnect / stale-callback rejection
 * (frozen-design-post-review.md item 13, "primary, sufficient,
 * testable control"): completeOAuthCallback()/refreshConnectionToken()/
 * disconnect() all re-load `firm_integrations` fresh with
 * ->lockForUpdate(), inside the same locked transaction, immediately
 * before any write, and abort/no-op if status is already Disconnected.
 */
class ProviderConnectionService
{
    /**
     * @var array<string, string[]>
     */
    private const TRANSITIONS = [
        'pending' => ['active', 'scope_insufficient', 'error', 'disconnected'],
        'active' => ['scope_insufficient', 'reauthorization_required', 'error', 'disconnected'],
        'scope_insufficient' => ['active', 'reauthorization_required', 'error', 'disconnected'],
        'reauthorization_required' => ['active', 'scope_insufficient', 'error', 'disconnected'],
        'error' => ['active', 'scope_insufficient', 'reauthorization_required', 'disconnected'],
        'disconnected' => [],
    ];

    /**
     * FirmsVault Live Integrations, Checkpoint 4 addition ("Plaid
     * financial evidence add-on" §6). The design's own binding
     * Plaid-Item-error-code -> `ConnectionStatus::ReauthorizationRequired`
     * mapping table — see `markItemErrorState()`, the sole reader.
     */
    private const REAUTHORIZATION_REQUIRED_PLAID_ERROR_CODES = [
        'ITEM_LOGIN_REQUIRED',
        'USER_PERMISSION_REVOKED',
        'USER_ACCOUNT_REVOKED',
        'OAUTH_INVALID_TOKEN',
        'OAUTH_CONSENT_EXPIRED',
        'OAUTH_USER_REVOKED',
    ];

    public function __construct(
        private readonly IntegrationOAuthStateService $stateService,
        private readonly IntegrationCredentialService $credentialService,
        private readonly IntegrationAccessPolicyService $accessPolicy,
        private readonly ProviderRegistry $providerRegistry,
        private readonly OutboundProviderHttpClient $httpClient,
        private readonly ProviderRedirectUrlValidator $redirectValidator,
        private readonly TimelineEventRecorder $events,
        private readonly IntegrationEntitlementPolicyService $entitlement,
        private readonly GmailMailboxRoutingService $gmailMailboxRouting,
        // FirmsVault Live Integrations, Checkpoint 4 addition ("Plaid
        // financial evidence add-on" — checkpoint4-combined-design.md
        // §1.1.1/§6.5, binding "Option B"): mirrors
        // $gmailMailboxRouting's identical injection shape immediately
        // above. Used by disconnect()/disableWebhookRouting() to widen
        // their existing routing-table cleanup with
        // PlaidItemRoutingService::unroute() — the exact sibling call
        // site $gmailMailboxRouting->unroute() already establishes.
        private readonly PlaidItemRoutingService $plaidItemRouting,
    ) {}

    /**
     * CHECKPOINT 8.2 (§A7b). Set by `finishLinkTokenCallback()` INSIDE its
     * transaction and consumed by `completeLinkTokenConnection()` after
     * that transaction commits, so the webhook bootstrap's outbound calls
     * happen outside it.
     *
     * Scalars only, deliberately: nothing carried across the boundary is a
     * live model, so the bootstrap always re-reads fresh state. The OAuth
     * flow needs no equivalent — `completeOAuthCallback()` already has the
     * committed result in hand.
     *
     * @var array{connection_id: int, firm_id: int, current_user_id: int}|null
     */
    private ?array $deferredWebhookBootstrap = null;

    /**
     * Checkpoint 10 addition (frozen-design-post-security-review.md §2;
     * agent-10h-architecture-security-review.md §1). Required not only
     * for a firm's first connection to a provider but for EVERY
     * reconnect after a full disconnect — finishCallback() unconditionally
     * rejects completing OAuth against an already-Disconnected row (see
     * this file's own class docblock), so re-running
     * initiateOAuthConnection() on the old row can never work.
     *
     * Idempotency is best-effort only (lockForUpdate()-guarded
     * find-then-create against an existing (firm_id,
     * integration_provider_id) row with status = Pending and
     * external_account_id IS NULL) — NOT DB-enforced. The existing
     * partial unique index on firm_integrations deliberately permits
     * multiple concurrent NULL-external_account_id rows, so a true
     * double-submit race could still create two Pending rows; that
     * residual is accepted as a low-severity, purely-cosmetic gap, per
     * the frozen design's explicit ruling not to add migration scope to
     * close it.
     *
     * FirmsVault Live Integrations, Checkpoint 2 addition
     * (checkpoint2-combined-design.md §2 P-6a): optional trailing
     * `?array $requestedCapabilities = null` — the firm's pre-connect
     * capability selection (a string[] of `ResourceType` values). When
     * non-null, validated as a SUBSET of the resolved provider's
     * `ProviderMetadata::resourceTypes` before being persisted —
     * defense-in-depth against a tampered client payload, mirroring
     * this method's own existing `ProviderRegistry::has()`-via-`get()`
     * re-check immediately above: never trust a submitted id/array
     * alone. Every existing call site that omits this parameter keeps
     * today's exact behavior (null persisted, no validation performed).
     */
    public function startConnection(
        int $firmId,
        int $integrationProviderId,
        int $currentUserId,
        ?array $requestedCapabilities = null,
    ): FirmIntegration {
        return (new TenantContextService)->runWithFirmContext(
            $firmId,
            function () use ($firmId, $integrationProviderId, $currentUserId, $requestedCapabilities) {
                $actor = $this->resolveActingFirmUser($currentUserId, $firmId);

                $this->entitlement->assertEnabled($actor->firm);
                $this->accessPolicy->assertCanConnect($actor);

                $provider = IntegrationProvider::query()->find($integrationProviderId);

                if ($provider === null) {
                    throw new RuntimeException(
                        "Cannot start connection: integration_provider {$integrationProviderId} does not exist."
                    );
                }

                // Fails before any row is created if this provider is not
                // a genuinely registered, instantiable adapter — mirrors
                // resolveProvider()'s own equivalent check below.
                $resolvedProvider = $this->providerRegistry->get(ProviderKey::from($provider->code));

                if ($requestedCapabilities !== null) {
                    $allowedCapabilities = ProviderMetadata::fromProvider($resolvedProvider)->resourceTypes;
                    $unsupported = array_diff($requestedCapabilities, $allowedCapabilities);

                    if ($unsupported !== []) {
                        throw new RuntimeException(
                            'Requested capabilities include values not supported by this provider: '
                            .implode(', ', $unsupported)
                        );
                    }
                }

                $existing = FirmIntegration::query()
                    ->where('firm_id', $firmId)
                    ->where('integration_provider_id', $integrationProviderId)
                    ->where('status', ConnectionStatus::Pending->value)
                    ->whereNull('external_account_id')
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $connection = FirmIntegration::create([
                    'firm_id' => $firmId,
                    'integration_provider_id' => $integrationProviderId,
                    'status' => ConnectionStatus::Pending,
                    'connected_by_firm_user_id' => $actor->id,
                    'requested_capabilities_json' => $requestedCapabilities,
                ]);

                $this->events->record($connection->firm, 'integration_oauth.connection_created', $connection, $actor->user, [
                    'firm_integration_id' => $connection->id,
                    'integration_provider_id' => $integrationProviderId,
                ]);

                return $connection;
            }
        );
    }

    /**
     * Checkpoint 10 addition
     * (frozen-design-post-security-review.md §12). Narrowly scoped to
     * `display_label` — this is intentionally NOT a general-purpose
     * "update connection" method (per the frozen design's Action-based,
     * never Form-backed Edit-page ruling: there is no schema anywhere
     * that could accidentally reference credential fields).
     */
    public function renameConnection(FirmIntegration $connection, int $currentUserId, string $displayLabel): FirmIntegration
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $currentUserId, $displayLabel) {
                $actor = $this->resolveActingFirmUser($currentUserId, $connection->firm_id);

                $this->entitlement->assertEnabled($connection->firm);
                $this->accessPolicy->assertCanConfigure($actor);

                $fresh = FirmIntegration::query()
                    ->where('id', $connection->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $fresh->update(['display_label' => $displayLabel]);

                return $fresh->fresh();
            }
        );
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 2 addition
     * (checkpoint2-combined-design.md §2 P-6h). `renameConnection()`-
     * shaped: re-fetch under lock, re-authorize via the SAME existing
     * access policy renameConnection() uses (`assertCanConfigure()` —
     * updating which capabilities a connection requests is a
     * configuration action, not a connect/disconnect one), a
     * single-column update, one audit event. Deliberately does NOT
     * itself trigger a new OAuth round-trip or touch `status`/
     * credentials — a caller that needs the newly-requested capabilities
     * to actually take effect (i.e. request the corresponding broader
     * scope bundle from the provider) redirects the firm through the
     * existing, unmodified OAuth-initiate route afterward, which reads
     * this column fresh via `initiateOAuthConnection()`'s own
     * `requested_capabilities_json` threading (P-6b) — no service/
     * controller/route change needed for that reauthorization leg.
     *
     * Validated as a subset of the resolved provider's
     * `ProviderMetadata::resourceTypes`, identical discipline to
     * `startConnection()`'s own defense-in-depth check (P-6a) — never
     * trust a submitted capability array alone.
     */
    public function updateRequestedCapabilities(FirmIntegration $connection, array $capabilities, int $actingFirmUserId): FirmIntegration
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $capabilities, $actingFirmUserId) {
                $actor = $this->resolveActingFirmUser($actingFirmUserId, $connection->firm_id);

                $this->entitlement->assertEnabled($connection->firm);
                $this->accessPolicy->assertCanConfigure($actor);

                $fresh = FirmIntegration::query()
                    ->where('id', $connection->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $resolvedProvider = $this->resolveProvider($fresh);
                $allowedCapabilities = ProviderMetadata::fromProvider($resolvedProvider)->resourceTypes;
                $unsupported = array_diff($capabilities, $allowedCapabilities);

                if ($unsupported !== []) {
                    throw new RuntimeException(
                        'Requested capabilities include values not supported by this provider: '
                        .implode(', ', $unsupported)
                    );
                }

                $fresh->update(['requested_capabilities_json' => $capabilities]);

                $this->events->record($fresh->firm, 'integration_oauth.requested_capabilities_updated', $fresh, $actor->user, [
                    'firm_integration_id' => $fresh->id,
                    'requested_capabilities' => $capabilities,
                ]);

                return $fresh->fresh();
            }
        );
    }

    /**
     * Starts a connect/reauthorize attempt. $currentUserId is resolved
     * to a FirmUser scoped to THIS connection's own firm internally
     * (resolveActingFirmUser()) — the caller (OAuthConnectionController)
     * never resolves or passes a FirmUser itself, so there is no way
     * for a controller to accidentally reuse a stale one. The resolved
     * actor is re-checked against
     * IntegrationAccessPolicyService::assertCanConnect() HERE, at
     * initiate time — completeOAuthCallback() below independently
     * re-resolves and re-checks it again at callback time against
     * whatever role the SAME user currently holds (Agent H review item
     * 10, Factor B — never cached from initiate time).
     */
    public function initiateOAuthConnection(FirmIntegration $connection, int $currentUserId, string $redirectUri): OAuthInitiationResult
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $currentUserId, $redirectUri) {
                $actor = $this->resolveActingFirmUser($currentUserId, $connection->firm_id);

                $this->entitlement->assertEnabled($connection->firm);
                $this->accessPolicy->assertCanConnect($actor);

                $provider = $this->resolveProvider($connection);

                $result = $this->stateService->initiate(
                    $connection,
                    $actor,
                    $redirectUri,
                    // Checkpoint 2 (FirmsVault Live Integrations, P-6f):
                    // the 'client_id' key previously populated here from
                    // $connection->integration_provider_id is REMOVED —
                    // that value is FirmsBase's own internal
                    // integration_providers.id primary key, never a real
                    // OAuth client identifier. Confirmed safe to remove
                    // (checkpoint2-security-review.md Finding 7): zero
                    // production code anywhere reads $params['client_id'],
                    // and TestProvider's authorizationUrl() never
                    // referenced it either. A real OAuth provider
                    // self-supplies its own actual client_id from
                    // platform config (config('integrations.oauth_apps'))
                    // instead, the same way TestProvider self-supplies
                    // its own environment gate rather than trusting
                    // anything the caller passes.
                    fn (string $rawState, string $codeChallenge) => $provider->authorizationUrl([
                        'redirect_uri' => $redirectUri,
                        'response_type' => 'code',
                        'state' => $rawState,
                        'code_challenge' => $codeChallenge,
                        'code_challenge_method' => 'S256',
                        // Checkpoint 2 (P-6b): threads the connection's
                        // own pre-connect capability selection into
                        // requiredScopes()'s new optional $context
                        // parameter, so a capability-aware provider can
                        // compute a least-privilege scope bundle instead
                        // of a single hardcoded scope list. A provider
                        // with no per-capability distinction (TestProvider)
                        // simply ignores the context and returns its
                        // existing fixed scopes, exactly as before.
                        'scope' => implode(' ', $provider->requiredScopes([
                            'requested_capabilities' => $connection->requested_capabilities_json ?? [],
                        ])),
                    ]),
                );

                $this->events->record($connection->firm, 'integration_oauth.authorization_initiated', $connection, $actor->user, [
                    'oauth_state_id' => $result->oauthStateId,
                    'firm_integration_id' => $connection->id,
                ]);

                return $result;
            }
        );
    }

    /**
     * Completes a connect/reauthorize attempt. $currentUserId MUST be
     * the current request's authenticated user id — never reused from
     * initiate time. The acting FirmUser is re-resolved fresh here
     * (resolveActingFirmUser()), once the firm is known (after state
     * resolution), so assertCanConnect()'s re-check below genuinely
     * reflects the user's CURRENT role (Agent H review item 10, Factor
     * B) rather than whatever role they held at initiate time.
     */
    public function completeOAuthCallback(
        string $rawState,
        string $authorizationCode,
        int $currentUserId,
    ): OAuthCallbackResult {
        // Step 1/2 of the bootstrap (Agent H review item 2) lives
        // entirely inside resolveAndConsume() — by the time it returns,
        // the atomic one-time claim has already succeeded and its own
        // firm-context transaction has already closed.
        $consumed = $this->stateService->resolveAndConsume($rawState, $currentUserId);

        try {
            $result = (new TenantContextService)->runWithFirmContext(
                $consumed->firmId,
                fn () => $this->finishCallback($consumed, $authorizationCode, $currentUserId)
            );

            // CHECKPOINT 8.2 (§A7b). AFTER the OAuth transaction has
            // committed — so the authorization is already safe, and no
            // lock is held on the connection row — bring up the webhook
            // subscriptions. Deliberately fail-soft: a `subscribe()`
            // failure now degrades this connection's push delivery and
            // records why, instead of discarding a completed
            // authorization.
            $this->runWebhookBootstrapAfterConnect(
                $result->firmIntegration,
                $currentUserId,
                // Anti-tautology (ProviderBillableCallPipeline's own class
                // docblock, addition #0): $consumed->firmId is the OAuth
                // state's own independently-resolved firm id, established
                // long before the connection row was loaded — never
                // $connection->firm itself.
                $consumed->firmId,
            );

            return $result;
        } catch (OAuthAccountMismatchException|OAuthTenantMismatchException $e) {
            // By this point runWithFirmContext()'s DB::transaction() has
            // ALREADY rolled back and re-thrown — the lockForUpdate()
            // finishCallback() held on $connection is released, so this
            // ordinary, fresh, second transaction can write the durable
            // Error-transition + audit event without deadlocking against
            // it (see recordMismatchRejectionAfterRollback()'s own
            // docblock for the full genuine-defect history this closes).
            $this->recordMismatchRejectionAfterRollback(
                $consumed->firmId,
                $consumed->firmIntegrationId,
                $consumed->initiatingFirmUserId,
                $e instanceof OAuthAccountMismatchException
                    ? 'integration_oauth.provider_account_mismatch'
                    : 'integration_oauth.provider_tenant_mismatch',
                $e instanceof OAuthAccountMismatchException
                    ? 'Provider account mismatch on reauthorization.'
                    : 'Provider tenant mismatch on reauthorization.',
            );

            throw $e;
        }
    }

    private function finishCallback(ConsumedOAuthState $consumed, string $authorizationCode, int $currentUserId): OAuthCallbackResult
    {
        $connection = FirmIntegration::query()
            ->where('id', $consumed->firmIntegrationId)
            ->where('firm_id', $consumed->firmId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($connection->status === ConnectionStatus::Disconnected) {
            throw new RuntimeException(
                "Cannot complete OAuth callback for connection {$connection->id}: it has already been disconnected."
            );
        }

        // Factor A (state possession bound to the authenticated session)
        // is already enforced by resolveAndConsume()'s self-lookup RLS
        // predicate — a row could only be found if
        // initiating_user_id === $currentUserId. Factor B: re-resolve
        // the acting FirmUser fresh (never reused from initiate time)
        // and re-check the CURRENT role.
        $actor = $this->resolveActingFirmUser($currentUserId, $connection->firm_id);

        if ((int) $actor->id !== $consumed->initiatingFirmUserId) {
            throw new RuntimeException(
                "The acting FirmUser's membership no longer matches the FirmUser that initiated this OAuth state."
            );
        }

        $this->accessPolicy->assertCanConnect($actor);

        $expectedRedirectUri = $this->expectedRedirectUri();
        $this->redirectValidator->assertSafe($consumed->redirectUri);

        if (! $this->redirectValidator->matchesExpected($expectedRedirectUri, $consumed->redirectUri)) {
            throw new OAuthRedirectUriMismatchException;
        }

        $provider = $this->resolveProvider($connection);

        $tokenSet = $this->httpClient->execute(
            fn () => $provider->exchangeCodeForToken($authorizationCode, [
                'code_verifier' => $consumed->pkceVerifierPlaintext,
                'redirect_uri' => $consumed->redirectUri,
                // FirmsVault Live Integrations, Checkpoint 2 addition
                // (checkpoint2-combined-design.md §2 P-6c, closing FP-1):
                // exchangeCodeForToken() cannot call
                // ProviderRequestExecutor::send() at all without a full
                // FirmIntegration model in hand (that method's signature
                // requires one) — $connection is already in scope here,
                // this simply threads it through.
                'connection' => $connection,
            ]),
            'exchangeCodeForToken',
        );

        $returnedAccountId = $tokenSet['external_account_id'] ?? null;

        if ($connection->external_account_id !== null
            && $returnedAccountId !== null
            && ! hash_equals((string) $connection->external_account_id, (string) $returnedAccountId)) {
            // Deliberately throws WITHOUT writing here — see
            // completeOAuthCallback()'s catch block for why: this
            // method runs inside a lockForUpdate() on $connection, held
            // for the whole ambient transaction, so a write against the
            // SAME row from a separate connection (the naive fix)
            // would deadlock against that lock. The durable
            // Error-transition + audit event are recorded by the
            // caller, AFTER this transaction has already rolled back
            // and released the lock.
            throw new OAuthAccountMismatchException;
        }

        // FirmsVault Live Integrations, Checkpoint 2 addition
        // (checkpoint2-combined-design.md §2 P-6d; checkpoint2-security-review.md
        // Finding 1, confirmed sound): the EXACT same capture-if-null /
        // hash_equals()-compare-and-reject-if-both-set pattern as the
        // external_account_id check immediately above, applied to a
        // second, coarser-grained column — the connected provider
        // TENANT (e.g. a Microsoft 365 organization), distinct from the
        // specific connected user ACCOUNT within that tenant. Correctly
        // captures on first connect (nothing to compare against yet)
        // and enforces on every subsequent reauthorization, closing a
        // reconnect silently re-pointing a firm's connection at a
        // different provider tenant than the one it was originally
        // authorized against.
        $returnedTenantId = $tokenSet['tenant_id'] ?? null;

        if ($connection->external_tenant_id !== null
            && $returnedTenantId !== null
            && ! hash_equals((string) $connection->external_tenant_id, (string) $returnedTenantId)) {
            // See the identical comment on the account-mismatch branch
            // immediately above — same genuine defect, same fix, same
            // lockForUpdate()-deadlock reason for not writing here.
            throw new OAuthTenantMismatchException;
        }

        $grantedScopes = $this->parseScopes($tokenSet['scope'] ?? '');
        // Threads the SAME `requested_capabilities` context
        // initiateOAuthConnection() used to build the original scope
        // request (P-6b), so the satisfaction check here evaluates
        // against the identical bundle that was actually requested —
        // required for a capability-aware provider (e.g.
        // Microsoft365Provider) whose requiredScopes() throws on an
        // empty capability list. TestProvider (the only implementer
        // before this checkpoint) ignores context either way, so this
        // is a behavior-preserving change for it.
        $requiredScopes = $provider->requiredScopes([
            'requested_capabilities' => $connection->requested_capabilities_json ?? [],
        ]);
        $scopeSatisfied = count(array_diff($requiredScopes, $grantedScopes)) === 0;

        $wasReauthorization = in_array($connection->status, [
            ConnectionStatus::Error,
            ConnectionStatus::ScopeInsufficient,
            ConnectionStatus::ReauthorizationRequired,
        ], true);

        $targetStatus = $scopeSatisfied ? ConnectionStatus::Active : ConnectionStatus::ScopeInsufficient;

        $extra = ['scopes_granted_json' => $grantedScopes];

        if ($connection->external_account_id === null && $returnedAccountId !== null) {
            $extra['external_account_id'] = $returnedAccountId;
        }

        // Checkpoint 2 addition (P-6d) — first-connect capture, mirrors
        // external_account_id's own capture branch immediately above.
        if ($connection->external_tenant_id === null && $returnedTenantId !== null) {
            $extra['external_tenant_id'] = $returnedTenantId;
        }

        if ($connection->connected_at === null) {
            $extra['connected_at'] = now();
        }

        // Status is transitioned AWAY from Error/ScopeInsufficient/
        // ReauthorizationRequired BEFORE any credential is written —
        // deliberately, not incidentally: IntegrationCredentialService::store()
        // internally rejects a connection whose status is Disconnected
        // OR Error (assertConnectionUsable()), and a reauthorization
        // attempt legitimately starts from Error. Writing the target
        // status first (computed here from the token exchange result,
        // never depending on the credential write itself) means
        // store()'s own guard sees the NEW, already-usable status.
        $connection = $this->transitionStatus(
            $connection,
            $targetStatus,
            $scopeSatisfied ? null : 'Required scopes were not granted by the provider.',
            $extra,
        );

        // Credential replacement is wrapped in
        // IntegrationCredentialService::withRefreshLock() — the same
        // existing public primitive refreshConnectionToken() below
        // already uses — rather than a bare new DB::transaction() call:
        // it gives row-locking (via FirmIntegration::lockForUpdate())
        // AND transactional atomicity as one precedented call. The
        // closure receives a freshly locked FirmIntegration ($locked),
        // used for the credential lookups/writes below instead of the
        // outer $connection variable, matching refreshConnectionToken()'s
        // own convention.
        $this->credentialService->withRefreshLock(
            $connection,
            function (FirmIntegration $locked) use ($tokenSet, $scopeSatisfied) {
                $this->replaceOrStoreCredential(
                    $locked,
                    CredentialType::OauthAccessToken,
                    (string) $tokenSet['access_token'],
                    ['label' => 'OAuth access token'],
                    isset($tokenSet['expires_in']) ? now()->addSeconds((int) $tokenSet['expires_in']) : null,
                    $scopeSatisfied,
                );

                if (isset($tokenSet['refresh_token'])) {
                    $this->replaceOrStoreCredential(
                        $locked,
                        CredentialType::OauthRefreshToken,
                        (string) $tokenSet['refresh_token'],
                        ['label' => 'OAuth refresh token'],
                        null,
                        $scopeSatisfied,
                    );
                }

                return null;
            }
        );

        if (! $scopeSatisfied) {
            $this->events->record($connection->firm, 'integration_oauth.required_scope_missing', $connection, $actor->user, [
                'firm_integration_id' => $connection->id,
                'required_scopes' => $requiredScopes,
                'granted_scopes' => $grantedScopes,
            ]);
        }

        $this->events->record(
            $connection->firm,
            $wasReauthorization ? 'integration_oauth.reauthorization_succeeded' : 'integration_oauth.authorization_succeeded',
            $connection,
            $actor->user,
            ['firm_integration_id' => $connection->id, 'new_status' => $connection->status->value],
        );

        // FirmsVault Live Integrations, Checkpoint 2 addition
        // (checkpoint2-combined-design.md §2 P-6g): generic, not
        // Microsoft-specific — benefits every future webhook-capable
        // provider identically. Reached only on a successful
        // finishCallback() completion (every failure path above — the
        // account-mismatch and tenant-mismatch branches — throws before
        // this point, so $connection->status here is always either
        // Active or ScopeInsufficient, never Error). enableWebhookRouting()
        // performs its own full authorization (assertCanConfigure(),
        // which shares the exact same MANAGEMENT_ROLES ceiling as
        // assertCanConnect() already re-checked above, so this can never
        // fail on role grounds for the actor who just completed this
        // callback) and its own entitlement/lock/audit handling — called
        // here as a fully ordinary nested call
        // (TenantContextService::runWithFirmContext() is safely
        // re-entrant: it opens a nested transaction/savepoint and
        // restores whatever context was already active in a `finally`
        // block).
        // FirmsVault Live Integrations, Checkpoint 3 addition
        // (checkpoint3-combined-design.md §4.7; checkpoint3-security-review.md
        // Finding 1, required). See bootstrapWebhookSubscriptions()'s own
        // docblock for the full "this orchestration did not exist
        // anywhere in this codebase before this change" history — called
        // here, alongside enableWebhookRouting(), inside the SAME
        // `if ($provider instanceof SupportsWebhooksContract)` branch and
        // the SAME ambient transaction/lock finishCallback() already runs
        // under.
        // CHECKPOINT 8.2 (§A7b) — the bootstrap no longer runs here.
        //
        // `enableWebhookRouting()` STAYS: it is entirely local (a routing
        // token plus an index row), makes no provider call, and must be
        // atomic with the connection becoming Active.
        //
        // `bootstrapWebhookSubscriptions()` does NOT stay. It makes real
        // outbound `subscribe()` calls, and running those inside this
        // transaction had two consequences this checkpoint removes: the
        // provider call was made while this transaction held `FOR UPDATE`
        // on the connection row (the Checkpoint 8.1 deadlock shape), and
        // ANY failure rolled back a completed, valid authorization —
        // including the credential just exchanged — instead of degrading
        // honestly. The connection is marked `pending_webhook_bootstrap`
        // here, in the very transaction that makes it Active, so the
        // intermediate state is durable the instant it exists; the
        // bootstrap itself runs after this transaction commits, in
        // completeOAuthCallback().
        if ($provider instanceof SupportsWebhooksContract) {
            $this->enableWebhookRouting($connection, $currentUserId);
            $this->markWebhookBootstrapPending($connection, $provider);
        }

        return new OAuthCallbackResult($connection, $connection->status, $scopeSatisfied);
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 4 addition ("Plaid
     * financial evidence add-on" — checkpoint4-design-plaid-provider-core.md
     * §5.1; checkpoint4-combined-design.md §6.3). The Link-token
     * sibling of initiateOAuthConnection() — a genuinely narrower flow,
     * per SupportsLinkTokenContract's own docblock: Plaid Link never
     * leaves FirmsVault's own page (no cross-origin redirect), so there
     * is no `state`/PKCE CSRF-defeat requirement and no
     * `IntegrationOAuthState` row is ever created here — nothing needs
     * to survive an untrusted round-trip the way an OAuth authorization
     * code does.
     *
     * Reuses startConnection() unchanged as the precondition (creates
     * the `Pending` FirmIntegration row, already validates
     * `requested_capabilities` against `ProviderMetadata::resourceTypes` —
     * fully provider-agnostic already, no Plaid-specific change needed).
     */
    public function initiateLinkTokenConnection(FirmIntegration $connection, int $currentUserId): LinkTokenInitiationResult
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $currentUserId) {
                $actor = $this->resolveActingFirmUser($currentUserId, $connection->firm_id);

                $this->entitlement->assertEnabled($actor->firm);
                $this->accessPolicy->assertCanConnect($actor);

                $provider = $this->resolveProvider($connection);

                if (! $provider instanceof SupportsLinkTokenContract) {
                    throw new RuntimeException(
                        "ProviderConnectionService::initiateLinkTokenConnection() requires a provider implementing SupportsLinkTokenContract; connection {$connection->id}'s resolved provider does not."
                    );
                }

                $result = $this->httpClient->execute(
                    fn () => $provider->createLinkToken([
                        'connection' => $connection,
                        'requested_capabilities' => $connection->requested_capabilities_json ?? [],
                    ]),
                    'createLinkToken',
                );

                $this->events->record($connection->firm, 'integration_link_token.issued', $connection, $actor->user, [
                    'firm_integration_id' => $connection->id,
                ]);

                return new LinkTokenInitiationResult((string) $result['link_token'], (string) $result['expiration']);
            }
        );
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 4 addition ("Plaid
     * financial evidence add-on" §6 — the update-mode re-authentication
     * entry point). Mirrors `initiateLinkTokenConnection()`'s shape
     * closely — same actor resolution, same entitlement/access-policy
     * gates, same `SupportsLinkTokenContract` requirement — but carries
     * the connection's own decrypted `access_token` into
     * `createLinkToken()`'s `update_access_token` context key instead of
     * `requested_capabilities` (Plaid's update-mode Link flow re-uses the
     * Item's EXISTING product grant; it never re-requests capabilities),
     * a call shape `PlaidProviderLinkTokenTest.php` already proves
     * `PlaidProvider::createLinkToken()` itself correctly handles.
     *
     * Deliberately does NOT require the connection to currently be
     * `ReauthorizationRequired` — a firm may legitimately want to
     * re-launch update-mode Link for an `Active` connection too (Plaid's
     * own documented use case: adding accounts, or a user-initiated
     * "reconnect" from the UI before any error webhook has even arrived)
     * — the same latitude `initiateLinkTokenConnection()` itself already
     * grants by never inspecting `$connection->status` beyond
     * `startConnection()`'s own precondition.
     */
    public function initiateLinkTokenUpdateMode(FirmIntegration $connection, int $currentUserId): LinkTokenInitiationResult
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $currentUserId) {
                $actor = $this->resolveActingFirmUser($currentUserId, $connection->firm_id);

                $this->entitlement->assertEnabled($actor->firm);
                $this->accessPolicy->assertCanConnect($actor);

                $provider = $this->resolveProvider($connection);

                if (! $provider instanceof SupportsLinkTokenContract) {
                    throw new RuntimeException(
                        "ProviderConnectionService::initiateLinkTokenUpdateMode() requires a provider implementing SupportsLinkTokenContract; connection {$connection->id}'s resolved provider does not."
                    );
                }

                $credential = $this->credentialService->findActiveCredential($connection, CredentialType::ProviderAccessToken);

                if ($credential === null) {
                    throw new RuntimeException(
                        "ProviderConnectionService::initiateLinkTokenUpdateMode() found no active access token for connection {$connection->id}."
                    );
                }

                $accessToken = $this->credentialService->decryptForOperation(
                    $connection,
                    $credential,
                    'plaid update-mode link token: connection '.$connection->id,
                    'link_token_update_mode',
                );

                try {
                    $result = $this->httpClient->execute(
                        fn () => $provider->createLinkToken([
                            'connection' => $connection,
                            'update_access_token' => $accessToken,
                        ]),
                        'createLinkToken',
                    );
                } finally {
                    unset($accessToken);
                }

                $this->events->record($connection->firm, 'integration_link_token.update_mode_issued', $connection, $actor->user, [
                    'firm_integration_id' => $connection->id,
                ]);

                return new LinkTokenInitiationResult((string) $result['link_token'], (string) $result['expiration']);
            }
        );
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 4 addition ("Plaid
     * financial evidence add-on" — checkpoint4-design-plaid-provider-core.md
     * §5.2; checkpoint4-combined-design.md §6.3). Completes the
     * Link-token two-phase flow, mirroring completeOAuthCallback()'s own
     * shape (a thin public wrapper establishing firm context, delegating
     * the real work to a private finishLinkTokenCallback()) but WITHOUT
     * any `IntegrationOAuthState` consumption step — $connection is
     * already known directly (no opaque `state` to resolve first), per
     * this flow's own narrower threat model.
     */
    public function completeLinkTokenConnection(FirmIntegration $connection, string $publicToken, int $currentUserId): OAuthCallbackResult
    {
        $this->deferredWebhookBootstrap = null;

        $result = (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            fn () => $this->finishLinkTokenCallback($connection, $publicToken, $currentUserId)
        );

        // CHECKPOINT 8.2 (§A7b): run the deferred bootstrap only once the
        // transaction above has committed — see
        // runWebhookBootstrapAfterConnect().
        $deferred = $this->deferredWebhookBootstrap;
        $this->deferredWebhookBootstrap = null;

        if ($deferred !== null) {
            $this->runWebhookBootstrapAfterConnect(
                $result->firmIntegration,
                $deferred['current_user_id'],
                $deferred['firm_id'],
            );
        }

        return $result;
    }

    /**
     * Deliberately its own method, not a parameterized generalization of
     * finishCallback() — that method's signature is irreducibly
     * OAuth-shaped (ConsumedOAuthState, an authorization code,
     * redirect-URI re-validation, scope-satisfaction computation), and
     * retrofitting it to also accept a $publicToken path would
     * reintroduce exactly the "stretch one contract to fit two shapes"
     * anti-pattern this checkpoint's own SupportsLinkTokenContract
     * rejects. Mirrors finishCallback()'s structure closely wherever the
     * mechanics are genuinely analogous: same lock discipline, same
     * capture-if-null/hash_equals()-reject-on-mismatch pattern (reusing
     * OAuthAccountMismatchException/OAuthTenantMismatchException
     * verbatim — both already generic over "the specific account" vs.
     * "the coarser tenant," not OAuth-specific in meaning), same
     * enableWebhookRouting()/bootstrapWebhookSubscriptions() tail call.
     *
     * No scope-satisfaction concept exists for Plaid — a successfully
     * exchanged public_token IS full consent for whichever `products`
     * were requested at createLinkToken() time (Plaid has no partial-grant
     * response to compare against the way an OAuth `scope` response can
     * under-grant), so this method always transitions straight to
     * ConnectionStatus::Active on success, never ScopeInsufficient.
     */
    private function finishLinkTokenCallback(FirmIntegration $connection, string $publicToken, int $currentUserId): OAuthCallbackResult
    {
        $fresh = FirmIntegration::query()
            ->where('id', $connection->id)
            ->where('firm_id', $connection->firm_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($fresh->status === ConnectionStatus::Disconnected) {
            throw new RuntimeException(
                "Cannot complete Link-token callback for connection {$fresh->id}: it has already been disconnected."
            );
        }

        $actor = $this->resolveActingFirmUser($currentUserId, $fresh->firm_id);
        $this->accessPolicy->assertCanConnect($actor);

        $provider = $this->resolveProvider($fresh);

        if (! $provider instanceof SupportsLinkTokenContract) {
            throw new RuntimeException(
                "ProviderConnectionService::completeLinkTokenConnection() requires a provider implementing SupportsLinkTokenContract; connection {$fresh->id}'s resolved provider does not."
            );
        }

        $exchange = $this->httpClient->execute(
            fn () => $provider->exchangePublicToken($publicToken, ['connection' => $fresh]),
            'exchangePublicToken',
        );

        $returnedItemId = $exchange['item_id'] ?? null;

        if ($fresh->external_account_id !== null
            && $returnedItemId !== null
            && ! hash_equals((string) $fresh->external_account_id, (string) $returnedItemId)) {
            throw new OAuthAccountMismatchException;
        }

        $returnedInstitutionId = $exchange['institution_id'] ?? null;

        if ($fresh->external_tenant_id !== null
            && $returnedInstitutionId !== null
            && ! hash_equals((string) $fresh->external_tenant_id, (string) $returnedInstitutionId)) {
            throw new OAuthTenantMismatchException;
        }

        $extra = [];

        if ($fresh->external_account_id === null && $returnedItemId !== null) {
            $extra['external_account_id'] = $returnedItemId;
        }

        if ($fresh->external_tenant_id === null && $returnedInstitutionId !== null) {
            $extra['external_tenant_id'] = $returnedInstitutionId;
        }

        if ($fresh->connected_at === null) {
            $extra['connected_at'] = now();
        }

        $fresh = $this->transitionStatus($fresh, ConnectionStatus::Active, null, $extra);

        $this->credentialService->withRefreshLock(
            $fresh,
            function (FirmIntegration $locked) use ($exchange) {
                $this->replaceOrStoreCredential(
                    $locked,
                    CredentialType::ProviderAccessToken,
                    (string) $exchange['access_token'],
                    ['label' => 'Plaid Item access token'],
                    null, // Plaid access_token does not expire on its own
                    true,
                );

                return null;
            }
        );

        $this->events->record($fresh->firm, 'integration_link_token.exchange_succeeded', $fresh, $actor->user, [
            'firm_integration_id' => $fresh->id,
        ]);

        if ($provider instanceof SupportsWebhooksContract) {
            $this->enableWebhookRouting($fresh, $currentUserId);
            $this->markWebhookBootstrapPending($fresh, $provider);

            // CHECKPOINT 8.2 (§A7b): same staging as the OAuth flow — the
            // local state is committed first, and the outbound
            // `subscribe()` calls happen fail-soft afterwards. See
            // finishCallback()'s own comment and
            // runWebhookBootstrapAfterConnect().
            //
            // Anti-tautology (ProviderBillableCallPipeline's own class
            // docblock, addition #0): this flow (unlike finishCallback()'s
            // OAuth-state-derived $consumed->firmId) has no independent
            // firm-identifying value beyond the connection itself — the
            // ambient TenantContextService firm context that
            // completeLinkTokenConnection() already established via
            // runWithFirmContext() is the closest genuinely-separate
            // source available, so it is read fresh here rather than
            // reusing $fresh->firm.
            $this->deferredWebhookBootstrap = [
                'connection_id' => (int) $fresh->id,
                'firm_id' => (int) (new TenantContextService)->currentFirmId(),
                'current_user_id' => $currentUserId,
            ];
        }

        return new OAuthCallbackResult($fresh, $fresh->status, true);
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 4 addition ("Plaid
     * financial evidence add-on" §6 — "Item error-state handling and
     * update-mode re-authentication"). Maps a Plaid Item error code onto
     * `ConnectionStatus::ReauthorizationRequired` per the design's own
     * binding table:
     *
     *   - Reauthorization required: ITEM_LOGIN_REQUIRED,
     *     USER_PERMISSION_REVOKED, USER_ACCOUNT_REVOKED,
     *     OAUTH_INVALID_TOKEN, OAUTH_CONSENT_EXPIRED, OAUTH_USER_REVOKED.
     *   - Health-signal-only (connection remains genuinely Active/usable,
     *     no status transition): PENDING_EXPIRATION, PENDING_DISCONNECT,
     *     and any other/unrecognized code — never guessed into
     *     ReauthorizationRequired.
     *
     * A no-op (returns the row unchanged) if the connection is already
     * Disconnected — a terminal state a late-arriving/out-of-order
     * webhook must never resurrect, mirroring
     * `finishLinkTokenCallback()`'s own explicit Disconnected guard
     * immediately above.
     */
    public function markItemErrorState(FirmIntegration $connection, string $plaidErrorCode): FirmIntegration
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $plaidErrorCode): FirmIntegration {
                $fresh = FirmIntegration::query()
                    ->where('id', $connection->id)
                    ->where('firm_id', $connection->firm_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($fresh->status === ConnectionStatus::Disconnected) {
                    return $fresh;
                }

                if (! in_array($plaidErrorCode, self::REAUTHORIZATION_REQUIRED_PLAID_ERROR_CODES, true)) {
                    return $fresh;
                }

                $fresh = $this->transitionStatus($fresh, ConnectionStatus::ReauthorizationRequired, $plaidErrorCode);

                $this->events->record($fresh->firm, 'integration_connection.item_error_state_applied', $fresh, null, [
                    'firm_integration_id' => $fresh->id,
                    'plaid_error_code' => $plaidErrorCode,
                ], independentOfAmbientTransaction: true);

                return $fresh;
            }
        );
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 4 addition — the symmetric
     * counterpart to `markItemErrorState()`, driven by Plaid's
     * `ITEM: LOGIN_REPAIRED` webhook. A no-op (returns the row unchanged)
     * unless the connection is CURRENTLY `ReauthorizationRequired` —
     * covers both an already-Active connection (the update-mode Link
     * flow's own `completeLinkTokenConnection()`/`finishLinkTokenCallback()`
     * call may already have transitioned it to Active before this
     * confirmation webhook arrives — an expected, idempotent race, not an
     * error) and a terminal Disconnected connection (never resurrected).
     */
    public function markItemLoginRepaired(FirmIntegration $connection): FirmIntegration
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection): FirmIntegration {
                $fresh = FirmIntegration::query()
                    ->where('id', $connection->id)
                    ->where('firm_id', $connection->firm_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($fresh->status !== ConnectionStatus::ReauthorizationRequired) {
                    return $fresh;
                }

                $fresh = $this->transitionStatus($fresh, ConnectionStatus::Active, null);

                $this->events->record($fresh->firm, 'integration_connection.item_login_repaired', $fresh, null, [
                    'firm_integration_id' => $fresh->id,
                ], independentOfAmbientTransaction: true);

                return $fresh;
            }
        );
    }

    /**
     * Replaces or stores a single OAuth credential (access token or
     * refresh token) resulting from a completed token exchange, using
     * ONLY IntegrationCredentialService's existing PUBLIC store()/
     * rotate() methods — never a private method of that service, never
     * a direct IntegrationCredential::save()/update()/create() write
     * from this file, never touching ciphertext ourselves.
     *
     * Why this exists: IntegrationCredentialService::store() always
     * INSERTs a brand-new Active row, and
     * integration_credentials_one_active_per_connection_and_type is a
     * partial unique index on (firm_integration_id, credential_type)
     * WHERE status = 'active'. Calling store() unconditionally on every
     * callback — including a reauthorization of an already-connected
     * integration that already has an Active credential of this type —
     * throws a unique-constraint violation. rotate() is the correct
     * primitive for that case: it marks the OLD row Rotated before
     * inserting the new Active row (see
     * IntegrationCredentialService::rotateExistingCredential()), so the
     * index is never violated.
     *
     * First-time connect (no existing Active row of this $type found)
     * always calls store() unconditionally, exactly as before — there is
     * nothing to preserve yet, and this holds even when
     * $replaceIfExisting is false (a first connect that comes back
     * scope-insufficient still stores whatever was granted).
     *
     * Reauthorization (an existing Active row of this $type IS found) is
     * gated by $replaceIfExisting, which callers bind to this callback's
     * $scopeSatisfied outcome: only a fully scope-satisfied
     * reauthorization is allowed to rotate() the existing credential
     * away. A scope-insufficient reauthorization leaves the existing,
     * still-usable credential exactly as it is — preserved rather than
     * downgraded/discarded by a lesser grant — while the connection's
     * own `status` column is still separately transitioned to
     * ScopeInsufficient by transitionStatus() above, unconditionally.
     */
    private function replaceOrStoreCredential(
        FirmIntegration $connection,
        CredentialType $type,
        string $plaintextSecret,
        array $metadata,
        ?DateTimeInterface $expiresAt,
        bool $replaceIfExisting,
    ): ?IntegrationCredential {
        $existing = IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', $type->value)
            ->where('status', IntegrationCredentialStatus::Active->value)
            ->first();

        if ($existing === null) {
            return $this->credentialService->store($connection, $type, $plaintextSecret, $metadata, $expiresAt);
        }

        if (! $replaceIfExisting) {
            return null;
        }

        return $this->credentialService->rotate($connection, $existing, $plaintextSecret, $expiresAt);
    }

    /**
     * Token-refresh, using IntegrationCredentialService::withRefreshLock()
     * EXACTLY as it exists (never a second lock mechanism, never
     * Cache::lock()). This checkpoint is the first real production
     * caller of that method, so the mandatory double-checked-locking
     * re-read-after-lock step below is written fresh here (Agent H
     * review item 15).
     *
     * DISCLOSED GAP: integration_credentials.last_refreshed_at and
     * .refresh_failure_reason are NOT populated by this method.
     * IntegrationCredentialService (a Checkpoint 4 file, outside this
     * checkpoint's frozen file allowlist) exposes no method to write
     * either column, and this checkpoint may not add one. Refresh
     * outcome is instead reflected at the firm_integrations level
     * (status/error_reason), which ProviderConnectionService, as the
     * sole writer of that table, IS authorized to write directly.
     *
     * Checkpoint 1 (FirmsVault Live Integrations) additions:
     *
     *   - $callAttemptNumber: additive, OPTIONAL, trailing scalar param
     *     (checkpoint1-design-http-ratelimit-usage.md §2.6's derivation
     *     table) — folded into the refresh-token decrypt's own
     *     operationId label for extra audit precision across a job's
     *     bounded retries; a future
     *     `IntegrationUsageRecorderService::deriveIdempotencyKey('firm_integration_refresh', ...)`
     *     caller can also use it once usage-metering is wired into this
     *     method (not this checkpoint's scope). Every existing caller
     *     that omits it preserves today's exact behavior.
     *   - Scope-downgrade detection on the refresh path itself
     *     (checkpoint1-design-oauth-security-review.md §8;
     *     checkpoint1-security-review.md Finding 8's one code-quality
     *     fix applied: guarded with `($outcome['refreshedScopes'] ?? null) !== null`,
     *     not a bare `!== null`, since the `'already_fresh'` outcome
     *     branch never sets that key at all). Reuses parseScopes()
     *     (already private on this class) and the already-legal
     *     `active -> scope_insufficient` transition — no new
     *     dependency, no new migration.
     */
    public function refreshConnectionToken(FirmIntegration $connection, ?int $callAttemptNumber = null): OAuthCallbackResult
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $callAttemptNumber) {
                try {
                    $outcome = $this->credentialService->withRefreshLock($connection, function (FirmIntegration $locked) use ($callAttemptNumber) {
                        // CHECKPOINT 8 GATE 2 (agent-8h-architecture-security-review.md
                        // §1 item 4 / §2 item 5): post-lock ConnectionStatus
                        // re-check, using the ALREADY-locked $locked row —
                        // closes the TOCTOU window between the
                        // RefreshIntegrationToken job's own pre-lock Gate 1
                        // read and this lock's acquisition (e.g. a
                        // concurrent disconnect() completing in between,
                        // which itself takes this same lockForUpdate() on
                        // firm_integrations). Silent no-op, never an
                        // exception — whatever transitioned the connection
                        // away from Active already recorded its own event.
                        if ($locked->status !== ConnectionStatus::Active) {
                            return ['outcome' => 'not_active'];
                        }

                        $refreshCredential = IntegrationCredential::query()
                            ->where('firm_integration_id', $locked->id)
                            ->where('credential_type', CredentialType::OauthRefreshToken->value)
                            ->where('status', IntegrationCredentialStatus::Active->value)
                            ->first();

                        if ($refreshCredential === null) {
                            throw new RuntimeException("No active refresh token for connection {$locked->id}.");
                        }

                        $accessCredential = IntegrationCredential::query()
                            ->where('firm_integration_id', $locked->id)
                            ->where('credential_type', CredentialType::OauthAccessToken->value)
                            ->where('status', IntegrationCredentialStatus::Active->value)
                            ->first();

                        // Mandatory double-checked locking (Agent H
                        // review item 15): re-read the access token's
                        // own freshness AFTER the parent row lock is
                        // already held, and treat this as a no-op if
                        // another transaction already refreshed it
                        // while this one waited behind the lock.
                        if ($accessCredential !== null
                            && $accessCredential->expires_at !== null
                            && $accessCredential->expires_at->isAfter(now()->addMinutes(2))) {
                            return ['outcome' => 'already_fresh', 'credential' => $accessCredential];
                        }

                        if ($accessCredential === null) {
                            throw new RuntimeException("No active access token for connection {$locked->id}.");
                        }

                        // Spaces (not hyphens) deliberately break up the digit runs here so
                        // this label never trips IntegrationCredentialService::assertSafeAuditLabel()'s
                        // 20+-contiguous-character high-entropy heuristic (checkpoint1-diff-review.md) —
                        // matches the convention already established in WebhookConnectionResolverService.
                        $operationId = 'oauth refresh: connection '.$locked->id.' at '.now()->timestamp
                            .($callAttemptNumber !== null ? ' attempt '.$callAttemptNumber : '');

                        $refreshTokenPlaintext = $this->credentialService->decryptForOperation(
                            $locked,
                            $refreshCredential,
                            $operationId,
                            'oauth_token_refresh',
                        );

                        $provider = $this->resolveProvider($locked);

                        $tokenSet = $this->httpClient->execute(
                            // FirmsVault Live Integrations, Checkpoint 2
                            // addition (checkpoint2-combined-design.md §2
                            // P-6e, closing FP-2): threads the ALREADY-
                            // locked $locked model directly, rather than
                            // only the bare firm_integration_id — avoids
                            // a redundant, lock-external second DB read a
                            // real provider's refreshToken() would
                            // otherwise need to perform to get a usable
                            // FirmIntegration for ProviderRequestExecutor::send().
                            fn () => $provider->refreshToken($refreshTokenPlaintext, [
                                'firm_integration_id' => $locked->id,
                                'connection' => $locked,
                            ]),
                            'refreshToken',
                        );

                        $newAccessCredential = $this->credentialService->rotate(
                            $locked,
                            $accessCredential,
                            (string) $tokenSet['access_token'],
                            isset($tokenSet['expires_in']) ? now()->addSeconds((int) $tokenSet['expires_in']) : null,
                        );

                        if (isset($tokenSet['refresh_token'])) {
                            $this->credentialService->rotate($locked, $refreshCredential, (string) $tokenSet['refresh_token']);
                        }

                        // Checkpoint 1 addition (checkpoint1-design-oauth-security-review.md
                        // §8): some providers' refresh responses carry a
                        // `scope` field reflecting the CURRENT actual
                        // grant, which can legitimately narrow between
                        // two refreshes without the refresh itself
                        // failing (invalid_grant is not raised) — e.g. a
                        // user revoking one scope via their provider
                        // account permissions page while the refresh
                        // token remains technically valid for the
                        // remaining scopes. null (not an empty array)
                        // when the provider didn't return a scope field
                        // at all, so the caller below can distinguish
                        // "no scope info returned" from "returned an
                        // empty scope grant".
                        $refreshedScopes = isset($tokenSet['scope']) ? $this->parseScopes((string) $tokenSet['scope']) : null;

                        return ['outcome' => 'refreshed', 'credential' => $newAccessCredential, 'refreshedScopes' => $refreshedScopes];
                    });

                    if ($outcome['outcome'] === 'not_active') {
                        $fresh = $connection->fresh();

                        return new OAuthCallbackResult($fresh, $fresh->status, false, 'Connection is not Active; refresh skipped.');
                    }

                    // Checkpoint 1 addition (checkpoint1-design-oauth-security-review.md
                    // §8; checkpoint1-security-review.md Finding 8's
                    // guard fix): `?? null` is REQUIRED here, not a bare
                    // `!== null` — the 'already_fresh' outcome branch
                    // above never sets the 'refreshedScopes' key at all,
                    // which would otherwise trigger a PHP "undefined
                    // array key" warning on every no-op refresh.
                    if (($outcome['refreshedScopes'] ?? null) !== null) {
                        $provider = $this->resolveProvider($connection->fresh());
                        // Threads the same `requested_capabilities`
                        // context as finishCallback()'s scope-satisfaction
                        // check, so a capability-aware provider evaluates
                        // the downgrade check against the same bundle
                        // that was actually requested.
                        $requiredScopes = $provider->requiredScopes([
                            'requested_capabilities' => $connection->fresh()->requested_capabilities_json ?? [],
                        ]);
                        $refreshedScopes = $outcome['refreshedScopes'];
                        $stillSatisfied = count(array_diff($requiredScopes, $refreshedScopes)) === 0;

                        if (! $stillSatisfied || $refreshedScopes !== $connection->fresh()->scopes_granted_json) {
                            $target = $stillSatisfied ? $connection->fresh()->status : ConnectionStatus::ScopeInsufficient;

                            $downgraded = $this->transitionStatus(
                                $connection->fresh(),
                                $target,
                                $stillSatisfied ? null : 'Refresh returned a narrower scope grant than required.',
                                ['scopes_granted_json' => $refreshedScopes],
                            );

                            $this->events->record($downgraded->firm, 'integration_oauth.scope_downgrade_detected_on_refresh', $downgraded, null, [
                                'firm_integration_id' => $downgraded->id,
                                'required_scopes' => $requiredScopes,
                                'refreshed_scopes' => $refreshedScopes,
                                'still_satisfied' => $stillSatisfied,
                            ]);
                        }
                    }

                    $fresh = $connection->fresh();

                    $this->events->record($fresh->firm, 'integration_oauth.refresh_succeeded', $fresh, null, [
                        'firm_integration_id' => $fresh->id,
                    ]);

                    return new OAuthCallbackResult($fresh, $fresh->status, true);
                } catch (SanitizedProviderHttpException $e) {
                    if ($e->category() === SanitizedProviderHttpException::CATEGORY_INVALID_GRANT) {
                        // Definitively invalid/expired/revoked refresh
                        // token — the provider has told us, in the one
                        // category OAuth reserves specifically for this
                        // case, that no amount of retrying will ever
                        // succeed. Terminal: transition now, do not retry.
                        $fresh = $this->transitionStatus(
                            $connection->fresh(),
                            ConnectionStatus::ReauthorizationRequired,
                            "Token refresh failed: {$e->category()}.",
                        );

                        $this->events->record($fresh->firm, 'integration_oauth.refresh_failed', $fresh, null, [
                            'firm_integration_id' => $fresh->id,
                            'category' => $e->category(),
                            'status_code' => $e->statusCode(),
                        ]);

                        return new OAuthCallbackResult(
                            $fresh,
                            $fresh->status,
                            false,
                            'Token refresh failed; reauthorization is required.',
                            transitionedThisCall: true,
                        );
                    }

                    // CHECKPOINT 8 CATEGORY SPLIT
                    // (agent-8h-architecture-security-review.md §1 item 4 /
                    // §2 item 5): network_error | provider_rejected |
                    // timeout | unknown are ambiguous or transient — a
                    // single failed attempt proves nothing about the
                    // refresh token's own validity. Do NOT transition the
                    // connection away from Active. Record the attempt,
                    // then rethrow so the caller (the queued
                    // RefreshIntegrationToken job) applies its own bounded
                    // $tries/backoff() policy; only exhausting those
                    // retries (the job's failed() hook ->
                    // markRefreshExhausted() below) results in any status
                    // change, and even then to Error, never
                    // ReauthorizationRequired — Error does not imply the
                    // credential itself is invalid.
                    $this->events->record($connection->firm, 'integration_oauth.refresh_transient_failure', $connection->fresh(), null, [
                        'firm_integration_id' => $connection->id,
                        'category' => $e->category(),
                        'status_code' => $e->statusCode(),
                    ]);

                    throw $e;
                }
            }
        );
    }

    /**
     * CHECKPOINT 8 addition (agent-8h-architecture-security-review.md §1
     * item 4 / §2 item 5): called ONLY from RefreshIntegrationToken's
     * failed() hook, once $tries is exhausted for a transient
     * (non-invalid_grant) refresh-failure category. Transitions to
     * ConnectionStatus::Error — never ReauthorizationRequired, which
     * specifically implies the credential itself is known-invalid (that
     * transition is reserved for the invalid_grant branch of
     * refreshConnectionToken()'s catch block above). Keeps this class
     * the sole writer of firm_integrations.status — the job never
     * writes that column directly.
     */
    public function markRefreshExhausted(FirmIntegration $connection, string $category): FirmIntegration
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $category) {
                $fresh = FirmIntegration::query()
                    ->where('id', $connection->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($fresh->status !== ConnectionStatus::Active) {
                    // Already moved on (reconnected, disconnected, or
                    // already transitioned by a concurrent operation) —
                    // nothing further for this exhaustion signal to do.
                    return $fresh;
                }

                $fresh = $this->transitionStatus(
                    $fresh,
                    ConnectionStatus::Error,
                    "Token refresh retries exhausted: {$category}.",
                );

                $this->events->record($fresh->firm, 'integration_oauth.refresh_exhausted', $fresh, null, [
                    'firm_integration_id' => $fresh->id,
                    'category' => $category,
                ]);

                return $fresh;
            }
        );
    }

    /**
     * Idempotent disconnect (Agent H review item 17): repeat-disconnect
     * is a safe no-op (no second outbound revoke, no disconnected_at
     * overwrite, no error). Revokes every Active credential via
     * IntegrationCredentialService::revoke() (never a direct
     * IntegrationCredential::update()), clears webhook_routing_token,
     * and best-effort revokes at the provider (failure to reach the
     * simulated provider never blocks local teardown).
     *
     * Phase 2 (FirmsVault Platform Admin Control Center, "Integration
     * Operations Center") addition: $actorPlatformAdminId — a narrow,
     * additive admin-actor extension, mirroring the established
     * `?int $actorFirmUserId = null` pattern already used by
     * IntegrationOutboxEventService::requeue()/
     * SyncItemService::requeueFromFailedPermanent() (accepted purely as
     * evidence to record, never re-derived or re-authorized here).
     * Exactly one of $currentUserId/$actorPlatformAdminId must be
     * provided. When $actorPlatformAdminId is given, resolveActingFirmUser()
     * is skipped entirely (a PlatformAdmin has no FirmUser membership to
     * resolve) and so is $accessPolicy->assertCanDisconnect() — that
     * firm-user-specific business-role check does not apply to a
     * platform-admin actor. This does NOT weaken this method's own
     * authorization: it was never this method's job to authorize a
     * PlatformAdmin caller in the first place. Per this phase's
     * architecture ruling, that authorization is enforced entirely by
     * the CALLER — App\Services\PlatformFirmIntegrationBoundedAccessService::
     * disconnectConnection(), which checks a role ceiling AND
     * PlatformStaffAccessPolicyService::canMutate() BEFORE ever reaching
     * this method — never by loosening this method to trust an
     * unauthenticated/unauthorized caller. $entitlement->assertEnabled()
     * still applies unconditionally on both paths: it is a firm-level
     * state check, independent of which kind of actor is disconnecting.
     * Every $events->record() call below is passed $actorUser (a real
     * `?User`, resolved only on the FirmUser path — TimelineEventRecorder::record()
     * has no PlatformAdmin-actor slot, mirroring this class's own
     * existing null-actor calls elsewhere, e.g. refreshConnectionToken()'s
     * `integration_oauth.refresh_succeeded` event); the acting
     * PlatformAdmin's id is instead folded into each event's own
     * $metadata as `actor_platform_admin_id`, so the evidence is still
     * captured, exactly as $actorFirmUserId's own "evidence to record"
     * convention does for requeue()/requeueFromFailedPermanent().
     */
    public function disconnect(FirmIntegration $connection, ?int $currentUserId = null, ?int $actorPlatformAdminId = null): FirmIntegration
    {
        if ($currentUserId === null && $actorPlatformAdminId === null) {
            throw new RuntimeException('disconnect() requires either a FirmUser $currentUserId or an admin $actorPlatformAdminId.');
        }

        if ($currentUserId !== null && $actorPlatformAdminId !== null) {
            throw new RuntimeException('disconnect() cannot be called with both a FirmUser $currentUserId and an admin $actorPlatformAdminId.');
        }

        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $currentUserId, $actorPlatformAdminId) {
                $actorUser = null;

                if ($currentUserId !== null) {
                    $actor = $this->resolveActingFirmUser($currentUserId, $connection->firm_id);

                    $this->accessPolicy->assertCanDisconnect($actor);

                    $actorUser = $actor->user;
                }

                $auditMetadataExtra = $actorPlatformAdminId !== null
                    ? ['actor_platform_admin_id' => $actorPlatformAdminId]
                    : [];

                // Checkpoint 10 addition (frozen design §0 ruling 2 /
                // §3.2 item 1): gating disconnect() on entitlement means
                // a firm whose entitlement is later administratively
                // revoked cannot use this path to clean up active
                // connections. Accepted, disclosed, precedented residual
                // — matches WebhookSubscriptionService::disable()'s
                // identical, already-shipped shape exactly. Applies on
                // BOTH the FirmUser and admin-actor paths — this is a
                // firm-level state check, not an actor-specific one.
                $this->entitlement->assertEnabled($connection->firm);

                $fresh = FirmIntegration::query()
                    ->where('id', $connection->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($fresh->status === ConnectionStatus::Disconnected) {
                    return $fresh;
                }

                $provider = $this->resolveProvider($fresh);

                if ($provider instanceof SupportsDisconnectContract) {
                    try {
                        $this->httpClient->execute(
                            fn () => $provider->revokeAtProvider(['firm_integration_id' => $fresh->id]),
                            'revokeAtProvider',
                        );
                    } catch (SanitizedProviderHttpException $e) {
                        // Best-effort: local teardown proceeds regardless
                        // of whether the (simulated) remote revoke
                        // succeeded. Checkpoint 9 addition (frozen
                        // design §3): record the failure for audit
                        // visibility — never blocks the teardown below.
                        $this->events->record($fresh->firm, 'integration_oauth.provider_revocation_failed', $fresh, $actorUser, array_merge([
                            'firm_integration_id' => $fresh->id,
                            'category' => $e->category(),
                            'status_code' => $e->statusCode(),
                        ], $auditMetadataExtra));
                    }
                } else {
                    // Checkpoint 6 addition (cross-provider security/ops
                    // review, audit-trail finding): a provider that does
                    // not implement SupportsDisconnectContract at all
                    // (Microsoft 365 — Graph delegated OAuth permissions
                    // cannot be revoked by the app itself; a tenant admin
                    // must revoke separately via the Entra admin center,
                    // per Microsoft365Provider's own class docblock)
                    // previously left NO trace in the timeline: the same
                    // `credential_revoked` + `disconnect` events fired as
                    // for a provider that genuinely revoked at the remote
                    // side, so nothing distinguished "we revoked at the
                    // provider" from "we never even tried." This event
                    // closes that operator-visibility gap without
                    // changing disconnect()'s own best-effort behavior —
                    // local teardown still proceeds identically either
                    // way.
                    $this->events->record($fresh->firm, 'integration_oauth.provider_revocation_not_supported', $fresh, $actorUser, array_merge([
                        'firm_integration_id' => $fresh->id,
                    ], $auditMetadataExtra));
                }

                foreach (IntegrationCredential::query()
                    ->where('firm_integration_id', $fresh->id)
                    ->where('status', IntegrationCredentialStatus::Active->value)
                    ->get() as $credential) {
                    $this->credentialService->revoke($fresh, $credential, 'disconnect');

                    // Checkpoint 9 addition (frozen design §3): fires
                    // for EACH credential revoked inside this loop, not
                    // once per disconnect() call.
                    $this->events->record($fresh->firm, 'integration_oauth.credential_revoked', $fresh, $actorUser, array_merge([
                        'firm_integration_id' => $fresh->id,
                        'integration_credential_id' => $credential->id,
                        'credential_type' => $credential->credential_type->value,
                    ], $auditMetadataExtra));
                }

                $fresh = $this->transitionStatus($fresh, ConnectionStatus::Disconnected, null, [
                    'disconnected_at' => now(),
                    'webhook_routing_token' => null,
                    // Checkpoint 10 addition (frozen design §0 ruling 1;
                    // agent-10h-architecture-security-review.md §1.4):
                    // disconnect() previously left external_account_id
                    // set, creating a latent uniqueness-violation risk on
                    // reconnect-to-the-same-external-account (the partial
                    // unique index on (firm_id, integration_provider_id,
                    // external_account_id) WHERE external_account_id IS
                    // NOT NULL). Checkpoint 10 is the first checkpoint to
                    // build a reachable "reconnect after disconnect"
                    // journey (startConnection() above), making this a
                    // real, not merely theoretical, risk starting now.
                    'external_account_id' => null,
                    // Checkpoint 2 (FirmsVault Live Integrations) note
                    // (checkpoint2-combined-design.md §2 P-6d;
                    // checkpoint2-security-review.md Finding 1):
                    // external_tenant_id is DELIBERATELY NOT nulled here,
                    // unlike external_account_id immediately above — this
                    // is not an oversight. No uniqueness constraint
                    // anywhere depends on external_tenant_id (unlike
                    // external_account_id's partial unique index), and
                    // startConnection()'s own docblock confirms a
                    // reconnect after a full disconnect() always creates
                    // a BRAND-NEW firm_integrations row (finishCallback()
                    // unconditionally rejects completing OAuth against an
                    // already-Disconnected row), so this row's stale
                    // external_tenant_id is never read again by anything.
                    // Do not "fix" this into an inconsistency by adding a
                    // null-out that isn't actually needed.
                ]);

                // Checkpoint 7 addition (frozen design §4, checklist
                // item 22, MODIFY): clear the corresponding
                // integration_webhook_routing_index row in the SAME
                // transaction as the firm_integrations.webhook_routing_token
                // nulling above — otherwise a stale index row remains
                // resolvable post-disconnect, letting a possessed
                // routing token continue to resolve a connection
                // identity for a connection that no longer has any
                // usable credential anyway (it would still collapse to
                // the same generic rejection via
                // WebhookConnectionResolverService::activeAndPreviousWebhookSecretsFor()'s
                // status check, but leaving the index row behind is an
                // unnecessary, avoidable drift).
                IntegrationWebhookRoutingIndex::query()
                    ->where('firm_integration_id', $fresh->id)
                    ->delete();

                // Checkpoint 3 addition (FirmsVault Live Integrations,
                // Google Workspace — checkpoint3-combined-design.md §4.7):
                // clears any Gmail mailbox-routing mapping in the SAME
                // transaction as the routing-index clear immediately
                // above, so a disconnected connection can never leave a
                // stale, forever-resolvable email-correlator row behind.
                // Idempotent/cheap no-op for every non-Gmail connection
                // (GmailMailboxRoutingService::unroute()'s own documented
                // contract) — unconditional, not provider-instanceof-gated,
                // so this can never be skipped by a future provider whose
                // capability detection doesn't happen to match here.
                $this->gmailMailboxRouting->unroute($fresh);

                // FirmsVault Live Integrations, Checkpoint 4 addition
                // ("Plaid financial evidence add-on" —
                // checkpoint4-combined-design.md §1.1.1/§6.5/§11):
                // clears any Plaid item_id-routing mapping in the SAME
                // transaction as the two clears immediately above, so a
                // disconnected Plaid connection can never leave a stale,
                // forever-resolvable item_id-correlator row behind.
                // Idempotent/cheap no-op for every non-Plaid connection
                // (PlaidItemRoutingService::unroute()'s own documented
                // contract) — unconditional, not provider-instanceof-gated,
                // mirroring $gmailMailboxRouting->unroute()'s identical
                // discipline immediately above.
                $this->plaidItemRouting->unroute($fresh);

                $this->events->record($fresh->firm, 'integration_oauth.disconnect', $fresh, $actorUser, array_merge([
                    'firm_integration_id' => $fresh->id,
                ], $auditMetadataExtra));

                return $fresh;
            }
        );
    }

    /**
     * Checkpoint 7 addition
     * (reviews/checkpoint-07/frozen-design-post-security-review.md
     * §4/§5.1, checklist item 6, MODIFY). Generates a fresh CSPRNG
     * routing token (mirrors
     * IntegrationOAuthStateService::generateRawState()'s exact
     * `random_bytes(32)` discipline, never `Str::random()`), writes the
     * plaintext-display copy to `firm_integrations.webhook_routing_token`
     * and the hashed lookup row to `integration_webhook_routing_index`
     * in the SAME transaction, so the two can never drift. Returns the
     * raw token — the ONE time it is ever available in plaintext; it is
     * never persisted anywhere in that form beyond this single display
     * column, and this method never logs it.
     *
     * Any pre-existing routing-index row(s) for this connection are
     * removed before the new one is inserted (rather than
     * `updateOrCreate()`), so a connection can never accumulate more
     * than one resolvable routing token at a time even if this method
     * is called again without an intervening disableWebhookRouting().
     *
     * Checkpoint 10 addition (frozen-design-post-security-review.md §3;
     * agent-10h-architecture-security-review.md §2): this method
     * previously had ZERO authorization checks of any kind — no actor
     * parameter, no role check, no entitlement check — safe only because
     * nothing called it yet. Checkpoint 10 is the first real caller, so
     * the gate is added HERE, in-service, matching
     * WebhookSubscriptionService's proven 5-for-5 precedent, rather than
     * only in the UI action handler (which would leave this method
     * itself permanently unguarded for any future non-UI caller). Zero
     * existing callers exist, so this signature change is safe.
     */
    public function enableWebhookRouting(FirmIntegration $connection, int $currentUserId): string
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $currentUserId) {
                $actor = $this->resolveActingFirmUser($currentUserId, $connection->firm_id);

                $this->entitlement->assertEnabled($connection->firm);
                $this->accessPolicy->assertCanConfigure($actor);

                $fresh = FirmIntegration::query()
                    ->where('id', $connection->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $rawToken = $this->generateRawWebhookRoutingToken();
                $tokenHash = hash('sha256', $rawToken);

                $fresh->update(['webhook_routing_token' => $rawToken]);

                IntegrationWebhookRoutingIndex::query()
                    ->where('firm_integration_id', $fresh->id)
                    ->delete();

                IntegrationWebhookRoutingIndex::query()->create([
                    'firm_id' => $fresh->firm_id,
                    'firm_integration_id' => $fresh->id,
                    'integration_provider_id' => $fresh->integration_provider_id,
                    'webhook_routing_token_hash' => $tokenHash,
                ]);

                return $rawToken;
            }
        );
    }

    /**
     * FirmsVault Live Integrations, Checkpoint 3 addition
     * (checkpoint3-combined-design.md §4.7; checkpoint3-design-sync-webhooks.md
     * §6.5; checkpoint3-security-review.md Finding 1, required). The
     * missing "subscribe()-on-connect" orchestration, traced and proven
     * NOT to exist anywhere in this codebase, for any provider, before
     * this change: `enableWebhookRouting()` above only ever writes
     * `firm_integrations.webhook_routing_token` and one
     * `integration_webhook_routing_index` row — it never calls
     * `$provider->subscribe()` — and the ONLY pre-existing production
     * call site of `->subscribe(` anywhere under `app/Integrations/` was
     * `RenewGraphSubscriptionJob.php`'s own 404-triggered re-subscribe
     * fallback, reached exclusively from the RENEWAL schedule's
     * enumeration of already-`Active` subscription rows — never on a
     * first connect. This means Microsoft 365 (Checkpoint 2) has carried
     * a real, pre-existing production defect since it shipped: no
     * webhook subscription was ever created for any connection, only
     * renewed if one already (impossibly) existed. This method is the
     * fix — generic, provider-agnostic, not Gmail/Google-specific —
     * retroactively correcting Microsoft 365's behavior as a byproduct
     * while giving Google Workspace correct behavior from day one.
     *
     * Called from finishCallback() immediately alongside the existing
     * enableWebhookRouting() call, inside the SAME
     * `if ($provider instanceof SupportsWebhooksContract)` branch and the
     * SAME ambient `TenantContextService::runWithFirmContext()`/
     * `DB::transaction()` wrap `completeOAuthCallback()` already opens
     * around the whole of `finishCallback()` — so a failure here (e.g. a
     * provider-specific routing conflict raised from inside
     * `$provider->subscribe()`) rolls back the entire OAuth connect,
     * leaving the connection never `Active`, rather than silently
     * degrading to manual-sync-only.
     *
     * Only subscribes to a resource type that is BOTH something this
     * provider can push webhooks for (`SupportsPullSyncContract::
     * pullableResourceTypes()`) AND something this specific connection
     * actually requested (`requested_capabilities_json`) — never a
     * broader, unrequested grant. A provider that does not implement
     * `SupportsPullSyncContract` has no resource types to intersect
     * against and this is a safe no-op for it.
     *
     * Idempotent on reauthorization (skips any resource type already
     * backed by an `Active` `integration_provider_webhook_subscriptions`
     * row for this connection), mirroring
     * `Microsoft365Provider::subscribe()`'s own pre-call idempotency
     * check — so a same-mailbox/same-tenant reauthorization safely
     * replaces rather than duplicates.
     *
     * Persists the returned subscription state by parsing
     * `subscription_id`/`expires_at` the same way
     * `RenewGraphSubscriptionJob::extractSubscriptionState()` already
     * does (narrowly duplicated here rather than shared, per the design's
     * own explicit "implementer's choice" allowance) — a missing/
     * unparseable required field throws, letting the surrounding
     * transaction roll back rather than persist a malformed row.
     * `provider_resource`/`provider_change_type` (this table's own two
     * further NOT NULL columns) are read from the provider's `resource`/
     * `change_type` response keys — the exact shape
     * `Microsoft365Provider::subscribe()` already returns — falling back
     * to the requested resource type / a fixed default respectively if a
     * provider's response omits them, so this can never attempt to
     * persist a NULL into either column.
     */
    /**
     * FirmsVault Live Integrations, Checkpoint 4 cost-control wiring pass
     * (checkpoint4-design-cost-control.md §2.1 call site #3, resolving
     * Finding 1 of checkpoint4-security-review.md): the 4th, trailing,
     * OPTIONAL `?Firm $firm` parameter. Both existing callers
     * (finishCallback()/finishLinkTokenCallback()) now pass it — see each
     * call site's own comment for its anti-tautology-safe source — so
     * this stays additive/non-breaking for any other hypothetical caller
     * this private method might gain in the future. Only used when the
     * resolved `$provider` implements `RequiresBillableCallPipelineContract`.
     *
     * CHECKPOINT 8.2 (§A7b) CORRECTION TO THIS DOCBLOCK. The paragraph
     * above describing this method as running "inside the SAME ambient
     * `TenantContextService::runWithFirmContext()`/`DB::transaction()`
     * wrap `completeOAuthCallback()` already opens" — so that a failure
     * "rolls back the entire OAuth connect, leaving the connection never
     * `Active`, rather than silently degrading to manual-sync-only" — is
     * NO LONGER TRUE, and that behavior was a defect rather than a
     * safeguard: it discarded a completed authorization (credential
     * included) over a transient `subscribe()` hiccup, and it made a
     * provider HTTP call while holding `FOR UPDATE` on the connection row.
     * This method is now called by `runWebhookBootstrapAfterConnect()`
     * AFTER that transaction commits, and each iteration's local writes
     * are committed in their own short transaction.
     */
    private function bootstrapWebhookSubscriptions(
        FirmIntegration $connection,
        IntegrationProviderContract $provider,
        int $currentUserId,
        ?Firm $firm = null,
    ): void {
        if (! $provider instanceof SupportsPullSyncContract) {
            return;
        }

        $requestedCapabilities = $connection->requested_capabilities_json ?? [];
        $resourceTypes = array_values(array_intersect($provider->pullableResourceTypes(), $requestedCapabilities));

        if ($resourceTypes === []) {
            return;
        }

        $actor = $this->resolveActingFirmUser($currentUserId, $connection->firm_id);

        foreach ($resourceTypes as $resourceType) {
            $this->runOneWebhookSubscriptionBootstrap(
                $connection, $provider, $resourceType, $actor, $firm,
            );
        }
    }

    private function runOneWebhookSubscriptionBootstrap(
        FirmIntegration $connection,
        IntegrationProviderContract $provider,
        string $resourceType,
        FirmUser $actor,
        ?Firm $firm,
    ): void {
        $alreadyActive = IntegrationProviderWebhookSubscription::query()
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', $resourceType)
            ->where('status', ProviderWebhookSubscriptionStatus::Active->value)
            ->exists();

        if ($alreadyActive) {
            return;
        }

        // DETERMINISTIC SUBSCRIBE-CYCLE IDENTITY (double-billing
        // remediation). The idempotency key below used to end in
        // `now()->format('YmdHi')`, which made a re-entry of this
        // bootstrap that crossed a minute boundary reserve a brand
        // new row and bill a second real subscribe() call. Lower
        // severity than the job call sites (this path is connect-flow
        // driven, not queue-auto-retried, and the $alreadyActive
        // guard above already suppresses the common case) but fixed
        // for the same reason.
        //
        // A FULLY static key would be wrong in the other direction:
        // it would wedge every legitimate future re-subscribe of the
        // same (connection, resourceType) behind one permanently
        // terminal reservation. The highest subscription-row id this
        // connection/resource has ever reached is the durable
        // discriminator that already exists — it is stable across
        // re-entries of one failed bootstrap (no row is written until
        // subscribe() succeeds) and advances exactly once a real
        // subscription is created.
        $subscribeCycle = (int) (IntegrationProviderWebhookSubscription::query()
            ->where('firm_integration_id', $connection->id)
            ->where('resource_type', $resourceType)
            ->max('id') ?? 0);

        // FirmsVault Live Integrations, Checkpoint 4 cost-control
        // wiring pass (checkpoint4-design-cost-control.md §2.1 call
        // site #3). Additive `instanceof` branch only — every other
        // provider (Microsoft365Provider, GoogleWorkspaceProvider)
        // does not implement RequiresBillableCallPipelineContract and
        // falls straight through to the else branch below, which is
        // the exact, byte-for-byte unchanged
        // `$this->httpClient->execute(...)` call this method has
        // always made.
        if ($provider instanceof RequiresBillableCallPipelineContract && $firm !== null) {
            $result = app(ProviderBillableCallPipeline::class)->execute(
                providerKey: $provider->key(),
                connection: $connection,
                firm: $firm,
                actor: $actor,
                product: 'webhook_subscribe',
                billingOperation: 'subscribe',
                environment: (new ProviderEnvironmentResolver)->modeFor($provider->key()),
                direction: SyncDirection::Outbound,
                resourceType: ResourceType::from($resourceType),
                providerCall: fn () => $this->httpClient->execute(
                    fn () => $provider->subscribe([
                        'connection' => $connection,
                        'resource_type' => $resourceType,
                    ]),
                    'subscribe',
                ),
                usageIdempotencyKey: 'provider_webhook_subscribe:'.$connection->id.':'.$resourceType.':cycle'.$subscribeCycle,
                provider: $provider,
                requiredContractFqcn: SupportsWebhooksContract::class,
            );

            $result = $result->response;
        } else {
            $result = $this->httpClient->execute(
                fn () => $provider->subscribe([
                    'connection' => $connection,
                    'resource_type' => $resourceType,
                ]),
                'subscribe',
            );
        }

        [$providerSubscriptionId, $expiresAt] = $this->extractSubscriptionState($result);

        $providerResourceRaw = $result['resource'] ?? null;
        $providerResource = (is_string($providerResourceRaw) && $providerResourceRaw !== '')
            ? $providerResourceRaw
            : $resourceType;

        $providerChangeTypeRaw = $result['change_type'] ?? null;
        $providerChangeType = (is_string($providerChangeTypeRaw) && $providerChangeTypeRaw !== '')
            ? $providerChangeTypeRaw
            : 'default';

        // Committed together with the subscribe() above by this
        // method's own transaction, so a later resource type's failure
        // can never undo an earlier one's subscription (which really
        // does exist at the provider by then).
        IntegrationProviderWebhookSubscription::query()->create([
            'firm_id' => $connection->firm_id,
            'firm_integration_id' => $connection->id,
            'provider_key' => $provider->key()->value,
            'resource_type' => $resourceType,
            'provider_resource' => $providerResource,
            'provider_change_type' => $providerChangeType,
            'provider_subscription_id' => $providerSubscriptionId,
            'expires_at' => $expiresAt,
            'status' => ProviderWebhookSubscriptionStatus::Active,
        ]);

        $this->events->record($connection->firm, 'integration_oauth.webhook_subscription_bootstrapped', $connection, $actor->user, [
            'firm_integration_id' => $connection->id,
            'resource_type' => $resourceType,
        ]);
    }

    /**
     * CHECKPOINT 8.2 (§A7b). Marks a connection as awaiting its webhook
     * bootstrap, from INSIDE the transaction that makes it Active — so the
     * "connected, push not yet live" state is durable the instant it
     * exists, and a crash between the commit and the bootstrap leaves a
     * connection that is honestly labelled rather than silently
     * push-dead.
     *
     * `NotRequired` is written when there is genuinely nothing to do, so
     * the column never implies a pending action that will never come.
     */
    private function markWebhookBootstrapPending(FirmIntegration $connection, IntegrationProviderContract $provider): void
    {
        $connection->forceFill([
            'webhook_bootstrap_state' => $this->webhookBootstrapResourceTypes($connection, $provider) === []
                ? WebhookBootstrapState::NotRequired
                : WebhookBootstrapState::Pending,
            'webhook_bootstrap_error' => null,
        ])->save();
    }

    /**
     * CHECKPOINT 8.2 (§A7b). Runs the webhook bootstrap AFTER the connect
     * transaction has committed, and never lets its failure undo the
     * connection.
     *
     * Outcomes, all recorded on the connection itself:
     *   - success                     -> `bootstrap_complete`
     *   - retryable provider failure  -> `bootstrap_pending_retry`, and a
     *                                    retry is queued
     *   - definite provider failure   -> `bootstrap_failed`
     *   - unknown outcome / gate says
     *     reconciliation              -> `bootstrap_reconciliation_required`,
     *                                    never retried automatically
     *
     * Only the SANITIZED failure category is stored (§A8) — never a
     * provider message. The connection stays Active throughout: scheduled
     * and manual syncs keep working, and the UI says plainly which part is
     * degraded (see `WebhookBootstrapState::firmFacingSummary()`).
     *
     * `$scheduleRetry` MUST be false when this call IS itself a retry —
     * see the dispatch site below for the recursion that taught us so.
     */
    public function runWebhookBootstrapAfterConnect(
        FirmIntegration $connection,
        int $currentUserId,
        int $firmId,
        bool $scheduleRetry = true,
    ): WebhookBootstrapState {
        return (new TenantContextService)->runWithFirmContextWithoutTransaction($firmId, function () use ($connection, $currentUserId, $firmId, $scheduleRetry) {
            $fresh = FirmIntegration::query()
                ->where('id', $connection->id)
                ->where('firm_id', $firmId)
                ->first();

            if ($fresh === null || $fresh->webhook_bootstrap_state === WebhookBootstrapState::NotRequired) {
                return WebhookBootstrapState::NotRequired;
            }

            if ($fresh->webhook_bootstrap_state === WebhookBootstrapState::ReconciliationRequired) {
                // A previous attempt's outcome is unknown. Retrying blindly
                // is exactly how duplicate provider-side subscriptions get
                // created; a human decides.
                return WebhookBootstrapState::ReconciliationRequired;
            }

            $provider = $this->resolveProvider($fresh);

            if (! $provider instanceof SupportsWebhooksContract) {
                $this->recordWebhookBootstrapState($fresh, WebhookBootstrapState::NotRequired, null);

                return WebhookBootstrapState::NotRequired;
            }

            try {
                $this->bootstrapWebhookSubscriptions($fresh, $provider, $currentUserId, Firm::query()->findOrFail($firmId));
            } catch (ProviderOperationRequiresReconciliationException $e) {
                $this->recordWebhookBootstrapState($fresh, WebhookBootstrapState::ReconciliationRequired, 'reconciliation_required');

                return WebhookBootstrapState::ReconciliationRequired;
            } catch (GmailMailboxAlreadyRoutedException $e) {
                // A LOCAL claim conflict, raised BEFORE any provider call
                // was made (§A7b): definite, never ambiguous. Nothing
                // exists at the provider to reconcile, and no retry will
                // change the answer while another connection still owns
                // that mailbox — so `failed`, not `pending_retry` and not
                // `reconciliation_required`.
                $this->recordWebhookBootstrapState($fresh, WebhookBootstrapState::Failed, 'mailbox_already_routed');

                return WebhookBootstrapState::Failed;
            } catch (SanitizedProviderHttpException $e) {
                $retryable = in_array($e->category(), [
                    SanitizedProviderHttpException::CATEGORY_RATE_LIMITED,
                    SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED,
                ], true);

                $uncertain = in_array($e->category(), [
                    SanitizedProviderHttpException::CATEGORY_TIMEOUT,
                    SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR,
                    SanitizedProviderHttpException::CATEGORY_CONNECTION_UNAVAILABLE,
                    SanitizedProviderHttpException::CATEGORY_UNKNOWN,
                    SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE,
                ], true);

                // An ambiguous outcome means a subscription may already
                // exist at the provider — that is a reconciliation, never a
                // retry.
                $state = match (true) {
                    $uncertain => WebhookBootstrapState::ReconciliationRequired,
                    $retryable => WebhookBootstrapState::PendingRetry,
                    default => WebhookBootstrapState::Failed,
                };

                $this->recordWebhookBootstrapState($fresh, $state, $e->category());

                // Only the FIRST attempt may schedule a retry. Found the
                // hard way: dispatching unconditionally meant a retry that
                // failed again queued another retry — and under a
                // synchronous queue driver that dispatch runs INLINE, so it
                // recursed until the process was killed. Even on a real
                // queue it was an uncapped, never-terminating retry chain.
                // BootstrapWebhookSubscriptionsJob's own $tries/backoff()
                // is the one repetition mechanism.
                if ($scheduleRetry && $state === WebhookBootstrapState::PendingRetry) {
                    BootstrapWebhookSubscriptionsJob::dispatch($fresh->id, $firmId, $currentUserId);
                }

                return $state;
            } catch (Throwable $e) {
                // Anything unsanitized is treated as ambiguous rather than
                // as a clean failure, for the same reason the outcome
                // normalizer does: we cannot prove the provider did not act.
                $this->recordWebhookBootstrapState($fresh, WebhookBootstrapState::ReconciliationRequired, 'unsanitized_failure');

                return WebhookBootstrapState::ReconciliationRequired;
            }

            $this->recordWebhookBootstrapState($fresh, WebhookBootstrapState::Complete, null);

            return WebhookBootstrapState::Complete;
        });
    }

    /**
     * CHECKPOINT 8.2 (§A7b). The retry entry point, used by
     * `BootstrapWebhookSubscriptionsJob` and safe for a UI action to call.
     * Refuses to touch a connection whose bootstrap needs a human
     * (`bootstrap_failed` is retryable only by explicit request, and
     * `bootstrap_reconciliation_required` never automatically).
     */
    public function retryWebhookBootstrap(int $connectionId, int $firmId, int $currentUserId, bool $force = false): WebhookBootstrapState
    {
        $connection = (new TenantContextService)->runWithFirmContext(
            $firmId,
            fn () => FirmIntegration::query()->where('id', $connectionId)->where('firm_id', $firmId)->first()
        );

        if ($connection === null) {
            return WebhookBootstrapState::NotRequired;
        }

        if (! $force && ! $connection->webhook_bootstrap_state->isRetryable()) {
            return $connection->webhook_bootstrap_state;
        }

        if ($connection->webhook_bootstrap_state === WebhookBootstrapState::ReconciliationRequired) {
            // Not even `force` may bypass this one: a blind retry here can
            // create a duplicate provider-side subscription.
            return WebhookBootstrapState::ReconciliationRequired;
        }

        // scheduleRetry: false — this IS the retry. Repetition is owned by
        // BootstrapWebhookSubscriptionsJob's $tries/backoff(), never by a
        // retry queueing its own successor.
        return $this->runWebhookBootstrapAfterConnect($connection, $currentUserId, $firmId, scheduleRetry: false);
    }

    /**
     * The resource types this connection would subscribe to — the same
     * intersection `bootstrapWebhookSubscriptions()` computes, extracted so
     * `markWebhookBootstrapPending()` can tell "nothing to do" from
     * "something to do" without duplicating the rule.
     *
     * @return list<string>
     */
    private function webhookBootstrapResourceTypes(FirmIntegration $connection, IntegrationProviderContract $provider): array
    {
        if (! $provider instanceof SupportsPullSyncContract) {
            return [];
        }

        return array_values(array_intersect(
            $provider->pullableResourceTypes(),
            $connection->requested_capabilities_json ?? [],
        ));
    }

    /**
     * Persists a bootstrap outcome in its own short transaction, with an
     * audit event. Stores only the sanitized category — never a provider
     * message or payload (§A8).
     */
    private function recordWebhookBootstrapState(
        FirmIntegration $connection,
        WebhookBootstrapState $state,
        ?string $sanitizedCategory,
    ): void {
        (new TenantContextService)->runWithFirmContext($connection->firm_id, function () use ($connection, $state, $sanitizedCategory) {
            $connection->forceFill([
                'webhook_bootstrap_state' => $state,
                'webhook_bootstrap_error' => $sanitizedCategory,
                'webhook_bootstrap_attempted_at' => now(),
            ])->save();

            $this->events->record(
                $connection->firm,
                'integration_oauth.webhook_bootstrap_state_changed',
                $connection,
                null,
                [
                    'firm_integration_id' => $connection->id,
                    'webhook_bootstrap_state' => $state->value,
                    'sanitized_category' => $sanitizedCategory,
                ],
            );
        });
    }

    /**
     * Byte-for-byte the same extraction discipline as
     * `RenewGraphSubscriptionJob::extractSubscriptionState()` (narrowly
     * duplicated here rather than shared — see
     * bootstrapWebhookSubscriptions()'s own docblock): `subscribe()`
     * (`SupportsWebhooksContract`) returns only an open
     * `array<string, mixed>` — "subscription state (e.g. subscription
     * id, expiry)", no fixed key names guaranteed by the interface. A
     * missing/unparseable required field is treated as a malformed-
     * response failure, propagating out and rolling back the enclosing
     * transaction, never silently persisted as a NULL against this
     * table's NOT NULL `expires_at` column.
     *
     * @param  array<string, mixed>  $result
     * @return array{0: string, 1: Carbon}
     */
    private function extractSubscriptionState(array $result): array
    {
        $subscriptionId = $result['subscription_id'] ?? null;
        $expiresAtRaw = $result['expires_at'] ?? null;

        if (! is_string($subscriptionId) || trim($subscriptionId) === '') {
            throw new RuntimeException('Provider returned a subscription result with no usable subscription_id.');
        }

        if (! is_string($expiresAtRaw) && ! $expiresAtRaw instanceof DateTimeInterface) {
            throw new RuntimeException('Provider returned a subscription result with no usable expires_at.');
        }

        try {
            $expiresAt = Carbon::parse($expiresAtRaw);
        } catch (Throwable) {
            throw new RuntimeException('Provider returned an unparseable expires_at value.');
        }

        return [$subscriptionId, $expiresAt];
    }

    /**
     * Checkpoint 7 addition (see enableWebhookRouting() above) — clears
     * both the plaintext-display column and the hashed routing-index
     * row in the SAME transaction. Idempotent: safe to call on a
     * connection with no routing token currently enabled.
     *
     * Checkpoint 10 addition — see enableWebhookRouting()'s own
     * docblock: identical authorization-gate rationale applies here.
     */
    public function disableWebhookRouting(FirmIntegration $connection, int $currentUserId): void
    {
        (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $currentUserId): void {
                $actor = $this->resolveActingFirmUser($currentUserId, $connection->firm_id);

                $this->entitlement->assertEnabled($connection->firm);
                $this->accessPolicy->assertCanConfigure($actor);

                $fresh = FirmIntegration::query()
                    ->where('id', $connection->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $fresh->update(['webhook_routing_token' => null]);

                IntegrationWebhookRoutingIndex::query()
                    ->where('firm_integration_id', $fresh->id)
                    ->delete();

                // Checkpoint 3 addition (FirmsVault Live Integrations,
                // Google Workspace) — see disconnect()'s identical
                // addition for the full rationale; this is the sibling
                // call site checkpoint3-combined-design.md §4.7 and
                // RowLevelSecurityCoverageMappingService's own registry
                // entry for GmailMailboxRoutingService both name
                // explicitly.
                $this->gmailMailboxRouting->unroute($fresh);

                // FirmsVault Live Integrations, Checkpoint 4 addition
                // ("Plaid financial evidence add-on" —
                // checkpoint4-combined-design.md §1.1.1/§6.5/§11) — see
                // disconnect()'s identical addition for the full
                // rationale; the sibling call site for
                // PlaidItemRoutingService.
                $this->plaidItemRouting->unroute($fresh);
            }
        );
    }

    /**
     * CSPRNG routing token: 32 raw bytes (256 bits) of entropy,
     * base64url-encoded without padding — byte-for-byte the same
     * construction as
     * IntegrationOAuthStateService::generateRawState(), per frozen
     * design §4's explicit requirement that this checkpoint stop using
     * `Str::random()` for routing-token generation.
     */
    private function generateRawWebhookRoutingToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * The ONLY place firm_integrations.status is ever written. Guards
     * against illegal transitions via the internal TRANSITIONS table
     * (structural guard, layered on top of — never a replacement for —
     * the caller's own specific business-rule checks above).
     * $extraAttributes lets a caller fold in non-status columns (e.g.
     * external_account_id, connected_at, disconnected_at) into the same
     * update() call without this method losing its "sole writer"
     * property (only status-carrying updates need to funnel through
     * here; a later, separate update() that never touches status is
     * fine, but every caller in this class routes through this method
     * anyway for consistency).
     *
     * @param  array<string, mixed>  $extraAttributes
     */
    private function transitionStatus(
        FirmIntegration $connection,
        ConnectionStatus $target,
        ?string $reason = null,
        array $extraAttributes = [],
    ): FirmIntegration {
        $current = $connection->status;

        if ($current !== $target) {
            $allowed = self::TRANSITIONS[$current->value] ?? [];

            if (! in_array($target->value, $allowed, true)) {
                throw new RuntimeException(
                    "Illegal connection status transition for firm_integration {$connection->id}: ".
                    "{$current->value} -> {$target->value}."
                );
            }
        }

        $connection->update(array_merge($extraAttributes, [
            'status' => $target,
            'error_reason' => in_array($target, [ConnectionStatus::Error, ConnectionStatus::ReauthorizationRequired, ConnectionStatus::ScopeInsufficient], true)
                ? $reason
                : null,
        ]));

        return $connection->fresh();
    }

    /**
     * Genuine defect found and fixed during Checkpoint 2 test-writing
     * (checkpoint2-diff-review.md, "production bug found" — surfaced
     * by the new tenant-mismatch code faithfully copying an existing,
     * already-latent bug in the sibling account-mismatch branch).
     *
     * The account-mismatch and tenant-mismatch rejection branches in
     * finishCallback() throw WITHOUT writing anything (see their own
     * comments) — deliberately, because finishCallback() runs inside a
     * lockForUpdate() on $connection, held for the WHOLE ambient
     * transaction (completeOAuthCallback()'s
     * TenantContextService::runWithFirmContext()/DB::transaction()
     * wrap). An earlier version of this fix tried writing the durable
     * Error-transition + audit event on the separate `pgsql_audit`
     * connection (TimelineEventRecorder::recordOnIndependentConnection()'s
     * own established technique) from INSIDE that same still-open
     * transaction — but a write from a second session against a row
     * already lockForUpdate()-locked by the first session's still-open
     * transaction blocks until that transaction ends, and that
     * transaction cannot end until finishCallback() itself returns —
     * a genuine self-deadlock, not merely a test-environment artifact.
     *
     * Correct fix: this method is called from completeOAuthCallback()'s
     * OWN catch block, AFTER runWithFirmContext()'s DB::transaction()
     * has already caught the mismatch exception, rolled back (releasing
     * the lock), and re-thrown it to the caller — at that point the row
     * is unlocked and a perfectly ordinary write on the DEFAULT
     * connection (a fresh runWithFirmContext() call of its own) commits
     * normally, no independent-connection trick needed at all.
     */
    private function recordMismatchRejectionAfterRollback(int $firmId, int $connectionId, int $actorFirmUserId, string $eventType, string $reason): void
    {
        (new TenantContextService)->runWithFirmContext($firmId, function () use ($connectionId, $actorFirmUserId, $eventType, $reason): void {
            $connection = FirmIntegration::query()->find($connectionId);

            if ($connection === null) {
                return;
            }

            $this->transitionStatus($connection, ConnectionStatus::Error, $reason);

            $actor = FirmUser::query()->find($actorFirmUserId);

            $this->events->record($connection->firm, $eventType, $connection, $actor?->user, [
                'firm_integration_id' => $connection->id,
            ]);
        });
    }

    /**
     * Resolves a User's FirmUser membership for a SPECIFIC firm — never
     * "their first active membership anywhere" (unlike
     * User::activeFirmUser(), which is deliberately not reused here for
     * that reason). MUST be called from within an already-active
     * runWithFirmContext($firmId, ...) call — relies on the ordinary,
     * already-tenant-scoped firm_users RLS policy, not the self-lookup
     * bootstrap carve-out (which is ONLY consulted for SELECT with no
     * firm context active at all; once real firm context is active, the
     * base tenant-isolation policy already covers this read).
     */
    private function resolveActingFirmUser(int $userId, int $firmId): FirmUser
    {
        $firmUser = FirmUser::query()
            ->where('user_id', $userId)
            ->where('firm_id', $firmId)
            ->where('status', FirmUserStatus::Active->value)
            ->first();

        if ($firmUser === null) {
            throw new RuntimeException("User {$userId} has no active FirmUser membership in firm {$firmId}.");
        }

        return $firmUser;
    }

    private function resolveProvider(FirmIntegration $connection): IntegrationProviderContract
    {
        $code = $connection->integrationProvider?->code;

        if ($code === null) {
            throw new RuntimeException("Connection {$connection->id} has no resolvable integration_provider.");
        }

        return $this->providerRegistry->get(ProviderKey::from($code));
    }

    /**
     * The single, hardcoded, deterministic OAuth callback URL this
     * application registers with every provider — NEVER computed from
     * the claimed state row's own value (that would defeat the point of
     * re-validating it against something independent). Recomputed fresh
     * on every call from the app's own named route, matching exactly
     * what App\Http\Controllers\Integrations\OAuthConnectionController's
     * initiate() action used to build the original redirect_uri.
     */
    private function expectedRedirectUri(): string
    {
        return route('integrations.oauth.callback', [], true);
    }

    /**
     * @return string[]
     */
    private function parseScopes(string $scopeString): array
    {
        return array_values(array_filter(explode(' ', trim($scopeString)), static fn (string $s): bool => $s !== ''));
    }
}
