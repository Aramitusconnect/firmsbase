<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_credentials — Checkpoint 4 of the Stage B integration-
 * platform mission (checkpoint-00-final-specification.md §5, §10, §11;
 * frozen-design-post-review.md). Holds the actual credential material
 * (OAuth tokens, API keys) for a `firm_integrations` connection —
 * `firm_integrations` itself carries no credential-shaped column at all
 * (split-table convention proven by email_accounts/email_oauth_tokens
 * and webhook_subscriptions/webhook_secrets; see that table's own
 * migration docblock).
 *
 * No `uuid` column: internal secret material, never externally
 * addressed — matches email_oauth_tokens/webhook_secrets exactly.
 *
 * (a) Composite FK — first genuine one in this codebase: unlike
 * Checkpoint 3's `firm_integrations.connected_by_firm_user_id` (which
 * was forced into a bare FK + compensating-control pattern because
 * `firm_users` lacks UNIQUE(firm_id, id)), `firm_integrations` itself
 * DOES carry UNIQUE(firm_id, id) (added in Checkpoint 3 specifically
 * for this purpose — see 2026_09_02_020001_create_firm_integrations_table.php).
 * That makes `$table->foreign(['firm_id', 'firm_integration_id'])
 * ->references(['firm_id', 'id'])->on('firm_integrations')` a real,
 * DB-enforced composite constraint, not merely an apologetic
 * compensating control: a row inserted with firm_id=A and
 * firm_integration_id=<a row actually owned by firm B> produces the
 * tuple (A, <id>) which has no match in firm_integrations (which only
 * has (B, <id>)) and is rejected at the constraint layer, independent
 * of and in addition to RLS. `firm_integration_id` itself is therefore
 * declared as a bare `foreignId()` column with NO `->constrained()` of
 * its own — the composite `$table->foreign([...])` call below is the
 * sole FK constraint governing this column.
 *
 * (b) `webhook_routing_token` (nullable string) + its own partial
 * unique index are frozen per checkpoint-00-final-specification.md §11,
 * R4 finding item 8 (see agent-f-security-review.md §6) — schema-only
 * scaffolding for Checkpoint 7. Both are INERT this checkpoint: no
 * Checkpoint 4 code path ever writes a row with
 * credential_type='webhook_signing_secret', so this column is never
 * populated and this index never matches any row. No carve-out RLS
 * policy exists or may be added in THIS migration (or anywhere in
 * Checkpoint 4) — the `integration_credentials_webhook_signing_lookup`
 * SELECT-carve-out policy is exclusively Checkpoint 7's scope, gated on
 * its own independent security review
 * (checkpoint-00-final-specification.md §11(a)-(e)). Adding it here, or
 * any variant of it, is explicitly out of scope and was the subject of
 * Agent F's security review boundary check.
 *
 * (c)/(d) `credential_type` is a plain string column (application-level
 * enum cast via App\Integrations\Enums\CredentialType on the
 * IntegrationCredential model — mirrors firm_integrations.status's own
 * convention). Its 'webhook_signing_secret' case exists ONLY as a
 * descriptive label for future Checkpoint 7 use: it is never consulted
 * by any RLS predicate in this checkpoint (the RLS policy in the
 * companion migration is firm_id-only, with no credential_type branch
 * whatsoever), and no code in this checkpoint treats that case
 * specially. It is used only as an input to the application-layer
 * partial unique index below (one active credential per
 * firm_integration_id + credential_type combination).
 *
 * Two raw DB::statement partial unique indexes (Postgres-only syntax,
 * matches this codebase's existing Postgres-first posture — mirrors
 * firm_integrations/webhook_secrets' identical DB::statement pattern):
 *   - one active credential per (firm_integration_id, credential_type);
 *   - webhook_routing_token unique only for credential_type =
 *     'webhook_signing_secret' rows (inert this checkpoint, see (b)
 *     above).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('credential_type'); // 'oauth_access_token' | 'oauth_refresh_token' | 'api_key' | 'webhook_signing_secret'
            // NEVER consulted by any RLS predicate; 'webhook_signing_secret' rows
            // are never written by any Checkpoint 4 code path (no creation flow exists yet)
            $table->text('encrypted_payload_ciphertext');
            $table->foreignId('encryption_key_id')->constrained('tenant_encryption_keys')->restrictOnDelete();
            $table->string('status')->default('active'); // active | rotated | revoked

            $table->json('granted_scopes_json')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->json('masked_display_metadata')->nullable(); // see IntegrationCredentialService's "masked metadata provenance" docblock
            $table->string('webhook_routing_token')->nullable(); // frozen per checkpoint-00 §11 R4 item 8 — INERT this checkpoint, see class docblock (b)

            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->text('refresh_failure_reason')->nullable(); // never secret material

            $table->timestamps();

            $table->index(['firm_id', 'firm_integration_id']);
            $table->index(['firm_id', 'status']);

            $table->foreign(['firm_id', 'firm_integration_id'])
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX integration_credentials_one_active_per_connection_and_type '.
            'ON integration_credentials (firm_integration_id, credential_type) '.
            "WHERE status = 'active'"
        );

        DB::statement(
            'CREATE UNIQUE INDEX integration_credentials_webhook_routing_token_unique '.
            'ON integration_credentials (webhook_routing_token) '.
            "WHERE credential_type = 'webhook_signing_secret'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
