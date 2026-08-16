<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * provider_commands — FirmsVault Pay Gate A2 (v1.4 §9-§14).
 *
 * ============================================================
 * WHY THIS IS NOT A SECOND COMMAND ENGINE
 * ============================================================
 * Gate A1 identified `provider_operation_attempts` as the existing
 * ProviderCommand candidate, and Gate A2's compatibility review
 * (docs/payments/gate-a2-compatibility-decision.md) confirms its state
 * machine and at-most-once guarantee are excellent and MUST be reused.
 * But it cannot itself be the ProviderCommand, for one structural
 * reason that is deliberate in its own design:
 *
 *   `provider_operation_attempts` is written on the INDEPENDENT
 *   `pgsql_audit` connection, in autocommit, with no transactions and
 *   no foreign keys — SPECIFICALLY so its evidence SURVIVES a rollback
 *   of the caller's transaction. That is the whole point of Checkpoint
 *   8.2 §A4, and it is why Checkpoint 8.1's FK-bearing version was
 *   rejected after a proven production deadlock.
 *
 * v1.4 §14 requires the exact opposite for the ProviderCommand: it must
 * be created INSIDE the financial domain transaction and commit ATOMICALLY
 * with it, together with the outbox row, so that no economic instruction
 * can ever outlive a rolled-back financial transaction.
 *
 * "Survives rollback" and "commits atomically with the transaction" are
 * contradictory requirements on one row. They are therefore two rows,
 * at two layers, with two different jobs:
 *
 *   provider_commands              (THIS TABLE)
 *     the ECONOMIC INSTRUCTION. Tenant-owned, FORCE RLS, real composite
 *     foreign keys, immutable envelope, atomic with the domain
 *     transaction and the outbox row.
 *
 *   provider_operation_attempts    (EXISTING, REUSED UNCHANGED)
 *     the DURABLE AT-MOST-ONCE SEND GATE, consulted by the worker at
 *     the instant of the outbound HTTP call, on the independent
 *     connection, keyed by a logical_operation_key DERIVED
 *     DETERMINISTICALLY from this row's immutable envelope.
 *
 * The worker never invents a key: `logical_operation_key` is
 * `fvpay:<command_uuid>`, so the same command always maps to the same
 * gate row and a second worker can never obtain a second send
 * permission. Nothing here duplicates that engine; this table is what
 * the engine was always missing — a tenant-owned, transactional,
 * immutable instruction to point at.
 * ============================================================
 *
 * IMMUTABLE ENVELOPE vs MUTABLE EXECUTION METADATA (§12). The two are
 * physically grouped below and the split is enforced by the model's
 * append-only guard on the envelope columns. The economic instruction
 * never mutates after creation.
 *
 * IDEMPOTENCY (§13).
 *   same key + same canonical payload  -> the SAME logical command
 *   same key + different payload       -> IDEMPOTENCY_CONFLICT,
 *                                         no provider execution, audited
 * Enforced in the DATABASE by `UNIQUE (firm_id, idempotency_key)`: a
 * second insert with the same key simply cannot land, so the conflict
 * is detected by comparing the stored `canonical_payload_hash` against
 * the incoming one rather than by trusting the caller. This does not
 * rely on controller validation, queue uniqueness, or provider-side
 * idempotency.
 *
 * TENANT OWNERSHIP. Tenant-owned. RLS + FORCE RLS in the companion
 * migration. Composite FKs to `payment_intents (id, firm_id)` and
 * `firm_integrations (firm_id, id)` mean a command can never point at
 * another firm's intent or provider account (v1.4 §35, FV-A2-062/063).
 *
 * ROLLBACK. Dropping this table loses command history; safe only
 * pre-execution, which is the POC #1 state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_commands', function (Blueprint $table) {
            $table->id();

            // ---------- IMMUTABLE ENVELOPE (§12) ----------
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            // The firm's provider account. v1.4 §4 maps the architecture
            // role FirmProviderAccount onto the existing FirmIntegration.
            $table->unsignedBigInteger('firm_integration_id')->nullable();

            // The platform-level provider connection. v1.4 §4 maps the
            // architecture role ProviderPlatformConnection onto the
            // existing IntegrationProvider.
            $table->foreignId('integration_provider_id')->nullable()
                ->constrained('integration_providers')->restrictOnDelete();

            $table->string('command_type');

            // What this command acts upon, in the tenant domain.
            $table->string('aggregate_type');
            $table->unsignedBigInteger('aggregate_id');

            // Durable idempotency identity, supplied by the domain.
            $table->string('idempotency_key');

            // sha256 over the canonicalized economic payload.
            $table->string('canonical_payload_hash', 64);

            // The canonical payload itself, retained so a conflict can
            // be explained and an executor can rebuild the request
            // without re-deriving it from mutable domain state.
            $table->jsonb('canonical_payload')->default('{}');

            $table->uuid('correlation_id');

            $table->unsignedBigInteger('payment_intent_id')->nullable();

            // ---------- MUTABLE EXECUTION METADATA (§12) ----------
            $table->string('status')->default('pending');
            $table->timestamp('enqueued_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('provider_reference')->nullable();
            $table->string('last_error')->nullable();
            $table->boolean('reconciliation_required')->default(false);

            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index(['firm_id', 'aggregate_type', 'aggregate_id']);
            $table->index('correlation_id');

            // §13 — the database mechanism behind FV-A2-004/005.
            $table->unique(['firm_id', 'idempotency_key'], 'provider_commands_firm_idempotency_key_unique');

            // Composite-FK target for payment_attempts / payment_refunds.
            $table->unique(['id', 'firm_id'], 'provider_commands_id_firm_id_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE provider_commands
            ADD CONSTRAINT provider_commands_firm_integration_same_firm_fk
            FOREIGN KEY (firm_id, firm_integration_id)
            REFERENCES firm_integrations (firm_id, id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE provider_commands
            ADD CONSTRAINT provider_commands_payment_intent_same_firm_fk
            FOREIGN KEY (payment_intent_id, firm_id)
            REFERENCES payment_intents (id, firm_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE provider_commands
            ADD CONSTRAINT provider_commands_status_values CHECK (
                status IN ('pending', 'dispatched', 'succeeded', 'failed', 'outcome_unknown')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE provider_commands
            ADD CONSTRAINT provider_commands_command_type_values CHECK (
                command_type IN ('capture_payment', 'refund_payment')
            )
        SQL);

        // A resolved command must record when it resolved, and an
        // unresolved one must not pretend it did.
        DB::statement(<<<'SQL'
            ALTER TABLE provider_commands
            ADD CONSTRAINT provider_commands_resolution_consistency CHECK (
                (status IN ('succeeded', 'failed', 'outcome_unknown') AND resolved_at IS NOT NULL)
                OR (status IN ('pending', 'dispatched') AND resolved_at IS NULL)
            )
        SQL);

        // An OUTCOME_UNKNOWN command ALWAYS requires reconciliation:
        // that is what the state means (v1.4 §23).
        DB::statement(<<<'SQL'
            ALTER TABLE provider_commands
            ADD CONSTRAINT provider_commands_unknown_requires_reconciliation CHECK (
                status <> 'outcome_unknown' OR reconciliation_required = true
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_commands');
    }
};
