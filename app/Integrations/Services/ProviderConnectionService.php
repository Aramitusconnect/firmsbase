<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Enums\FirmUserStatus;
use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsDisconnectContract;
use App\Integrations\Contracts\SupportsPullSyncContract;
use App\Integrations\Contracts\SupportsWebhooksContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\ConsumedOAuthState;
use App\Integrations\Data\OAuthCallbackResult;
use App\Integrations\Data\OAuthInitiationResult;
use App\Integrations\Data\ProviderMetadata;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Enums\ProviderWebhookSubscriptionStatus;
use App\Integrations\Exceptions\OAuthAccountMismatchException;
use App\Integrations\Exceptions\OAuthRedirectUriMismatchException;
use App\Integrations\Exceptions\OAuthTenantMismatchException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\IntegrationProviderWebhookSubscription;
use App\Integrations\Models\IntegrationWebhookRoutingIndex;
use App\Integrations\Support\GmailMailboxRoutingService;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\ProviderRedirectUrlValidator;
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
    ) {}

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
            return (new TenantContextService)->runWithFirmContext(
                $consumed->firmId,
                fn () => $this->finishCallback($consumed, $authorizationCode, $currentUserId)
            );
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
        if ($provider instanceof SupportsWebhooksContract) {
            $this->enableWebhookRouting($connection, $currentUserId);
            $this->bootstrapWebhookSubscriptions($connection, $provider, $currentUserId);
        }

        return new OAuthCallbackResult($connection, $connection->status, $scopeSatisfied);
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
    private function bootstrapWebhookSubscriptions(
        FirmIntegration $connection,
        IntegrationProviderContract $provider,
        int $currentUserId,
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
            $alreadyActive = IntegrationProviderWebhookSubscription::query()
                ->where('firm_integration_id', $connection->id)
                ->where('resource_type', $resourceType)
                ->where('status', ProviderWebhookSubscriptionStatus::Active->value)
                ->exists();

            if ($alreadyActive) {
                continue;
            }

            $result = $this->httpClient->execute(
                fn () => $provider->subscribe([
                    'connection' => $connection,
                    'resource_type' => $resourceType,
                ]),
                'subscribe',
            );

            [$providerSubscriptionId, $expiresAt] = $this->extractSubscriptionState($result);

            $providerResourceRaw = $result['resource'] ?? null;
            $providerResource = (is_string($providerResourceRaw) && $providerResourceRaw !== '')
                ? $providerResourceRaw
                : $resourceType;

            $providerChangeTypeRaw = $result['change_type'] ?? null;
            $providerChangeType = (is_string($providerChangeTypeRaw) && $providerChangeTypeRaw !== '')
                ? $providerChangeTypeRaw
                : 'default';

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
