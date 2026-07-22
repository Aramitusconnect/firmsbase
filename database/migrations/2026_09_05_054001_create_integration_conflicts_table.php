<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * integration_conflicts — Checkpoint 6, fifth table of the six-table
 * date block (reviews/checkpoint-06/frozen-design-post-review.md §4/§6/
 * §8; agent-6f-mapping-conflict-design.md §3-§5). Direct firm-owned.
 * Positioned AFTER both its FK parents (integration_sync_items,
 * integration_external_mappings) already exist in this same date
 * block.
 *
 * Does NOT collide with the pre-existing, unrelated
 * conflict_check_runs/conflict_check_results tables (legal
 * conflict-of-interest screening — an entirely different domain).
 *
 * `firm_integration_id` — NOT NULL, real composite FK, the durable
 * ownership anchor independent of the short-retention `sync_item_id`
 * parent (resolves agent-6b's flagged retention-mismatch tension:
 * integration_sync_items is ~30-90 day/60-day-retention prunable,
 * integration_conflicts is 365-day "potentially disputed record"
 * retention — a plain cascadeOnDelete() on sync_item_id would let
 * routine item pruning silently destroy the longer-retention conflict
 * record).
 * `sync_item_id` — nullable, composite FK, ON DELETE SET NULL (not
 * cascade/restrict) — see above.
 * `external_mapping_id` — nullable, composite FK, ON DELETE SET NULL.
 *
 * `resolved_by_firm_user_id` / `resolution_approved_by_firm_user_id` —
 * BOTH bare FK to firm_users.id, nullOnDelete(), BOTH independently
 * guarded by a `saving` listener on IntegrationConflict shaped
 * verbatim after FirmIntegration::assertConnectedByFirmUserBelongsToSameFirm()
 * (see that model). Required because firm_users carries only
 * UNIQUE(user_id, firm_id), not UNIQUE(firm_id, id) — the identical,
 * disclosed Checkpoint 3/5 composite-FK-impossibility, applied here to
 * two actor columns instead of one.
 *
 * `local_type`/`local_id` — present directly on this table (not only
 * on its sync_item_id/external_mapping_id parents) because they are
 * the natural key of the "one open conflict per mapped local record"
 * partial unique index below, which must survive even after
 * sync_item_id is nulled by a routine prune.
 *
 * Idempotency (frozen-design-post-review.md §6): UNIQUE(firm_integration_id,
 * resource_type, local_type, local_id) WHERE status IN ('detected',
 * 'awaiting_review') — a raw INSERT ... ON CONFLICT (...) WHERE status
 * IN (...) DO NOTHING RETURNING * is REQUIRED for the write path
 * (IntegrationConflictService::recordDetection()); Postgres requires
 * the ON CONFLICT clause to repeat the partial index's WHERE predicate
 * exactly, which Laravel's fluent insertOrIgnoreReturning()/upsert()
 * uniqueBy cannot express — this must not be "simplified" to the
 * fluent form, it will fail at the DB layer the first time it runs.
 *
 * Conflict status (7-case, frozen-design-post-review.md §8): detected,
 * awaiting_review, resolved_local_wins, resolved_remote_wins,
 * resolved_merged, ignored, expired. Checkpoint 6 code paths only ever
 * write `detected` — the other six exist so a future resolution
 * workflow (Checkpoint 10/11) needs no schema-changing migration.
 *
 * Structural auto-resolution block — 5 independent CHECK constraints
 * (agent-6f §5.3, ratified verbatim by frozen-design-post-review.md
 * §8), none of which depends on any other for its own correctness:
 *   1. resolution_requires_actor — every resolved-shaped status needs
 *      a real resolved_by_firm_user_id + resolved_at.
 *   2. privileged_resource_dual_approval — THE structural, DB-CHECK-
 *      enforced answer to "can resource_type force manual review
 *      regardless of any future auto-resolution feature": reads
 *      resource_type DIRECTLY (not merely the requires_manual_review
 *      flag), IS DISTINCT FROM enforces two genuinely different,
 *      non-null actor IDs before invoice/payment/document/message
 *      conflicts may reach any resolved-shaped status. Cannot be
 *      bypassed by application code — only a superuser DDL change,
 *      an auditable operation.
 *   3. flagged_dual_approval — same rule via the denormalized
 *      requires_manual_review flag, covering the non-financial-
 *      resource_type-but-financial-tier-connection case constraint 2
 *      cannot see on its own.
 *   4. flag_matches_resource_type — consistency guard: the four forced
 *      resource types can never carry requires_manual_review=false.
 *   5. no_silent_expiry_when_flagged — a requires_manual_review row can
 *      never reach `expired` (silent aging-out is itself a form of
 *      un-audited auto-resolution).
 *
 * NOT enforced by this migration (disclosed, not silently worked
 * around, per agent-6f §5.4 and frozen-design-post-review.md §8):
 * state-TRANSITION validity (e.g. blocking resolved_local_wins ->
 * expired) is not a DB trigger — this codebase has zero CREATE TRIGGER
 * precedent anywhere in database/migrations. It is the sole-writer
 * IntegrationConflictService's responsibility, mirroring
 * ProviderConnectionService::transitionStatus()'s precedent.
 *
 * POST-DIFF-REVIEW FIX (checkpoint-06 verification pass) —
 * integration_conflicts_sync_item_fk / integration_conflicts_external_mapping_fk
 * composite-FK ON DELETE SET NULL bug: PostgreSQL's composite-key
 * `ON DELETE SET NULL` (with no column list) nulls the ENTIRE
 * referencing tuple, i.e. BOTH `sync_item_id`/`external_mapping_id`
 * AND `firm_id` — the moment the parent row is deleted this violates
 * firm_id's own NOT NULL constraint and the delete fails outright,
 * exactly backwards from the intended "orphan the child, keep the
 * child alive" behavior documented above. Fixed here by declaring
 * both composite FKs as raw ALTER TABLE ... ON DELETE SET NULL
 * (<column>) statements (PostgreSQL 15+; confirmed available — this
 * stack runs Postgres 16.14 per diff-review.md/agent-6h) instead of
 * Laravel's fluent ->nullOnDelete(), which cannot express a column
 * list and always nulls every column of a composite FK. The column
 * list restricts the SET NULL action to ONLY sync_item_id /
 * external_mapping_id — firm_id is never touched, so the NOT NULL
 * constraint can never be violated by this action.
 *
 * This intentionally does NOT follow the bare-FK + compensating
 * `saving`-listener pattern used elsewhere in this same migration for
 * resolved_by_firm_user_id/resolution_approved_by_firm_user_id, for
 * two independent reasons: (1) that pattern exists in this codebase
 * specifically for the case where a composite FK is structurally
 * IMPOSSIBLE (firm_users carries no UNIQUE(firm_id, id)) — that
 * obstacle does not apply here, both integration_sync_items and
 * integration_external_mappings already carry their own
 * UNIQUE(firm_id, id); downgrading to a bare FK here would be a
 * strictly weaker guarantee (losing DB-enforced same-firm validation
 * at INSERT/UPDATE time, falling back to an Eloquent `saving` listener
 * that a raw DB::table()->insert() bypasses entirely) for no reason
 * when a full composite FK is available; and (2) adding a `saving`
 * listener requires editing app/Integrations/Models/IntegrationConflict.php,
 * which is outside this fix task's file allowlist — the column-list
 * SET NULL approach fixes the bug entirely within this migration file,
 * with zero new mechanism (this migration already uses raw
 * DB::statement() for the partial unique index and all 5 CHECK
 * constraints below).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_conflicts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint
            $table->unsignedBigInteger('sync_item_id')->nullable(); // bare column; composite FK below
            $table->unsignedBigInteger('external_mapping_id')->nullable(); // bare column; composite FK below

            $table->string('resource_type');
            $table->string('local_type');
            $table->unsignedBigInteger('local_id');
            $table->string('conflict_type');

            $table->jsonb('local_value')->nullable();
            $table->jsonb('external_value')->nullable();
            $table->string('local_version_token')->nullable();
            $table->string('external_version_token')->nullable();

            $table->string('status')->default('detected');
            $table->boolean('requires_manual_review')->default(false);

            $table->foreignId('resolved_by_firm_user_id')->nullable()
                ->constrained('firm_users', 'id', 'integration_conflicts_resolved_by_fk')->nullOnDelete();
            $table->foreignId('resolution_approved_by_firm_user_id')->nullable()
                ->constrained('firm_users', 'id', 'integration_conflicts_resolution_approved_by_fk')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamp('detected_at');
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'firm_integration_id']);
            $table->index(['firm_id', 'status']);
            $table->index('resolved_at');

            $table->foreign(['firm_id', 'firm_integration_id'], 'integration_conflicts_firm_integration_fk')
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();

            // sync_item_id / external_mapping_id composite FKs are declared
            // below via raw DB::statement() with an explicit ON DELETE SET
            // NULL (<column>) list — see this migration's class docblock
            // ("POST-DIFF-REVIEW FIX") for why the fluent ->nullOnDelete()
            // (which nulls the WHOLE composite tuple, including firm_id,
            // violating its NOT NULL constraint) is not used here.
        });

        DB::statement(
            'ALTER TABLE integration_conflicts '.
            'ADD CONSTRAINT integration_conflicts_sync_item_fk '.
            'FOREIGN KEY (firm_id, sync_item_id) REFERENCES integration_sync_items (firm_id, id) '.
            'ON DELETE SET NULL (sync_item_id)'
        );

        DB::statement(
            'ALTER TABLE integration_conflicts '.
            'ADD CONSTRAINT integration_conflicts_external_mapping_fk '.
            'FOREIGN KEY (firm_id, external_mapping_id) REFERENCES integration_external_mappings (firm_id, id) '.
            'ON DELETE SET NULL (external_mapping_id)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX integration_conflicts_one_open_per_local_record '.
            'ON integration_conflicts (firm_integration_id, resource_type, local_type, local_id) '.
            "WHERE status IN ('detected', 'awaiting_review')"
        );

        DB::statement(<<<'SQL'
            ALTER TABLE integration_conflicts ADD CONSTRAINT integration_conflicts_resolution_requires_actor CHECK (
                status NOT IN ('resolved_local_wins', 'resolved_remote_wins', 'resolved_merged', 'ignored')
                OR (resolved_by_firm_user_id IS NOT NULL AND resolved_at IS NOT NULL)
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_conflicts ADD CONSTRAINT integration_conflicts_privileged_resource_dual_approval CHECK (
                NOT (
                    resource_type IN ('invoice', 'payment', 'document', 'message')
                    AND status IN ('resolved_local_wins', 'resolved_remote_wins', 'resolved_merged', 'ignored')
                )
                OR (
                    resolution_approved_by_firm_user_id IS NOT NULL
                    AND resolution_approved_by_firm_user_id IS DISTINCT FROM resolved_by_firm_user_id
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_conflicts ADD CONSTRAINT integration_conflicts_flagged_dual_approval CHECK (
                NOT (
                    requires_manual_review
                    AND status IN ('resolved_local_wins', 'resolved_remote_wins', 'resolved_merged', 'ignored')
                )
                OR (
                    resolution_approved_by_firm_user_id IS NOT NULL
                    AND resolution_approved_by_firm_user_id IS DISTINCT FROM resolved_by_firm_user_id
                )
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_conflicts ADD CONSTRAINT integration_conflicts_flag_matches_resource_type CHECK (
                resource_type NOT IN ('invoice', 'payment', 'document', 'message') OR requires_manual_review
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE integration_conflicts ADD CONSTRAINT integration_conflicts_no_silent_expiry_when_flagged CHECK (
                NOT requires_manual_review OR status <> 'expired'
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_conflicts');
    }
};
