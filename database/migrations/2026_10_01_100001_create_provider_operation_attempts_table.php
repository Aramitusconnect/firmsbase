<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * provider_operation_attempts — Checkpoint 8.2 §A4. The FK-FREE durable
 * at-most-once gate for outbound provider calls.
 *
 * *** THIS TABLE INTENTIONALLY HAS NO FOREIGN KEYS. THAT IS THE WHOLE
 * POINT. DO NOT ADD ANY. ***
 *
 * WHY. Checkpoint 8.1 tried to make billing evidence survive an ambient
 * transaction rollback by moving the EXISTING, FK-bearing
 * `provider_billable_call_reservations` table onto the independent
 * `pgsql_audit` connection. That deadlocks in production:
 * `App\Jobs\PullSyncJob` (see that file's own `lockForUpdate()` on
 * `firm_integrations`) holds FOR UPDATE on the connection row across the
 * provider call, and `RenewGraphSubscriptionJob` does the same on its
 * subscription row. A cross-session INSERT whose composite FK
 * references a locked row must acquire FOR KEY SHARE on it, which is
 * incompatible with FOR UPDATE — so the durable write waits for a
 * transaction that cannot commit until the job finishes. Proven live
 * via pg_stat_activity/pg_locks (audit backend queued on
 * Lock/transactionid, blocked_by the ambient job backend). Checkpoint
 * 8.1 was rejected for exactly this reason.
 *
 * Because this table writes on a session INDEPENDENT of whatever
 * transaction/locks a caller holds, every column that would ordinarily
 * be a foreign key is stored as a PLAIN SCALAR for correlation only
 * (`firm_id`, `firm_integration_id`). No `constrained()`, no composite
 * FK, no `references()`.
 *
 * COMPENSATING APPLICATION-LEVEL VALIDATION (the tradeoff this buys,
 * and how it is paid for). Losing referential integrity here is
 * acceptable ONLY because:
 *   1. The pipeline validates firm ownership BEFORE claiming — the
 *      existing `ProviderTenantSafePolicyService::assertConnectionBelongsToFirm()`
 *      runs against real, FK-backed rows on the ordinary connection.
 *   2. Entitlement, capability and actor authorization are likewise
 *      checked against FK-backed rows before any claim is written.
 *   3. These rows are OPERATIONAL EVIDENCE, never a source of truth for
 *      money owed. The authoritative billing rows
 *      (`provider_billable_call_reservations`, `integration_usage_records`)
 *      keep their real foreign keys on the ordinary connection
 *      (Checkpoint 8.2 §A9) and are rebuilt from this evidence during
 *      recovery, never invented from it.
 *   4. A dangling scalar (e.g. connection later hard-deleted) can only
 *      ever cause a claim to be refused or reconciled — it can never
 *      authorize a call, spend money, or leak cross-tenant data. Fail
 *      closed by construction.
 *   5. `firm_id` is still carried so the row is attributable and
 *      sweepable per tenant; RLS is deliberately NOT applied (see
 *      below).
 *
 * RLS. This table is classified Global/EXEMPT rather than tenant-owned
 * FORCE-RLS — the same treatment as its siblings
 * `integration_webhook_routing_index`, `integration_gmail_mailbox_routes`
 * and `integration_plaid_item_routes`, which likewise carry a real
 * firm_id that is a non-authoritative correlation pointer rather than a
 * security boundary. Applying `app.current_firm_id`-keyed RLS would require the
 * independent session to have tenant context pushed for every read,
 * including the pre-claim probe that must run BEFORE any firm context
 * is necessarily established, and would reintroduce cross-session
 * coupling of exactly the kind this table exists to avoid. Tenant
 * attribution is preserved via the scalar `firm_id`, and every query in
 * `ProviderOperationAttemptService` filters on it explicitly. This
 * classification is registered in
 * `App\Services\RowLevelSecurityCoverageMappingService`.
 *
 * UNIQUENESS. `logical_operation_key` is globally unique. It is a
 * deterministic hash of stable business inputs (never wall-clock time)
 * and is what makes "one logical operation" a real, enforceable
 * database constraint rather than a convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_operation_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Deterministic identity of ONE logical operation. Unique —
            // this constraint is the at-most-once guarantee's anchor.
            $table->string('logical_operation_key')->unique();

            $table->string('provider_key');

            // Scalars, NOT foreign keys. See this migration's docblock.
            $table->unsignedBigInteger('firm_id');
            $table->unsignedBigInteger('firm_integration_id')->nullable();

            $table->string('operation_type');
            $table->unsignedInteger('operation_version')->default(1);

            $table->string('attempt_state');

            // Short machine reason for the CURRENT state (e.g.
            // 'lease_expired_before_send', 'provider_timeout'). Never
            // free-form operator prose, never provider payload text.
            $table->string('state_reason')->nullable();

            // Single-winner send ownership.
            $table->string('owner_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();

            // Transition evidence. Both counters are incremented ONLY by
            // the markAttemptStarted() compare-and-set.
            //
            // `send_count` counts sends in the CURRENT attempt
            // generation and is reset to 0 only when a new generation
            // legitimately begins — i.e. from a state that positively
            // proves no billable provider work happened
            // (`provider_rejected`) or from an explicit, audited
            // operator resolution (`retry_allowed`). It therefore
            // expresses the at-most-once guarantee as a directly
            // assertable database fact: NO ROW MAY EVER EXCEED 1.
            //
            // `total_send_count` is monotonic and never reset, so the
            // full history is never lost by a generation restart, and
            // `reclaim_count` records how many times a lease was taken
            // over.
            $table->unsignedInteger('send_count')->default(0);
            $table->unsignedInteger('total_send_count')->default(0);
            $table->unsignedInteger('reclaim_count')->default(0);

            // Safe, redacted provider evidence only — never tokens,
            // never raw banking payloads (Checkpoint 8.2 §A8).
            $table->string('provider_request_reference')->nullable();
            $table->text('redacted_result_metadata')->nullable();
            $table->string('result_checksum')->nullable();
            $table->string('provider_outcome')->nullable();
            $table->string('billable_classification')->nullable();

            $table->timestamp('provider_started_at')->nullable();
            $table->timestamp('provider_completed_at')->nullable();

            $table->string('local_processing_state')->nullable();
            $table->timestamp('local_processing_completed_at')->nullable();

            $table->string('reconciliation_reason')->nullable();

            $table->timestamps();
            $table->timestamp('finalized_at')->nullable();

            $table->index(['firm_id', 'attempt_state']);
            $table->index(['firm_integration_id', 'operation_type']);
            $table->index('lease_expires_at');
            $table->index('attempt_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_operation_attempts');
    }
};
