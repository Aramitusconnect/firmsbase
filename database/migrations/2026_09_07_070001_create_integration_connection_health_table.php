<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_connection_health — Checkpoint 8
 * (agent-8f-health-state-design.md §1/§3/§6;
 * agent-8h-architecture-security-review.md §1 item 6). DirectTenant,
 * standard FORCE ROW LEVEL SECURITY, identical shape to every table
 * since Checkpoint 3. One row per `firm_integrations` row (strict 1:1,
 * not append-only history) — a separate table from
 * `firm_integrations` because it carries a materially richer, far
 * higher-write-frequency signal set (consecutive_failures, backoff
 * state, a closed last_failure_category, a provider-declared
 * rate-limit reset, a bounded sanitized diagnostic) than that table's
 * existing flat last_health_check_at/last_health_status/error_reason
 * triple, mirroring the same "rotation-without-mutating-parent-row"
 * split this codebase already uses for firm_integrations/
 * integration_credentials.
 *
 * `firm_integrations.last_health_check_at`/`last_health_status`/
 * `error_reason` are kept, unmodified, as a denormalized last-known-
 * state cache — written transactionally by the SAME
 * HealthStateService call that writes this table, never independently
 * (agent-8f §1).
 *
 * `next_retry_at` deliberately mirrors `integration_outbox_events.
 * next_attempt_at`'s naming/semantic shape (same useCurrent() default,
 * same "index it for a WHERE ... <= now() scan" intent) WITHOUT being
 * a literal reuse of that mechanism — there is no SKIP LOCKED pool to
 * claim here (exactly one row per connection, not an unbounded
 * backlog); a future scheduler simply scans `WHERE next_retry_at <=
 * now()` per firm.
 *
 * `sanitized_diagnostic_summary` is bounded BOTH at the app layer
 * (App\Integrations\Data\SanitizedHealthDiagnostic::toSummaryText(),
 * built from a fixed template over closed-vocabulary fields only) AND
 * by a DB-level CHECK constraint below — belt-and-suspenders, matching
 * this codebase's established "DB-level guarantee, not merely an
 * aspiration of the service layer" pattern.
 *
 * `cascadeOnDelete()` (not SET NULL, unlike
 * integration_outbox_events.firm_integration_id) is correct here:
 * firm_integration_id is NOT NULL — this row has no meaning
 * independent of its one connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connection_health', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->unsignedBigInteger('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('summary_state')->default('healthy');

            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->string('last_failure_category')->nullable();
            $table->timestamp('rate_limited_reset_at')->nullable();

            $table->timestamp('next_retry_at')->useCurrent();

            $table->text('sanitized_diagnostic_summary')->nullable();

            $table->timestamp('last_checked_at')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'id']);
            $table->unique('firm_integration_id');
            $table->index(['firm_id', 'summary_state']);
            $table->index('next_retry_at');

            $table->foreign(['firm_id', 'firm_integration_id'], 'integration_connection_health_firm_integration_fk')
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE integration_connection_health ADD CONSTRAINT integration_connection_health_rate_limit_consistency CHECK (
                (rate_limited_reset_at IS NULL) OR (last_failure_category = 'rate_limited')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_connection_health ADD CONSTRAINT integration_connection_health_diagnostic_summary_length CHECK (
                char_length(sanitized_diagnostic_summary) <= 500
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connection_health');
    }
};
