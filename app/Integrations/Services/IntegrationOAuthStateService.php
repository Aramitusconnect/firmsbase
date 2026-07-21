<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Data\ConsumedOAuthState;
use App\Integrations\Data\OAuthInitiationResult;
use App\Integrations\Exceptions\OAuthStateAlreadyConsumedException;
use App\Integrations\Exceptions\OAuthStateExpiredException;
use App\Integrations\Exceptions\OAuthStateNotFoundException;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationOAuthState;
use App\Integrations\Support\PkceService;
use App\Integrations\Support\ProviderRedirectUrlValidator;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Services\EmailBodyEncryptionService;
use App\Services\TenantContextService;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * IntegrationOAuthStateService — the ONLY writer/reader of
 * `integration_oauth_states` (Checkpoint 5,
 * checkpoint-00-final-specification.md §5/§12; frozen-design-post-review.md;
 * agent-h-security-architecture-review.md). Deliberately provider-
 * agnostic: it never depends on App\Integrations\Core\ProviderRegistry
 * or resolves a provider instance itself — initiate() accepts the
 * authorization-URL-building step as a caller-supplied closure, so this
 * class owns ONLY state generation/persistence/atomic consumption, and
 * App\Integrations\Services\ProviderConnectionService (the actual
 * orchestrator) owns provider resolution.
 *
 * State hashing (frozen-design-post-review.md item 8; Agent H review
 * item 7 — REJECTS Agent D's raw-UUID design): the raw CSPRNG `state`
 * token is generated here, handed to the caller-supplied closure so it
 * can be embedded in the provider's authorization URL, and is NEVER
 * written to the database in any form — only `hash('sha256', $rawState)`
 * is persisted (opaque_token_hash). Uses `random_bytes()` directly
 * (never `Str::random()`'s default path), matching
 * PkceService::generateVerifier()'s own discipline, for >=256 bits of
 * entropy.
 *
 * Callback bootstrap (frozen-design-post-review.md item 7; Agent H
 * review item 2): resolveAndConsume() is the two-step
 * TenantContextService bootstrap in full —
 * withUserContext($currentUserId, ...) to locate the row by BOTH the
 * self-lookup RLS predicate AND an explicit opaque_token_hash match
 * (never relying on the RLS predicate as the sole filter — see that
 * review item for why), then runWithFirmContext($row->firm_id, ...) to
 * perform the atomic one-time claim.
 *
 * Atomic one-time consumption (frozen-design-post-review.md item 10):
 * a single `UPDATE ... WHERE id = ? AND consumed_at IS NULL AND
 * expires_at > now() RETURNING *` raw statement — never a SELECT
 * followed by a separate UPDATE, never a bare ->update() call (which
 * returns only an affected-row count, not the row itself). PKCE
 * verifier nulling (verifier_ciphertext/encryption_key_id -> NULL) is a
 * second statement issued immediately after, inside the SAME
 * transaction runWithFirmContext() already opened — deliberately NOT
 * folded into the same single UPDATE statement, because PostgreSQL's
 * RETURNING clause reflects the POST-update row: nulling the verifier
 * columns in the same statement that claims the row would make
 * RETURNING hand back NULL for them, losing the very ciphertext this
 * method still needs to decrypt. "Same transaction" (frozen §12), not
 * "same statement", is the actual requirement, and is satisfied exactly
 * this way.
 */
class IntegrationOAuthStateService
{
    private const DEFAULT_TTL_MINUTES = 10;

    private const MAX_TTL_MINUTES = 30;

    public function __construct(
        private readonly EmailBodyEncryptionService $encryption,
        private readonly PkceService $pkce,
        private readonly ProviderRedirectUrlValidator $redirectValidator,
    ) {
    }

    /**
     * Generates a fresh raw state token and PKCE verifier/challenge
     * pair, persists the hashed state + encrypted verifier, and asks
     * $buildAuthorizationUrl (supplied by ProviderConnectionService,
     * which alone knows how to resolve the target provider) to build
     * the final authorization URL from the raw state and challenge.
     *
     * Must be called from within an already-active firm tenant context
     * (ProviderConnectionService::initiateOAuthConnection() wraps its
     * entire call in runWithFirmContext($connection->firm_id, ...)) —
     * this method does not establish its own, since it also needs to
     * write the row under that same context/transaction the caller
     * already owns.
     *
     * @param  Closure(string $rawState, string $codeChallenge): string $buildAuthorizationUrl
     */
    public function initiate(
        FirmIntegration $connection,
        FirmUser $initiatingFirmUser,
        string $redirectUri,
        Closure $buildAuthorizationUrl,
        ?int $ttlMinutes = null,
    ): OAuthInitiationResult {
        if ((int) $initiatingFirmUser->firm_id !== (int) $connection->firm_id) {
            throw new RuntimeException(
                "FirmUser {$initiatingFirmUser->id} does not belong to the same firm as connection {$connection->id}."
            );
        }

        // Re-validated fresh here (never a cached boolean) — see
        // ProviderRedirectUrlValidator's own class docblock for why
        // this discipline matters even though redirect_uri is always
        // app-controlled, never request-suppliable.
        $this->redirectValidator->assertSafe($redirectUri);

        $rawState = $this->generateRawState();
        $verifier = $this->pkce->generateVerifier();
        $codeChallenge = $this->pkce->challengeForVerifier($verifier);

        $encryptionResult = $this->encryption->encrypt($connection->firm, $verifier);

        if (! $encryptionResult->succeeded) {
            throw new RuntimeException("Cannot initiate OAuth state: {$encryptionResult->reason}");
        }

        $expiresAt = now()->addMinutes($this->clampTtlMinutes($ttlMinutes));

        $state = IntegrationOAuthState::create([
            'firm_id' => $connection->firm_id,
            'firm_integration_id' => $connection->id,
            'initiating_user_id' => $initiatingFirmUser->user_id,
            'initiating_firm_user_id' => $initiatingFirmUser->id,
            'opaque_token_hash' => hash('sha256', $rawState),
            'redirect_uri' => $redirectUri,
            'verifier_ciphertext' => $encryptionResult->ciphertext,
            'encryption_key_id' => $encryptionResult->encryptionKeyId,
            'expires_at' => $expiresAt,
            'consumed_at' => null,
        ]);

        $authorizationUrl = $buildAuthorizationUrl($rawState, $codeChallenge);

        return new OAuthInitiationResult($authorizationUrl, $state->id, $expiresAt);
    }

    /**
     * The full two-step bootstrap + atomic one-time claim. See class
     * docblock for the exact shape and reasoning.
     */
    public function resolveAndConsume(string $rawState, int $currentUserId): ConsumedOAuthState
    {
        $hash = hash('sha256', $rawState);
        $tenantContext = new TenantContextService();

        $row = $tenantContext->withUserContext(
            $currentUserId,
            fn () => IntegrationOAuthState::query()
                ->where('opaque_token_hash', $hash)
                ->first()
        );

        if ($row === null) {
            throw new OAuthStateNotFoundException();
        }

        return $tenantContext->runWithFirmContext(
            $row->firm_id,
            fn () => $this->claimAndDecrypt($row->id, $row->firm_id)
        );
    }

    private function claimAndDecrypt(int $stateId, int $firmId): ConsumedOAuthState
    {
        $claimed = DB::selectOne(
            'UPDATE integration_oauth_states '.
            'SET consumed_at = now() '.
            'WHERE id = ? AND consumed_at IS NULL AND expires_at > now() '.
            'RETURNING *',
            [$stateId]
        );

        if ($claimed === null) {
            $this->diagnoseClaimFailure($stateId);
        }

        // Same transaction as the atomic claim above (runWithFirmContext
        // already opened one covering this whole callback) — see class
        // docblock for why this is a second statement, not folded into
        // the first.
        DB::statement(
            'UPDATE integration_oauth_states SET verifier_ciphertext = NULL, encryption_key_id = NULL WHERE id = ?',
            [$stateId]
        );

        $firm = Firm::query()->findOrFail($firmId);

        $verifierPlaintext = $this->encryption->decrypt(
            $firm,
            $claimed->verifier_ciphertext,
            (int) $claimed->encryption_key_id,
        );

        return new ConsumedOAuthState(
            id: (int) $claimed->id,
            firmId: (int) $claimed->firm_id,
            firmIntegrationId: (int) $claimed->firm_integration_id,
            initiatingUserId: (int) $claimed->initiating_user_id,
            initiatingFirmUserId: (int) $claimed->initiating_firm_user_id,
            redirectUri: (string) $claimed->redirect_uri,
            pkceVerifierPlaintext: $verifierPlaintext,
            consumedAt: now(),
        );
    }

    /**
     * Runs ONLY after the atomic claim above already failed (affected
     * zero rows) — this read-only follow-up is not itself the
     * authorization/atomicity boundary, it exists purely to pick the
     * correct, specific exception type for accurate audit-event typing
     * (integration_oauth.state_expired vs
     * integration_oauth.state_replay_rejected). Not an enumeration risk:
     * $stateId was already legitimately resolved for this exact caller
     * by resolveAndConsume()'s Step-A lookup before this method is ever
     * reached.
     */
    private function diagnoseClaimFailure(int $stateId): never
    {
        $existing = IntegrationOAuthState::query()->find($stateId);

        if ($existing === null) {
            throw new OAuthStateNotFoundException();
        }

        if ($existing->consumed_at !== null) {
            throw new OAuthStateAlreadyConsumedException();
        }

        throw new OAuthStateExpiredException();
    }

    /**
     * CSPRNG raw state token: 32 raw bytes (256 bits) of entropy,
     * base64url-encoded without padding. Never written to the database
     * in any form (see class docblock) — only its sha256 digest is.
     */
    private function generateRawState(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function clampTtlMinutes(?int $ttlMinutes): int
    {
        if ($ttlMinutes === null) {
            return self::DEFAULT_TTL_MINUTES;
        }

        return max(1, min($ttlMinutes, self::MAX_TTL_MINUTES));
    }
}
