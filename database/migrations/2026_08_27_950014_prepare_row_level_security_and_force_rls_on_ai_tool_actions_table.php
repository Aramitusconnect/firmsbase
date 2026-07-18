<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-5 — ai_tool_actions FORCE ROW LEVEL SECURITY activation,
 * drawn from RowLevelSecurityCoverageMappingService::missingPreparedTables().
 * This table's migration/service/test batch lands independently from any
 * sibling table's batch; the shared registry (RowLevelSecurityCoverageMappingService,
 * still listing ai_tool_actions under MISSING_PREPARED_TABLES at the point
 * this migration lands on its own) is updated once by the coordinator
 * after all checkpoints in this wave have landed — not by this migration.
 *
 * Like ai_retrieval_indexes (2026_08_27_950001), this table has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * No production service code change accompanies this migration.
 * ai_tool_actions has exactly one writer, AiToolActionRecorderService::
 * recordFromResponse(), and its only call site is inside
 * AiUsageRecorderService::record()'s existing single outer
 * runWithFirmContext() wrap (see that method's own docblock, which
 * already documents that this wrap is comprehensive for the
 * ai_tool_actions writes performed inside it and that a later wave
 * activating FORCE ROW LEVEL SECURITY on this table must NOT re-wrap
 * the method again). Confirmed by two independent reviews that this
 * table requires zero production service code changes to work
 * correctly once forced.
 *
 * Known, deliberately-deferred, non-blocking gap: ai_tool_actions.
 * matter_id and ai_tool_actions.ai_usage_event_id are not cross-checked
 * against ai_tool_actions.firm_id at the database level — a row could
 * in principle reference a matter or ai_usage_event belonging to a
 * different firm than its own firm_id, and neither this policy nor any
 * composite FK/trigger would catch that transitive mismatch. This is
 * the same class of gap already accepted and documented for other
 * forced tables in this arc (e.g. matter_expenses); it is not
 * introduced or hidden by this migration, only left open, consistent
 * with the fix applied to AiToolActionFactory (see
 * database/factories/AiToolActionFactory.php) which ties matter_id/
 * ai_usage_event_id to a single authoritative firm by default so the
 * factory itself never manufactures the invalid shape.
 *
 * Known, accepted, non-gap behavior: PostgreSQL's documented row-
 * security semantics exempt foreign-key ON DELETE CASCADE actions from
 * row-security policy evaluation entirely (the cascade is enforced by
 * the FK constraint machinery, not by a role-scoped DML statement that
 * RLS would intercept). Consequently, deleting a `firms` row (firm_id
 * is ->cascadeOnDelete()) or an `ai_usage_events` row (ai_usage_event_id
 * is also ->cascadeOnDelete()) will always cascade-delete the
 * dependent ai_tool_actions row(s) regardless of which tenant's context
 * is currently active in the session — this is expected, identical
 * behavior to every other cascade-on-parent table already forced in
 * this repository, not a gap introduced or left open by this
 * migration.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'ai_tool_actions';

    private const POLICY = 'ai_tool_actions_tenant_isolation';

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
