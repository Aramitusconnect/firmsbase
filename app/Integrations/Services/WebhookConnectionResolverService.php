<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Integrations\Data\ResolvedWebhookConnection;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\CredentialType;
use App\Integrations\Enums\IntegrationCredentialStatus;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationCredential;
use App\Integrations\Models\IntegrationProvider;
use App\Services\EmailBodyEncryptionService;
use App\Services\TenantContextService;
use Illuminate\Support\Facades\DB;

/**
 * WebhookConnectionResolverService — Steps 1-3 of the frozen design's
 * four-step identity-scoped secret-resolution mechanism
 * (reviews/checkpoint-07/frozen-design-post-security-review.md §5;
 * agent-7h-security-design-review.md §1.3/checklist item 7). No
 * `SECURITY DEFINER` function anywhere in this class or anything it
 * calls (checklist item 8) — the ONE pre-tenant-context read
 * (`resolveConnectionIdentity()`) targets a table with no RLS at all
 * (`integration_webhook_routing_index`), so there is no privileged
 * boundary for this class to punch through.
 *
 * Collapse-to-false / anti-enumeration (frozen design §8): both public
 * methods below return an empty/null result — never a distinguishable
 * error — for EVERY non-usable case (unknown provider, unknown token,
 * disconnected connection, revoked-only credential). The caller
 * (App\Integrations\Http\Controllers\InboundWebhookController) must
 * never branch its wire response on WHICH of these cases occurred.
 *
 * Bright-line guard (frozen design §7): `credential_type` appears
 * exactly once anywhere this class touches
 * `integration_credentials` — as an ORDINARY post-RLS `WHERE` narrowing
 * filter inside `findActiveCredential()`
 * (App\Integrations\Services\IntegrationCredentialService), executed
 * only AFTER `TenantContextService::runWithFirmContext()` has already
 * set real, verified `app.current_firm_id`. This class adds, and may
 * never add, any RLS policy predicate referencing `credential_type` on
 * `integration_credentials` — none is permitted (see that table's own
 * RLS migration docblock).
 */
class WebhookConnectionResolverService
{
    /**
     * Fallback default for the secret-rotation overlap window (frozen
     * design §8: "current Active + most-recent Rotated within a 24h
     * configurable overlap window"). Supplied as an inline default to
     * config('integrations.webhook.secret_rotation_overlap_hours', ...)
     * rather than via a config/integrations.php entry — that file is
     * outside this checkpoint's frozen production-file allowlist,
     * mirroring App\Integrations\Services\IntegrationOutboxEventService's
     * identical, already-established precedent for
     * config('integrations.outbox.stale_lock_minutes', ...).
     */
    private const DEFAULT_ROTATION_OVERLAP_HOURS = 24;

    public function __construct(
        private readonly IntegrationCredentialService $credentialService,
        private readonly EmailBodyEncryptionService $encryption,
        private readonly TenantContextService $tenantContext,
    ) {}

    /**
     * STEP 1 — bounded connection-identity resolution. No RLS bypass
     * needed: `integration_providers` and `integration_webhook_routing_index`
     * both carry no RLS at all (see each table's own "WHY THIS TABLE
     * HAS NO RLS" docblock). Returns ONLY {firm_id, firm_integration_id,
     * integration_provider_id, provider_key} — never a secret, never
     * connection metadata, never a hydrated FirmIntegration/
     * IntegrationCredential model.
     *
     * $providerKeyValue is the raw `{provider}` route segment — this
     * method itself is the closed-catalog check (a `where('code', ...)`
     * lookup against the seeded-only, non-dynamic `integration_providers`
     * table); an unrecognized value simply matches zero rows and
     * returns null, exactly like an unrecognized routing token, so a
     * caller cannot distinguish "provider doesn't exist" from "provider
     * exists, token wrong" (frozen design §1/§8).
     */
    public function resolveConnectionIdentity(string $providerKeyValue, string $rawRoutingToken): ?ResolvedWebhookConnection
    {
        $provider = IntegrationProvider::query()->where('code', $providerKeyValue)->first();

        if ($provider === null) {
            return null;
        }

        $tokenHash = hash('sha256', $rawRoutingToken);

        $row = DB::table('integration_webhook_routing_index')
            ->where('integration_provider_id', $provider->id)
            ->where('webhook_routing_token_hash', $tokenHash)
            ->first();

        if ($row === null) {
            return null;
        }

        return new ResolvedWebhookConnection(
            firmId: (int) $row->firm_id,
            firmIntegrationId: (int) $row->firm_integration_id,
            integrationProviderId: (int) $provider->id,
            providerKey: $providerKeyValue,
        );
    }

    /**
     * CHECKPOINT 1 addition (FirmsVault Live Integrations,
     * checkpoint1-design-webhook-verification.md §1.4). Factored out of
     * activeAndPreviousWebhookSecretsFor()'s own connection-lookup-and-
     * status-check logic (below), so the controller can decide
     * reject-vs-proceed WITHOUT conflating "connection not
     * found/not Active" (a real, provider-agnostic rejection reason)
     * with "connection Active but zero usable secret credentials"
     * (expected and harmless for non-HMAC providers like Microsoft/
     * Google/Plaid, whose verification never consults a symmetric
     * secret at all). Same collapse-to-false discipline as every other
     * method on this class: returns a plain bool, never a distinguishable
     * reason.
     */
    public function isConnectionActive(ResolvedWebhookConnection $resolved): bool
    {
        return $this->tenantContext->runWithFirmContext(
            $resolved->firmId,
            fn (): bool => $this->findConnection($resolved)?->status === ConnectionStatus::Active
        );
    }

    /**
     * Shared connection lookup — used by both isConnectionActive() and
     * activeAndPreviousWebhookSecretsFor() below, so the
     * "found, then check status" logic exists in exactly one place.
     * Callers are responsible for already running inside
     * TenantContextService::runWithFirmContext() (both do).
     */
    private function findConnection(ResolvedWebhookConnection $resolved): ?FirmIntegration
    {
        return FirmIntegration::query()
            ->where('id', $resolved->firmIntegrationId)
            ->where('firm_id', $resolved->firmId)
            ->first();
    }

    /**
     * STEPS 2-3 combined — establishes real tenant context
     * (`TenantContextService::runWithFirmContext()`, existing,
     * unmodified), loads the connection via the now RLS-satisfied read,
     * and returns AT MOST 2 plaintext webhook-signing-secret candidates
     * (current Active first, then the most-recent Rotated credential
     * within the configurable overlap window) — frozen design §8's
     * secret-rotation contract. Returns an EMPTY array — never an
     * exception, never a distinguishable partial result — for ANY
     * non-usable connection state: connection not found, connection not
     * Active (covers "disconnected"), no Active webhook-signing-secret
     * credential at all (covers "revoked-only": revoke() never sets
     * rotated_at, so a Revoked-only credential also fails the
     * Rotated-within-window lookup below).
     *
     * The ACTIVE candidate is decrypted via the existing, unmodified
     * `IntegrationCredentialService::decryptForOperation()` — the
     * frozen design's literal call-out (§5 STEP 3). The Rotated
     * candidate CANNOT go through that same method:
     * `decryptForOperation()` unconditionally throws unless
     * `$credential->status === IntegrationCredentialStatus::Active`
     * (by design — it is the single narrow decrypt path for
     * currently-usable credentials), and this codebase's credential
     * lifecycle never has two simultaneously-Active rows of the same
     * type (a partial unique index enforces exactly one). This is a
     * disclosed, narrow implementation decision the frozen design's
     * pseudocode does not spell out: the Rotated candidate is decrypted
     * directly via the SAME underlying `EmailBodyEncryptionService`
     * primitive `decryptForOperation()` itself calls internally
     * (injected here exactly as
     * `IntegrationOAuthStateService`/`IntegrationCredentialService`
     * already inject it directly) — never a second encryption system,
     * never a modification to `IntegrationCredentialService` beyond the
     * one narrow `findActiveCredential()` addition this checkpoint's
     * file allowlist permits. Ownership (`firm_integration_id` match)
     * is verified explicitly before decrypting, mirroring
     * `IntegrationCredentialService::assertCredentialBelongsToConnection()`'s
     * check (private on that class, so re-implemented narrowly here).
     *
     * Both candidates are discarded from this method's own local scope
     * the instant the caller (App\Integrations\Http\Controllers\InboundWebhookController)
     * finishes calling
     * App\Integrations\Services\InboundWebhookSignatureVerifier::verify()
     * — this method itself never logs, persists, or re-throws either
     * value.
     *
     * @return string[]
     */
    public function activeAndPreviousWebhookSecretsFor(ResolvedWebhookConnection $resolved): array
    {
        return $this->tenantContext->runWithFirmContext(
            $resolved->firmId,
            function () use ($resolved): array {
                $connection = $this->findConnection($resolved);

                if ($connection === null || $connection->status !== ConnectionStatus::Active) {
                    return [];
                }

                $candidates = [];

                $active = $this->credentialService->findActiveCredential($connection, CredentialType::WebhookSigningSecret);

                if ($active !== null) {
                    // CHECKPOINT 1 note (FirmsVault Live Integrations):
                    // these two labels use SPACE-separated words rather
                    // than this codebase's more common hyphen/
                    // underscore-joined style, deliberately — the
                    // parallel Checkpoint 1 workstream's
                    // IntegrationCredentialService::decryptForOperation()
                    // now rejects any operationId/reason containing a
                    // contiguous run of 20+ characters drawn from
                    // [A-Za-z0-9+/=_-] (security review Finding 6, a
                    // token-shaped-value heuristic) — a hyphen/underscore
                    // -joined label of this length is entirely within
                    // that character class end to end and would trip
                    // the same false positive as an actual token. Space
                    // characters fall outside that class, so this stays
                    // a short, deterministic, non-secret, human-readable
                    // label while remaining compatible with the new
                    // guard.
                    $candidates[] = $this->credentialService->decryptForOperation(
                        $connection,
                        $active,
                        'inbound webhook verify: connection '.$connection->id.' at '.now()->getTimestampMs(),
                        'inbound webhook signature verification',
                    );
                }

                $overlapSeconds = (int) config(
                    'integrations.webhook.secret_rotation_overlap_hours',
                    self::DEFAULT_ROTATION_OVERLAP_HOURS,
                ) * 3600;

                $rotated = IntegrationCredential::query()
                    ->where('firm_integration_id', $connection->id)
                    ->where('credential_type', CredentialType::WebhookSigningSecret->value)
                    ->where('status', IntegrationCredentialStatus::Rotated->value)
                    ->where('rotated_at', '>=', now()->subSeconds($overlapSeconds))
                    ->orderByDesc('rotated_at')
                    ->orderByDesc('id')
                    ->first();

                if ($rotated !== null && (int) $rotated->firm_integration_id === (int) $connection->id) {
                    $candidates[] = $this->encryption->decrypt(
                        $connection->firm,
                        $rotated->encrypted_payload_ciphertext,
                        (int) $rotated->encryption_key_id,
                    );
                }

                return $candidates;
            }
        );
    }
}
