<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * firm_integrations — the per-firm connection instance to a registered
 * provider (checkpoint-00-final-specification.md §5 table #2;
 * domain-model-and-rls-classification.md §2). Direct firm-owned,
 * modeled explicitly on `email_accounts` (see
 * database/migrations/2026_07_12_900001_create_email_accounts_table.php
 * and app/Models/EmailAccount.php) — same connected_by_firm_user_id
 * actor-column shape, same plain-string `status` column with
 * application-level enum casting (mirrors email_accounts.connection_status),
 * same uuid-for-public-exposure / bigint-id-for-FKs dual-ID design.
 *
 * No credential-shaped column exists on this table at all — credential
 * material is a Checkpoint 4 concern (`integration_credentials`,
 * split-table convention proven by email_accounts/email_oauth_tokens
 * and webhook_subscriptions/webhook_secrets). `webhook_routing_token`
 * here is an opaque, non-secret routing identifier — distinct from the
 * future `integration_credentials.webhook_signing_secret` — and must
 * never be treated as, or logged like, a credential.
 *
 * `status` is stored as a plain string (default 'pending') with no
 * DB-level enum type, cast at the application layer to
 * App\Integrations\Enums\ConnectionStatus on the FirmIntegration model —
 * mirrors email_accounts.connection_status's exact convention rather
 * than introducing a new DB-level enum mechanism.
 *
 * `integration_provider_id` is a bare FK with restrictOnDelete() — the
 * one deliberate, correct exception to the composite-FK-closure
 * principle applied everywhere else in this design, because
 * `integration_providers` is Global/platform-wide reference data with
 * no `firm_id` column at all (confirmed by its own migration,
 * 2026_09_01_010001_create_integration_providers_table.php) — a
 * composite FK is structurally impossible against a parent with no
 * firm_id, not a gap.
 *
 * `connected_by_firm_user_id` is DELIBERATELY a bare FK to
 * `firm_users.id` (NOT the composite FK the cross-cutting design
 * principle would otherwise require) — a disclosed, narrow, tracked
 * gap, not an oversight. `firm_users` carries only
 * UNIQUE(user_id, firm_id) (see
 * database/migrations/2026_07_04_200002_create_firm_users_table.php),
 * not UNIQUE(firm_id, id), so the literal composite FK
 * (firm_id, connected_by_firm_user_id) REFERENCES firm_users(firm_id, id)
 * is not achievable without a separate migration altering firm_users
 * itself. Per the approved checkpoint-03-security-review.md ADDENDUM:
 * the coordinator accepted a bare FK here because (a) this column is
 * nullable audit/informational metadata (who initiated the
 * connection), never an access-control input, and (b) actual tenant
 * isolation for firm_integrations rows is fully and independently
 * provided by this table's own FORCE RLS policy (firm_id-scoped),
 * regardless of this column's correctness — a bare FK here reproduces
 * only a narrow audit-attribution-integrity gap, not the cross-firm
 * pivot-mismatch harm class. The compensating, application-level
 * control (verifying the referenced firm_users row's firm_id matches
 * this row's own firm_id before save) is implemented as a `saving`
 * model-event listener on App\Integrations\Models\FirmIntegration —
 * see that model's docblock. `nullOnDelete()` is used (rather than
 * cascadeOnDelete()) because a firm_integrations row must not
 * disappear merely because the firm_user who happened to initiate the
 * connection later leaves the firm.
 *
 * UNIQUE(firm_id, id) is added for Checkpoint 4+'s composite FKs that
 * will reference this table as a parent (integration_credentials,
 * integration_sync_runs, integration_external_mappings,
 * integration_sync_cursors, integration_usage_records,
 * integration_outbox_events, etc.) — this table itself has no parent
 * of its own requiring a composite FK (integration_provider_id is the
 * sole, correctly-bare, exception above).
 *
 * Partial unique indexes (Postgres-only syntax, matches this
 * codebase's existing Postgres-first posture — see
 * 2026_07_21_900003_create_webhook_secrets_table.php for the identical
 * DB::statement pattern):
 *   - webhook_routing_token: unique only when set, since most
 *     connections never populate it.
 *   - (firm_id, integration_provider_id, external_account_id): allows
 *     multiple connections per firm/provider only when
 *     external_account_id differs (or is null, in which case the
 *     partial index does not apply at all and any number of null rows
 *     may coexist) — per the master directive's explicit
 *     "multiple connections per firm/provider" support requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_integrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('integration_provider_id')->constrained('integration_providers')->restrictOnDelete();

            $table->string('external_account_id')->nullable();
            $table->string('display_label')->nullable();
            $table->string('status')->default('pending');
            $table->json('scopes_granted_json')->nullable();

            $table->foreignId('connected_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->string('last_health_status')->nullable();
            $table->text('error_reason')->nullable();
            $table->string('webhook_routing_token')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'id']);
            $table->index(['firm_id', 'status']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX firm_integrations_webhook_routing_token_unique '.
            'ON firm_integrations (webhook_routing_token) WHERE webhook_routing_token IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX firm_integrations_firm_provider_external_account_unique '.
            'ON firm_integrations (firm_id, integration_provider_id, external_account_id) '.
            'WHERE external_account_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_integrations');
    }
};
