<?php

namespace App\Services;

use App\Enums\EmailOAuthTokenStatus;
use App\Enums\EmailOAuthTokenType;
use App\Enums\EmailSyncEventType;
use App\Enums\EmailSyncOutcome;
use App\Models\EmailAccount;
use App\Models\EmailOAuthToken;

/**
 * EmailOAuthTokenService — store/rotate/revoke OAuth token material.
 * Never returns raw token material from any method to any external
 * caller — unlike Phase 8's ApiKeyService (which deliberately returns
 * a raw secret once, since that secret is FirmsBase's own), an OAuth
 * token is provider-issued material that should never surface to a
 * human at all, not even once. decryptForInternalUse() is the only
 * path back to plaintext, and it exists solely for
 * FakeEmailProviderClient/EmailSyncService's in-memory use during a
 * sync call — never logged, never returned from a public API.
 *
 * Fails closed: store()/rotate() throw if the firm has no active
 * TenantEncryptionKey (via EmailBodyEncryptionService), rather than
 * persisting a plaintext token column (which does not exist on this
 * table at all).
 *
 * Tenant-context wiring (email_sync_events FORCE ROW LEVEL SECURITY
 * activation, Section 39A-5 Wave 5): email_oauth_tokens itself has no
 * firm_id column and is not in scope. store() never calls
 * auditService->record(), so it needs no wrap. rotate()/revoke() both
 * begin with `$account = $token->emailAccount;` — a lazy load of
 * email_accounts, itself now FORCE'd — which is a caller-responsibility
 * bootstrap dependency (mirroring the already-landed
 * email_visibility_rules migration's own documented convention): the
 * caller must already have tenant context active before calling
 * rotate()/revoke(), not this service. Once $account is resolved, each
 * method adds its own defensive, independent wrap around just the
 * auditService->record(...) call, keyed on the now-resolved
 * $account->firm_id.
 */
class EmailOAuthTokenService
{
    public function __construct(
        private readonly EmailBodyEncryptionService $bodyEncryption,
        private readonly EmailSyncAuditService $auditService,
    ) {}

    public function store(EmailAccount $account, EmailOAuthTokenType $type, string $rawToken, ?\DateTimeInterface $expiresAt = null): EmailOAuthToken
    {
        $result = $this->bodyEncryption->encrypt($account->firm, $rawToken);

        if (! $result->succeeded) {
            throw new \RuntimeException("Cannot store OAuth token: {$result->reason}");
        }

        return EmailOAuthToken::create([
            'email_account_id' => $account->id,
            'token_type' => $type,
            'encrypted_token_ciphertext' => $result->ciphertext,
            'encryption_key_id' => $result->encryptionKeyId,
            'status' => EmailOAuthTokenStatus::Active,
            'expires_at' => $expiresAt,
        ]);
    }

    public function rotate(EmailOAuthToken $token, string $newRawToken, ?\DateTimeInterface $expiresAt = null): EmailOAuthToken
    {
        $account = $token->emailAccount;

        $token->update(['status' => EmailOAuthTokenStatus::Rotated]);

        $newToken = $this->store($account, $token->token_type, $newRawToken, $expiresAt);

        (new TenantContextService)->runWithFirmContext($account->firm_id, fn () => $this->auditService->record(
            $account->firm,
            $account,
            EmailSyncEventType::TokenRotated,
            EmailSyncOutcome::Success,
        ));

        return $newToken;
    }

    public function revoke(EmailOAuthToken $token): EmailOAuthToken
    {
        $account = $token->emailAccount;

        $token->update(['status' => EmailOAuthTokenStatus::Revoked]);

        (new TenantContextService)->runWithFirmContext($account->firm_id, fn () => $this->auditService->record(
            $account->firm,
            $account,
            EmailSyncEventType::TokenRevoked,
            EmailSyncOutcome::Success,
        ));

        return $token->fresh();
    }

    /**
     * The ONLY path back to plaintext token material. Callers must
     * keep the return value in memory only — never log it, never
     * persist it anywhere other than this table's ciphertext column.
     */
    public function decryptForInternalUse(EmailOAuthToken $token): string
    {
        return $this->bodyEncryption->decrypt(
            $token->emailAccount->firm,
            $token->encrypted_token_ciphertext,
            $token->encryption_key_id,
        );
    }
}
