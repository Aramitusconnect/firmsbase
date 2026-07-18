<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ai_usage_events — a single, independent FORCE ROW LEVEL SECURITY
 * activation checkpoint drawn from RowLevelSecurityCoverageMappingService::
 * missingPreparedTables() (Section 39A-4A.1 inventory sweep). Like
 * firm_ai_settings (39A-5, Wave 1) and matter_expenses (39A-5, Wave 2)
 * before it, this table has NO pre-existing policy to flip FORCE on for
 * — no ENABLE ROW LEVEL SECURITY and no CREATE POLICY exist for it
 * anywhere yet. This migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state. The shared registry
 * (RowLevelSecurityCoverageMappingService, still listing
 * ai_usage_events under MISSING_PREPARED_TABLES at the point this
 * migration lands on its own) is updated once by the coordinator in a
 * later, separate wave-integration commit — not by this migration.
 *
 * (a) Policy anchor: ai_usage_events carries its OWN direct, NOT NULL
 * firm_id column (see database/migrations/2026_07_23_900003_
 * create_ai_usage_events_table.php) — the policy predicate below reads
 * that column directly, exactly like every other DirectTenant table's
 * policy in this registry.
 *
 * (b) No application code accompanies this migration.
 * AiUsageRecorderService::record() — the SOLE writer of ai_usage_events
 * (project rule 8: append-only) — already wraps its ENTIRE method body,
 * including the AiUsageEvent::create() call, in a single outer
 * runWithFirmContext() call (see that method's own docblock, added
 * alongside the firm_ai_settings checkpoint in
 * 2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php).
 * That existing wrap already fully covers this table's only INSERT
 * path, confirmed by two independent reviews. This migration
 * deliberately introduces zero changes to AiUsageRecorderService.php,
 * AiToolActionRecorderService.php, AiApprovalWorkflowService.php,
 * AiUsageEvent.php, or AiUsageEventFactory.php — none are needed.
 *
 * (c) Known, deliberately-deferred gap: no composite foreign key or
 * trigger ties ai_usage_events.firm_id to the ACTUAL firm_id of the row
 * matter_id points at (matter_id is nullable — some AI actions, e.g. a
 * firm-wide summary, are not matter-scoped). PostgreSQL RLS on
 * ai_usage_events alone cannot see into the matters table to cross-
 * check this — that would require a structurally different EXISTS-
 * against-parent policy (a separate, unaddressed architectural
 * question), not the standard `firm_id = current_setting(...)` template
 * used here. This is the same accepted-gap class already stated for
 * matter_expenses.firm_id vs matter_expenses.matter_id/expense_id (see
 * that migration's own docblock, part (b)). Today, the only thing
 * preventing an ai_usage_events row from pointing at a matter belonging
 * to a different firm than its own firm_id is application-level
 * behavior in the AI request pipeline (matter resolution happens
 * against the firm's own matters before AiUsageRecorderService::record()
 * is ever called). This migration does not close that gap — it is
 * stated here, not hidden.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the explicit-over-
 * implicit convention established by firm_ai_settings and
 * matter_expenses, rather than relying on Postgres's "USING doubles as
 * WITH CHECK when none is given" behavior used by the earlier 39A-3
 * arc.
 *
 * Known, accepted, non-gap behavior: PostgreSQL's documented row-
 * security semantics exempt foreign-key ON DELETE CASCADE/SET NULL
 * actions from row-security policy evaluation entirely. firm_id and
 * user_id are ->cascadeOnDelete() and matter_id is ->nullOnDelete(), so
 * deleting a firms/users/matters row will always cascade as configured
 * regardless of which tenant's context is currently active — expected,
 * identical behavior to every other cascade-on-firms table already
 * forced in this repository, not a gap introduced or left open by this
 * migration.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'ai_usage_events';

    private const POLICY = 'ai_usage_events_tenant_isolation';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

        DB::statement(<<<SQL
            CREATE POLICY {$policy}
            ON {$table}
            USING (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
            WITH CHECK (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
        SQL);

        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Full rollback: this migration introduced the policy itself (there
     * was no pre-existing policy to merely un-FORCE), so down() must
     * remove all three effects up() added: FORCE, the policy, and row-
     * level security being enabled at all — restoring the table to its
     * true pre-this-migration (MISSING_PREPARED_TABLES) state.
     */
    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY {$policy} ON {$table}");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
