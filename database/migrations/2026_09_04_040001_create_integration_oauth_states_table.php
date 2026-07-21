<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_oauth_states — Checkpoint 5 of the Stage B integration-
 * platform mission (checkpoint-00-final-specification.md §5 table #4,
 * "APPROVED, redesigned (never nullable)"; frozen-design-post-review.md;
 * agent-h-security-architecture-review.md). Holds the server-side,
 * single-use OAuth state row a firm's connect/reauthorize flow claims
 * at callback time — the bridge between "we sent this browser to a
 * provider's hosted authorize screen" and "this specific callback
 * request is the legitimate continuation of that specific attempt."
 *
 * (a) No raw bearer token stored, ever. The `state=` value handed to
 * the provider (and back to us on the callback) is a CSPRNG-generated,
 * >=256-bit opaque string that is NEVER written to this table in any
 * form — only `opaque_token_hash` (sha256 hex digest of it) is
 * persisted, and lookup at callback time is an ordinary hash-equality
 * index lookup. Agent D's raw-UUID/HasPublicUuid design was explicitly
 * REJECTED by Agent H's review (item 7) as contrary to the frozen
 * domain-model spec — this table therefore has NO `uuid` column and
 * IntegrationOAuthState does NOT use HasPublicUuid, matching the
 * `integration_credentials` precedent (internal, single-use,
 * security-sensitive routing state is never externally addressed).
 *
 * (b) `firm_integration_id` — bare column; the composite FK below
 * mirrors integration_credentials' own first-genuine-composite-FK
 * precedent exactly (firm_integrations carries UNIQUE(firm_id, id),
 * added in Checkpoint 3 specifically so later tables could do this): a
 * row inserted with firm_id=A and firm_integration_id=<a row actually
 * owned by firm B> produces the tuple (A, <id>), which has no match in
 * firm_integrations (which only has (B, <id>)), and is rejected at the
 * constraint layer — independent of, and in addition to, RLS.
 *
 * (c) `initiating_user_id` — non-null, bare FK to `users.id`,
 * restrictOnDelete(). This is the RLS self-lookup identity column the
 * companion RLS migration's `integration_oauth_states_self_lookup`
 * policy reads (`initiating_user_id = current_setting('app.current_user_id')`),
 * proven byte-for-byte identical in shape to `firm_users_self_lookup`
 * (see 2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php).
 *
 * (d) `initiating_firm_user_id` — DELIBERATELY a bare FK to
 * `firm_users.id` (NOT a composite FK), restrictOnDelete() (NOT
 * cascadeOnDelete() — this column is NOT NULL and load-bearing for an
 * in-flight OAuth state, unlike firm_integrations.connected_by_firm_user_id,
 * which is nullable). A disclosed, narrow, tracked gap, not an
 * oversight — identical structural reason as Checkpoint 3's
 * connected_by_firm_user_id: `firm_users` carries only
 * UNIQUE(user_id, firm_id) (see
 * database/migrations/2026_07_04_200002_create_firm_users_table.php),
 * not UNIQUE(firm_id, id), so the literal composite FK
 * (firm_id, initiating_firm_user_id) REFERENCES firm_users(firm_id, id)
 * is not achievable without a separate migration altering firm_users
 * itself — independently confirmed by Agent H's review (item 4), out
 * of scope for this checkpoint. The compensating, application-level
 * control (verifying the referenced firm_users row's firm_id AND
 * user_id match this row's own firm_id/initiating_user_id before save)
 * is implemented as a `saving` model-event listener on
 * App\Integrations\Models\IntegrationOAuthState — see that model's
 * docblock, shaped verbatim after the proven live
 * FirmIntegration::assertConnectedByFirmUserBelongsToSameFirm().
 *
 * (e) `opaque_token_hash` — sha256 hex digest (64 hex chars), unique.
 * Only ever compared via an ordinary DB equality/index lookup (never a
 * manual string comparison in application code), so no hash_equals()
 * requirement attaches to this column specifically — unlike the PKCE
 * verifier and redirect_uri comparisons, which DO compare
 * caller-supplied values against stored values in application code and
 * correctly use hash_equals() (see IntegrationOAuthStateService/
 * ProviderConnectionService).
 *
 * (f) `redirect_uri` — the OAuth callback URL registered with the
 * provider for this attempt, re-validated byte-for-byte at claim time.
 * Deliberately NO `redirect_intent` column or enum exists anywhere on
 * this table (Agent D's threat-model assumption of one was traced to
 * source and found NOT part of the frozen domain-model schema — see
 * Agent H's review item 9). The post-callback browser destination is
 * computed deterministically from `firm_integration_id` alone at
 * completion time, never from a stored or request-suppliable value.
 *
 * (g) `verifier_ciphertext`/`encryption_key_id` — PKCE (S256-only)
 * verifier material, envelope-encrypted via the SAME
 * EmailBodyEncryptionService chain Checkpoint 4's integration_credentials
 * uses (real, firm-scoped tenant context is already established by the
 * time this needs to be decrypted — no structural pre-tenant decryption
 * problem the way there is for webhook_signing_secret). Both columns
 * are forced to NULL, in the SAME transaction as the atomic one-time
 * claim (see IntegrationOAuthStateService::resolveAndConsume()), the
 * moment the verifier is consumed — the row survives for the retention
 * window but permanently loses the ability to yield plaintext verifier
 * material, independent of consumed_at. `encryption_key_id` is
 * therefore nullable (integration_credentials' own encryption_key_id is
 * NOT nullable — a real, structural difference, not an oversight).
 *
 * (h) `expires_at`/`consumed_at` — expiry is enforced synchronously, at
 * claim time, via the atomic UPDATE's own `WHERE expires_at > now()`
 * clause (IntegrationOAuthStateService::resolveAndConsume()) — never
 * dependent on a future sweep job. 10-minute default TTL / 30-minute
 * hard ceiling, both enforced entirely in
 * IntegrationOAuthStateService::initiate() (never caller-suppliable
 * beyond the ceiling). Retention: 24-72h post-consumption, hard delete
 * — per Agent H's review item 6, NO cleanup mechanism exists yet in
 * this checkpoint; this migration ships only the columns and the index
 * on expires_at below. "State exists, nothing polls it yet" — a
 * disclosed, shared, cross-cutting scheduler dependency, not an
 * oversight, and must not be worked around with a bespoke interim
 * poller.
 *
 * Atomic one-time consumption (Agent H review item 1, frozen §12): a
 * single `UPDATE ... WHERE id = ? AND consumed_at IS NULL AND
 * expires_at > now() RETURNING *` statement, never a SELECT followed by
 * a separate UPDATE, never a bare ->update() call. See
 * IntegrationOAuthStateService::resolveAndConsume() for the exact raw
 * DB::selectOne() implementation.
 *
 * The table name is a single hardcoded string literal (never user
 * input), matching every prior create-table migration's own posture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_oauth_states', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->foreignId('initiating_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('initiating_firm_user_id')->constrained('firm_users')->restrictOnDelete();

            $table->string('opaque_token_hash', 64)->unique();
            $table->string('redirect_uri');

            $table->text('verifier_ciphertext')->nullable();
            $table->foreignId('encryption_key_id')->nullable()->constrained('tenant_encryption_keys')->restrictOnDelete();

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'firm_integration_id']);
            $table->index('initiating_user_id');
            $table->index('expires_at');

            $table->foreign(['firm_id', 'firm_integration_id'])
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_oauth_states');
    }
};
