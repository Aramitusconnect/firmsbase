<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Enums\FirmUserStatus;
use App\Integrations\Contracts\IntegrationProviderContract;
use App\Integrations\Contracts\SupportsDisconnectContract;
use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Data\ConsumedOAuthState;
use App\Integrations\Data\OAuthCallbackResult;
use App\Integrations\Data\OAuthInitiationResult;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Exceptions\OAuthAccountMismatchException;
use App\Integrations\Exceptions\OAuthRedirectUriMismatchException;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationWebhookRoutingIndex;
use App\Integrations\Support\OutboundProviderHttpClient;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\FirmUser;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use DateTimeInterface;
use RuntimeException;

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
    ) {
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
        return (new TenantContextService())->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $currentUserId, $redirectUri) {
                $actor = $this->resolveActingFirmUser($currentUserId, $connection->firm_id);

                $this->accessPolicy->assertCanConnect($actor);

                $provider = $this->resolveProvider($connection);

                $result = $this->stateService->initiate(
                    $connection,
                    $actor,
                    $redirectUri,
                    fn (string $rawState, string $codeChallenge) => $provider->authorizationUrl([
                        'client_id' => $connection->integration_provider_id,
                        'redirect_uri' => $redirectUri,
                        'response_type' => 'code',
                        'state' => $rawState,
                        'code_challenge' => $codeChallenge,
                        'code_challenge_method' => 'S256',
                        'scope' => implode(' ', $provider->requiredScopes()),
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

        return (new TenantContextService())->runWithFirmContext(
            $consumed->firmId,
            fn () => $this->finishCallback($consumed, $authorizationCode, $currentUserId)
        );
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
            throw new OAuthRedirectUriMismatchException();
        }

        $provider = $this->resolveProvider($connection);

        $tokenSet = $this->httpClient->execute(
            fn () => $provider->exchangeCodeForToken($authorizationCode, [
                'code_verifier' => $consumed->pkceVerifierPlaintext,
                'redirect_uri' => $consumed->redirectUri,
            ]),
            'exchangeCodeForToken',
        );

        $returnedAccountId = $tokenSet['external_account_id'] ?? null;

        if ($connection->external_account_id !== null
            && $returnedAccountId !== null
            && ! hash_equals((string) $connection->external_account_id, (string) $returnedAccountId)) {
            $this->transitionStatus($connection, ConnectionStatus::Error, 'Provider account mismatch on reauthorization.');

            $this->events->record($connection->firm, 'integration_oauth.provider_account_mismatch', $connection, $actor->user, [
                'firm_integration_id' => $connection->id,
            ]);

            throw new OAuthAccountMismatchException();
        }

        $grantedScopes = $this->parseScopes($tokenSet['scope'] ?? '');
        $requiredScopes = $provider->requiredScopes();
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
     */
    public function refreshConnectionToken(FirmIntegration $connection): OAuthCallbackResult
    {
        return (new TenantContextService())->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection) {
                try {
                    $this->credentialService->withRefreshLock($connection, function (FirmIntegration $locked) {
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
                            return $accessCredential;
                        }

                        if ($accessCredential === null) {
                            throw new RuntimeException("No active access token for connection {$locked->id}.");
                        }

                        $refreshTokenPlaintext = $this->credentialService->decryptForOperation(
                            $locked,
                            $refreshCredential,
                            (string) $locked->id.'-refresh-'.now()->timestamp,
                            'oauth_token_refresh',
                        );

                        $provider = $this->resolveProvider($locked);

                        $tokenSet = $this->httpClient->execute(
                            fn () => $provider->refreshToken($refreshTokenPlaintext, ['firm_integration_id' => $locked->id]),
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

                        return $newAccessCredential;
                    });

                    $fresh = $connection->fresh();

                    $this->events->record($fresh->firm, 'integration_oauth.refresh_succeeded', $fresh, null, [
                        'firm_integration_id' => $fresh->id,
                    ]);

                    return new OAuthCallbackResult($fresh, $fresh->status, true);
                } catch (SanitizedProviderHttpException $e) {
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

                    return new OAuthCallbackResult($fresh, $fresh->status, false, 'Token refresh failed; reauthorization is required.');
                }
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
     */
    public function disconnect(FirmIntegration $connection, int $currentUserId): FirmIntegration
    {
        return (new TenantContextService())->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $currentUserId) {
                $actor = $this->resolveActingFirmUser($currentUserId, $connection->firm_id);

                $this->accessPolicy->assertCanDisconnect($actor);

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
                    } catch (SanitizedProviderHttpException) {
                        // Best-effort: local teardown proceeds regardless
                        // of whether the (simulated) remote revoke
                        // succeeded.
                    }
                }

                foreach (IntegrationCredential::query()
                    ->where('firm_integration_id', $fresh->id)
                    ->where('status', IntegrationCredentialStatus::Active->value)
                    ->get() as $credential) {
                    $this->credentialService->revoke($fresh, $credential, 'disconnect');
                }

                $fresh = $this->transitionStatus($fresh, ConnectionStatus::Disconnected, null, [
                    'disconnected_at' => now(),
                    'webhook_routing_token' => null,
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

                $this->events->record($fresh->firm, 'integration_oauth.disconnect', $fresh, $actor->user, [
                    'firm_integration_id' => $fresh->id,
                ]);

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
     */
    public function enableWebhookRouting(FirmIntegration $connection): string
    {
        return (new TenantContextService())->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection) {
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
     * Checkpoint 7 addition (see enableWebhookRouting() above) — clears
     * both the plaintext-display column and the hashed routing-index
     * row in the SAME transaction. Idempotent: safe to call on a
     * connection with no routing token currently enabled.
     */
    public function disableWebhookRouting(FirmIntegration $connection): void
    {
        (new TenantContextService())->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection): void {
                $fresh = FirmIntegration::query()
                    ->where('id', $connection->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $fresh->update(['webhook_routing_token' => null]);

                IntegrationWebhookRoutingIndex::query()
                    ->where('firm_integration_id', $fresh->id)
                    ->delete();
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
     * @param  array<string, mixed> $extraAttributes
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
