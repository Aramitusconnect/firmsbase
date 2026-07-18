<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-5, Checkpoint 1 — the first staged FORCE ROW LEVEL
 * SECURITY activation for a table drawn from
 * RowLevelSecurityCoverageMappingService::missingPreparedTables() (the
 * 61 uncovered tenant-owned tables tracked in
 * docs/governance/rls-gap-registry.md), rather than from the now-fully-
 * forced 52-table PREPARED_TABLES arc that Section 39A-3L completed
 * (confirmed directly: every one of the 52 already-prepared tables now
 * has FORCE active — see that section's own final checkpoint,
 * security_events).
 *
 * Unlike every 39A-3 migration, this table has NO pre-existing policy
 * to flip FORCE on for — RowLevelSecurityCoverageMappingService lists
 * customer_success_health_scores under MISSING_PREPARED_TABLES, meaning
 * no ENABLE ROW LEVEL SECURITY and no CREATE POLICY exist for it
 * anywhere yet. This migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale (full detail in this checkpoint's PR
 * description): customer_success_health_scores has a direct, NOT NULL
 * firm_id column (foreignId()->constrained('firms')->cascadeOnDelete(),
 * see 2026_07_10_900013_create_customer_success_health_scores_table.php)
 * — no nullable-firm_id design question like security_events needed.
 * Its only application callers (CustomerSuccessHealthScoreService::
 * compute(), CustomerSuccessConsoleService::snapshotFor()/
 * organizationRollup()) are explicitly documented backend-only, with no
 * UI/controller/route in this phase, and compute() has no production
 * caller today (tests/governance-mapping references only) — the lowest
 * production blast radius available among the 61 candidates.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), rather than relying on
 * Postgres's documented "USING doubles as WITH CHECK when none is
 * given" behavior that every 39A-3 preparation policy uses. This is a
 * deliberate, reviewed choice for this checkpoint (explicit over
 * implicit), not a claim that the omitted-WITH-CHECK convention used
 * elsewhere in this codebase is wrong — CustomerSuccessHealthScoresForceRlsActivationTest
 * proves both read and write isolation regardless of which form is used.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'customer_success_health_scores';

    private const POLICY = 'customer_success_health_scores_tenant_isolation';

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
     * Full rollback: unlike a 39A-3-style FORCE-only migration's down()
     * (which only flips NO FORCE, since the policy pre-existed this
     * batch), this migration introduced the policy itself, so down()
     * must remove all three effects it added: FORCE, the policy, and
     * RLS being enabled at all — restoring the table to its true
     * pre-this-migration (MISSING_PREPARED_TABLES) state.
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
            throw new \RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
