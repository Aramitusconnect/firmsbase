<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Support\ProviderEnvironmentResolver;
use App\Services\EmailBodyEncryptionService;
use App\Services\TenantContextService;
use App\Services\TimelineEventRecorder;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * IntegrationCredentialService — the only writer of
 * `integration_credentials` (Checkpoint 4,
 * checkpoint-00-final-specification.md §5/§10;
 * frozen-design-post-review.md; agent-f-security-review.md). Reuses
 * EmailBodyEncryptionService EXACTLY as-is (same chain
 * AiProviderKeyService/WebhookSecretService already reuse) — no second
 * encryption system. Every public method fails closed: verification
 * failures throw \RuntimeException (or \InvalidArgumentException for
 * malformed input) with a clear, non-secret-containing message, and
 * nothing is persisted when a check fails.
 *
 * Constructor mirrors AiProviderKeyService/WebhookSecretService's exact
 * shape: EmailBodyEncryptionService is the one dependency every method
 * needs (it in turn already owns its own EncryptionKeyService
 * dependency internally — nothing else is required here).
 *
 * Masked metadata provenance (frozen-design-post-review.md, "Masked
 * metadata provenance"): `masked_display_metadata` may ONLY be
 * populated from the caller-supplied $metadata array passed into
 * store()/replace() — genuinely non-secret, caller-provided fields
 * (e.g. a provider-supplied display label, granted scopes, expiry).
 * It is NEVER derived from, or computed as a substring/hash/truncation
 * of, $plaintextSecret. getMaskedMetadata() performs no decrypt call at
 * all and only ever reads this already-non-secret column back.
 *
 * Checkpoint 1 (FirmsVault Live Integrations) additions
 * (checkpoint1-design-oauth-security-review.md §6;
 * checkpoint1-security-review.md Finding 3/Finding 6):
 * `TimelineEventRecorder` is a new constructor dependency, used solely
 * to record a `integration_credential.decrypted` audit event inside
 * decryptForOperation() — the SAME class every OAuth event in
 * ProviderConnectionService already uses, no new audit subsystem.
 * `credential_environment_mode` is a new, dedicated, DB-CHECK-constrained
 * column (never the open `$metadata` bag — see that migration's own
 * docblock for why) populated only via the new `$environmentMode`
 * parameter on store()/rotate(), and consulted by a new mode-consistency
 * check inside decryptForOperation(). `App\Integrations\Support\ProviderEnvironmentResolver`
 * is intentionally NOT a constructor dependency — it is stateless and
 * zero-config, so it is instantiated inline exactly where needed,
 * mirroring this file's own established `new TenantContextService()`
 * convention rather than growing the constructor for a service used by
 * exactly one method.
 */
class IntegrationCredentialService
{
    public function __construct(
        private readonly EmailBodyEncryptionService $encryption,
        private readonly TimelineEventRecorder $events,
    ) {}

    /**
     * Creates a new Active credential row for $connection.
     *
     * Verification: $connection->status must not be Disconnected or
     * Error (a connection in either state is not a valid target for new
     * credential material). The entire body is wrapped in one outer
     * runWithFirmContext($connection->firm_id, ...) call — mirrors
     * AiProviderKeyService::generate()'s exact convention (the whole
     * call, not just the final ::create() argument) — and
     * TenantContextService::runWithFirmContext() is safe to nest (it
     * saves/restores whatever context was active before it ran), so
     * replace()/rotate() calling this method internally from within
     * their own outer wrap works correctly, exactly like
     * AiProviderKeyService::rotate() calling generate() internally.
     *
     * masked_display_metadata is built ONLY from the passed-in
     * $metadata array (see class docblock's "Masked metadata
     * provenance" section) — never from $plaintextSecret.
     */
    public function store(
        FirmIntegration $connection,
        CredentialType $type,
        string $plaintextSecret,
        array $metadata = [],
        ?DateTimeInterface $expiresAt = null,
        // Checkpoint 1 (FirmsVault Live Integrations) addition
        // (checkpoint1-security-review.md Finding 3): additive, OPTIONAL,
        // trailing typed param — NEVER folded into $metadata, which has
        // no tamper-evidence at all (see this table's
        // credential_environment_mode migration docblock). Every
        // existing caller that omits it preserves today's exact
        // behavior (a null, untagged credential).
        ?string $environmentMode = null,
    ): IntegrationCredential {
        $this->assertValidEnvironmentMode($environmentMode);

        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $type, $plaintextSecret, $metadata, $expiresAt, $environmentMode) {
                $this->assertConnectionUsable($connection);

                $result = $this->encryption->encrypt($connection->firm, $plaintextSecret);

                if (! $result->succeeded) {
                    throw new RuntimeException("Cannot store integration credential: {$result->reason}");
                }

                return IntegrationCredential::create([
                    'firm_id' => $connection->firm_id,
                    'firm_integration_id' => $connection->id,
                    'credential_type' => $type,
                    'encrypted_payload_ciphertext' => $result->ciphertext,
                    'encryption_key_id' => $result->encryptionKeyId,
                    'status' => IntegrationCredentialStatus::Active,
                    'credential_environment_mode' => $environmentMode,
                    'masked_display_metadata' => $metadata === [] ? null : $metadata,
                    'expires_at' => $expiresAt,
                ]);
            }
        );
    }

    /**
     * Human-initiated credential replacement: marks $existing Rotated
     * (status/rotated_at only — never touches its ciphertext, so the
     * model's immutability guard does not block this normal case) and
     * stores a brand-new Active row via store(). Shares
     * rotateExistingCredential() with rotate() below — see that
     * method's docblock for why both exist as separate named entry
     * points over one shared implementation.
     */
    public function replace(
        FirmIntegration $connection,
        IntegrationCredential $existing,
        string $newPlaintextSecret,
        array $metadata = [],
        ?DateTimeInterface $expiresAt = null,
    ): IntegrationCredential {
        // Environment mode is carried forward from $existing, same
        // "carry forward unless explicitly changed" posture rotate()
        // uses for masked_display_metadata — a human replacing a
        // credential's SECRET is not, by itself, evidence they intend to
        // also change which sandbox/live environment it was issued for.
        return $this->rotateExistingCredential(
            $connection, $existing, $newPlaintextSecret, $metadata, $expiresAt, $existing->credential_environment_mode
        );
    }

    /**
     * System-initiated credential refresh (e.g. an OAuth access-token
     * refresh performed by a background job): identical verification
     * and behavior to replace() — both share rotateExistingCredential()
     * below — kept as a separate named entry point purely so call
     * sites read clearly (a human replacing a credential vs. a system
     * refreshing one), per the frozen design.
     *
     * rotate() carries the existing credential's own
     * masked_display_metadata forward unchanged (a system-initiated
     * refresh has no new caller-supplied display metadata to give —
     * unlike replace(), which always receives one from its human
     * caller) rather than wiping it to null.
     *
     * Throws \RuntimeException if $existing->status !== Active (mirrors
     * AiProviderKeyService::rotate()'s identical guard).
     */
    public function rotate(
        FirmIntegration $connection,
        IntegrationCredential $existing,
        string $newPlaintextSecret,
        ?DateTimeInterface $expiresAt = null,
        // Checkpoint 1 (FirmsVault Live Integrations) addition
        // (checkpoint1-security-review.md Finding 3): additive,
        // OPTIONAL, trailing typed param. When omitted (the common
        // system-initiated-refresh case), $existing's own
        // credential_environment_mode is carried forward unchanged —
        // identical "carry forward, never wipe" posture already
        // established for masked_display_metadata below.
        ?string $environmentMode = null,
    ): IntegrationCredential {
        $this->assertValidEnvironmentMode($environmentMode);

        return $this->rotateExistingCredential(
            $connection,
            $existing,
            $newPlaintextSecret,
            $existing->masked_display_metadata ?? [],
            $expiresAt,
            $environmentMode ?? $existing->credential_environment_mode,
        );
    }

    /**
     * Shared implementation for replace()/rotate(). Verifies
     * $existing->firm_integration_id === $connection->id and
     * $existing->status === Active (throws \RuntimeException
     * otherwise), marks $existing Rotated, then delegates to store()
     * for the new row. Wrapped in its own outer
     * runWithFirmContext($connection->firm_id, ...) call, same as
     * store() — safe to nest with store()'s own inner wrap, per
     * TenantContextService::runWithFirmContext()'s documented restore
     * behavior.
     */
    private function rotateExistingCredential(
        FirmIntegration $connection,
        IntegrationCredential $existing,
        string $newPlaintextSecret,
        array $metadata,
        ?DateTimeInterface $expiresAt,
        ?string $environmentMode = null,
    ): IntegrationCredential {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $existing, $newPlaintextSecret, $metadata, $expiresAt, $environmentMode) {
                $this->assertCredentialBelongsToConnection($existing, $connection);

                if ($existing->status !== IntegrationCredentialStatus::Active) {
                    throw new RuntimeException(
                        "Cannot rotate credential {$existing->id}: only an Active credential can be rotated."
                    );
                }

                // Mark the OLD row Rotated FIRST — the partial unique
                // index only allows one 'active' row per
                // (firm_integration_id, credential_type), so the new
                // Active row cannot be inserted while the old one is
                // still Active. Only status/rotated_at change here —
                // never ciphertext/encryption_key_id — so the model's
                // immutability guard does not block this update.
                $existing->update([
                    'status' => IntegrationCredentialStatus::Rotated,
                    'rotated_at' => now(),
                ]);

                return $this->store($connection, $existing->credential_type, $newPlaintextSecret, $metadata, $expiresAt, $environmentMode);
            }
        );
    }

    /**
     * Idempotent revocation: verifies ONLY that $credential belongs to
     * $connection (no status precondition) — re-revoking an
     * already-Revoked row is a safe no-op and returns the row
     * unchanged, rather than throwing, so callers never need to
     * pre-check status before calling this method.
     *
     * $reason is accepted and validated only for shape by the caller
     * contract; it is not persisted anywhere by this checkpoint (no
     * revocation-reason column exists on this table, and no audit-log
     * table/service exists yet for credential lifecycle events — see
     * decryptForOperation()'s docblock for the identical, disclosed
     * deferral).
     */
    public function revoke(FirmIntegration $connection, IntegrationCredential $credential, string $reason): IntegrationCredential
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $credential) {
                $this->assertCredentialBelongsToConnection($credential, $connection);

                if ($credential->status === IntegrationCredentialStatus::Revoked) {
                    return $credential;
                }

                $credential->update([
                    'status' => IntegrationCredentialStatus::Revoked,
                    'revoked_at' => now(),
                ]);

                return $credential->fresh();
            }
        );
    }

    /**
     * The ONLY path back to plaintext credential material in this
     * service. Callers MUST keep the return value in memory only for
     * the duration of the current operation — never log it, never
     * persist it, never assign it to a job property, never include it
     * in an exception message.
     *
     * Verification, in order: (1) the ambient tenant context's current
     * firm matches $connection->firm_id; (2) $credential belongs to
     * $connection; (3) $connection->status is Active; (4)
     * $credential->status is Active; (5) $operationId and $reason are
     * both non-empty AND pass the closed-shape audit-label validation
     * below (\InvalidArgumentException otherwise); (6) $credential's
     * tagged environment mode (if any) is consistent with the
     * connection's provider's CURRENTLY configured mode (if any).
     * Relies on ambient caller-supplied tenant context (check (1)
     * enforces that one is actually active and correct) rather than
     * establishing its own — a decrypt is a read performed on behalf of
     * an already-in-flight caller operation, not an independent unit of
     * work that should silently swap tenant context underneath it.
     *
     * Checkpoint 1 (FirmsVault Live Integrations) additions
     * (checkpoint1-design-oauth-security-review.md §6;
     * checkpoint1-security-review.md Finding 6):
     *
     *   - $operationId/$reason, beyond the pre-existing non-emptiness
     *     guard, are now ALSO capped at 128 characters and rejected if
     *     either contains a contiguous run of 20+ base64/hex-alphabet
     *     characters — a simple heuristic to catch an accidentally-
     *     passed token/secret. Every current call site already passes
     *     short, deterministic, non-secret labels (e.g.
     *     'inbound-webhook-verify-'.$connection->id.'-'.now()->getTimestampMs()),
     *     so this closes what was otherwise the ONE open free-text
     *     exception in this domain's otherwise-closed-vocabulary audit
     *     surfaces (SanitizedProviderHttpException, InboundWebhookAuditLogger,
     *     SanitizedHealthDiagnostic).
     *   - Immediately before the decrypt itself, a
     *     `integration_credential.decrypted` timeline event is recorded
     *     via the new TimelineEventRecorder constructor dependency —
     *     firm-facing, matching this domain's existing
     *     dot-namespaced-event convention. Never includes plaintext or
     *     ciphertext — only the already-non-secret, now-validated
     *     $operationId/$reason plus identifying columns.
     *
     * Checkpoint 1 also closes the health-sandbox design's §B.2 point 4
     * gap: if $credential->credential_environment_mode is tagged AND the
     * connection's provider currently has a `provider_environments`
     * config entry, the tagged mode must match the provider's CURRENT
     * mode or this method throws. Both preconditions are checked with
     * "fail open" tolerance — an untagged credential, or a provider with
     * no environment configured at all (every TestProvider-backed
     * credential today, since `test` is never present in
     * `provider_environments`), never triggers this check.
     */
    public function decryptForOperation(
        FirmIntegration $connection,
        IntegrationCredential $credential,
        string $operationId,
        string $reason,
    ): string {
        if ((new TenantContextService)->currentFirmId() !== $connection->firm_id) {
            throw new RuntimeException(
                'Cannot decrypt credential: no active tenant context, or the active context does not match this connection\'s firm.'
            );
        }

        $this->assertCredentialBelongsToConnection($credential, $connection);

        if ($connection->status !== ConnectionStatus::Active) {
            throw new RuntimeException("Cannot decrypt credential: connection {$connection->id} is not Active.");
        }

        if ($credential->status !== IntegrationCredentialStatus::Active) {
            throw new RuntimeException("Cannot decrypt credential: credential {$credential->id} is not Active.");
        }

        if (trim($operationId) === '' || trim($reason) === '') {
            throw new InvalidArgumentException('decryptForOperation() requires a non-empty operationId and reason.');
        }

        $this->assertSafeAuditLabel($operationId, 'operationId');
        $this->assertSafeAuditLabel($reason, 'reason');

        $this->assertCredentialEnvironmentModeMatchesConnection($connection, $credential);

        $this->events->record($connection->firm, 'integration_credential.decrypted', $connection, null, [
            'firm_integration_id' => $connection->id,
            'integration_credential_id' => $credential->id,
            'credential_type' => $credential->credential_type->value,
            'operation_id' => $operationId,
            'reason' => $reason,
        ]);

        return $this->encryption->decrypt(
            $connection->firm,
            $credential->encrypted_payload_ciphertext,
            $credential->encryption_key_id,
        );
    }

    /**
     * DISCLOSED, TRACKED GAP (frozen-design-post-review.md,
     * "Disclosed, tracked gap — reEncrypt()/key-rotation ordering";
     * agent-f-security-review.md §4): this method exists and is usable,
     * but NOTHING calls it automatically yet. It MUST be run for every
     * affected credential BEFORE any EncryptionKeyService::rotate($firm)
     * call for that firm, or those credential rows become PERMANENTLY
     * UNDECRYPTABLE — EmailBodyEncryptionService::decrypt() throws
     * unconditionally when the referenced TenantEncryptionKey is no
     * longer Active, and EncryptionKeyService exposes no way to decrypt
     * a specific, no-longer-active key version. Wiring this up
     * automatically (e.g. as a step inside EncryptionKeyService::rotate()
     * itself, or a queued job that runs before it) is explicitly left to
     * a future checkpoint — this is a disclosed, tracked gap, not an
     * oversight, and must be stated in these same explicit terms in the
     * Checkpoint 4 completion report.
     *
     * A system-maintenance operation, not a business operation on behalf
     * of an in-flight caller — decrypts directly via
     * EmailBodyEncryptionService::decrypt() (no operationId/reason,
     * unlike decryptForOperation()), re-encrypts via
     * EmailBodyEncryptionService::encrypt() (which binds to whatever
     * TenantEncryptionKey is CURRENTLY active for the firm), then updates
     * the SAME row's ciphertext/encryption_key_id in place via
     * IntegrationCredential::applyReEncryption() — the one legitimate,
     * narrow escape hatch built into that model's immutability guard for
     * exactly this purpose (see that model's class docblock).
     */
    public function reEncrypt(FirmIntegration $connection, IntegrationCredential $credential): IntegrationCredential
    {
        return (new TenantContextService)->runWithFirmContext(
            $connection->firm_id,
            function () use ($connection, $credential) {
                $this->assertCredentialBelongsToConnection($credential, $connection);

                if ($credential->status !== IntegrationCredentialStatus::Active) {
                    throw new RuntimeException(
                        "Cannot re-encrypt credential {$credential->id}: only an Active credential can be re-encrypted."
                    );
                }

                $plaintext = $this->encryption->decrypt(
                    $connection->firm,
                    $credential->encrypted_payload_ciphertext,
                    $credential->encryption_key_id,
                );

                $result = $this->encryption->encrypt($connection->firm, $plaintext);

                if (! $result->succeeded) {
                    throw new RuntimeException("Cannot re-encrypt credential {$credential->id}: {$result->reason}");
                }

                $credential->applyReEncryption($result->ciphertext, $result->encryptionKeyId);

                return $credential->fresh();
            }
        );
    }

    /**
     * Checkpoint 7 addition
     * (reviews/checkpoint-07/frozen-design-post-security-review.md
     * §5.2). Returns the single Active credential of $type for
     * $connection, or null. Relies entirely on ambient tenant context
     * (like decryptForOperation()) — verifies ambient context matches
     * $connection->firm_id BEFORE querying, so this can never be called
     * correctly before real tenant context has already been
     * established (i.e. before
     * App\Integrations\Services\WebhookConnectionResolverService's
     * Step 2, `TenantContextService::runWithFirmContext()`, has already
     * run). The `credential_type = ...` clause below is an ORDINARY
     * post-RLS narrowing filter, executed only after RLS has already
     * scoped the underlying read to `$connection->firm_id` — it can
     * only narrow that read, never widen it the way a `USING` clause
     * inside `CREATE POLICY` would. It is NOT, and must never become,
     * an RLS policy predicate: no RLS policy anywhere in this codebase
     * may reference `credential_type` (frozen design §7's bright-line
     * guard — the permanently-retired `credential_type =
     * 'webhook_signing_secret'` carve-out policy). Returns only the
     * model — callers must still call decryptForOperation() to obtain
     * plaintext.
     */
    public function findActiveCredential(FirmIntegration $connection, CredentialType $type): ?IntegrationCredential
    {
        if ((new TenantContextService)->currentFirmId() !== $connection->firm_id) {
            throw new RuntimeException(
                'Cannot look up active credential: no active tenant context, or the active context does not match this connection\'s firm.'
            );
        }

        return IntegrationCredential::query()
            ->where('firm_integration_id', $connection->id)
            ->where('credential_type', $type->value)
            ->where('status', IntegrationCredentialStatus::Active->value)
            ->first();
    }

    /**
     * No decrypt call at all — returns only non-secret fields. Safe to
     * expose to any caller that already has read access to the
     * $credential model itself (e.g. via a future Filament/API layer),
     * since none of the returned fields can reconstruct the plaintext
     * secret.
     */
    public function getMaskedMetadata(IntegrationCredential $credential): array
    {
        return [
            'id' => $credential->id,
            'firm_integration_id' => $credential->firm_integration_id,
            'credential_type' => $credential->credential_type,
            'status' => $credential->status,
            'expires_at' => $credential->expires_at,
            'masked_display_metadata' => $credential->masked_display_metadata,
            'created_at' => $credential->created_at,
            'rotated_at' => $credential->rotated_at,
            'revoked_at' => $credential->revoked_at,
        ];
    }

    /**
     * Token-refresh concurrency lock. Per checkpoint-00 §10 (frozen) and
     * Agent F's required change #2: DB::transaction() + ->lockForUpdate(),
     * NOT Cache::lock() (which would only degrade to polling the same
     * database-backed cache store with no stronger guarantee than a real
     * row lock). Mirrors
     * TrustConcurrencyLockService::withLockedBalances()'s exact
     * structural pattern: DB::transaction() wrapping a lockForUpdate()
     * query, then a closure callback that receives the locked model and
     * owns the business logic — this method only owns the lock/
     * transaction boundary.
     *
     * Locks the PARENT `firm_integrations` row for $connection — not an
     * individual `integration_credentials` row — because a single
     * refresh operation commonly needs to read/write MORE than one
     * credential row for the same connection at once (e.g. an OAuth
     * access token and its paired refresh token), and locking the one
     * parent row every credential for this connection already
     * references (via the composite FK) serializes all refresh activity
     * for that connection while leaving every OTHER connection —
     * whether this firm's or any other firm's — completely unaffected.
     * This is per-connection granularity, never global/provider-wide.
     *
     * Mandatory double-checked locking: $callback receives the freshly
     * locked FirmIntegration row and MUST re-read whatever
     * expires_at/credential-identity state it is about to act on from
     * INSIDE this closure (i.e. after the lock is already held) and
     * treat the refresh as a safe no-op if another transaction already
     * refreshed it while this one was queued behind the lock — this
     * method does not, and cannot, perform that business-rule
     * re-validation itself, exactly as
     * TrustConcurrencyLockService::withLockedBalances() leaves balance
     * validation entirely to its own caller's callback.
     *
     * Relies entirely on ambient caller-supplied tenant context — no
     * runWithFirmContext() wrap of its own, matching
     * WebhookSecretService's identical convention for methods with no
     * production caller yet; a future caller must supply context
     * explicitly before invoking this method.
     */
    public function withRefreshLock(FirmIntegration $connection, Closure $callback): mixed
    {
        return DB::transaction(function () use ($connection, $callback) {
            $lockedConnection = FirmIntegration::query()
                ->where('id', $connection->id)
                ->lockForUpdate()
                ->firstOrFail();

            return $callback($lockedConnection);
        });
    }

    private function assertConnectionUsable(FirmIntegration $connection): void
    {
        if (in_array($connection->status, [ConnectionStatus::Disconnected, ConnectionStatus::Error], true)) {
            throw new RuntimeException(
                "Cannot store a credential for connection {$connection->id}: its status is {$connection->status->value}."
            );
        }
    }

    private function assertCredentialBelongsToConnection(IntegrationCredential $credential, FirmIntegration $connection): void
    {
        if ((int) $credential->firm_integration_id !== (int) $connection->id) {
            throw new RuntimeException(
                "Credential {$credential->id} does not belong to connection {$connection->id}."
            );
        }
    }

    /**
     * Checkpoint 1 (FirmsVault Live Integrations) addition
     * (checkpoint1-security-review.md Finding 3). Never throws for
     * null (the "no environment tag supplied" default).
     */
    private function assertValidEnvironmentMode(?string $environmentMode): void
    {
        if ($environmentMode === null) {
            return;
        }

        if (! in_array($environmentMode, ['sandbox', 'live'], true)) {
            throw new InvalidArgumentException(
                "Invalid credential environment mode \"{$environmentMode}\"; expected \"sandbox\" or \"live\"."
            );
        }
    }

    /**
     * Checkpoint 1 (FirmsVault Live Integrations) addition
     * (checkpoint1-security-review.md Finding 6). A conservative length
     * cap plus a simple high-entropy/token-shaped-value heuristic —
     * rejects (never silently truncates) any candidate that looks like
     * it might accidentally carry real secret material, closing the one
     * open free-text exception in this domain's otherwise closed-
     * vocabulary audit surfaces.
     */
    private function assertSafeAuditLabel(string $value, string $fieldName): void
    {
        if (mb_strlen($value) > 128) {
            throw new InvalidArgumentException(
                "decryptForOperation() {$fieldName} exceeds the maximum allowed length of 128 characters."
            );
        }

        if (preg_match('/[A-Za-z0-9+\/=_-]{20,}/', $value) === 1) {
            throw new InvalidArgumentException(
                "decryptForOperation() {$fieldName} looks like it may contain high-entropy/token-shaped content ".
                'and was rejected; pass a short, developer-controlled label instead.'
            );
        }
    }

    /**
     * Checkpoint 1 (FirmsVault Live Integrations) addition
     * (checkpoint1-design-health-sandbox.md §B.2 point 4). Deliberately
     * tolerant: an untagged credential (credential_environment_mode ===
     * null) or a provider with no `provider_environments` configuration
     * entry at all (every TestProvider-backed credential today) never
     * triggers this check — only an ACTUAL mismatch between a tagged
     * credential and its provider's currently configured mode throws.
     */
    private function assertCredentialEnvironmentModeMatchesConnection(FirmIntegration $connection, IntegrationCredential $credential): void
    {
        if ($credential->credential_environment_mode === null) {
            return;
        }

        $providerKey = $connection->providerKey();
        $environmentResolver = new ProviderEnvironmentResolver;

        if ($providerKey === null || ! $environmentResolver->hasConfiguredEnvironment($providerKey)) {
            return;
        }

        $expectedMode = $environmentResolver->modeFor($providerKey);

        if ($credential->credential_environment_mode !== $expectedMode) {
            throw new RuntimeException(
                "Cannot decrypt credential {$credential->id}: it was issued for the \"{$credential->credential_environment_mode}\" ".
                "environment, but connection {$connection->id}'s provider is currently configured for \"{$expectedMode}\"."
            );
        }
    }
}
