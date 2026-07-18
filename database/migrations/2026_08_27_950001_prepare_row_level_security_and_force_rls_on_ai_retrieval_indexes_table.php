<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-5, Wave 1, Checkpoint 1 of 3 — one of THREE tables
 * (ai_retrieval_indexes, deployment_configs, firm_ai_settings) drawn
 * from RowLevelSecurityCoverageMappingService::missingPreparedTables()
 * for this wave's FORCE ROW LEVEL SECURITY activation. Each table's
 * migration/service/test batch lands independently; the shared
 * registry (RowLevelSecurityCoverageMappingService, still listing
 * ai_retrieval_indexes under MISSING_PREPARED_TABLES at the point this
 * migration lands on its own) is updated once by the coordinator after
 * all three checkpoints in this wave have landed — not by this
 * migration.
 *
 * Like customer_success_health_scores (39A-5, Checkpoint 1 of the prior
 * arc), this table has NO pre-existing policy to flip FORCE on for —
 * no ENABLE ROW LEVEL SECURITY and no CREATE POLICY exist for it
 * anywhere yet. This migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: ai_retrieval_indexes has a direct, UNIQUE,
 * NOT NULL firm_id column (foreignId('firm_id')->unique()->constrained('firms')
 * ->cascadeOnDelete(), see
 * database/migrations/2026_07_23_900004_create_ai_retrieval_indexes_table.php)
 * — one row per firm, no nullable-firm_id design question. Its only
 * production callers (AiRetrievalIsolationService::provisionFor()/
 * buildContext()) are backend-only record-keeping for Phase 15's
 * retrieval-isolation contract (no real vector/search backend exists
 * yet) — no UI/controller/route touches this table.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the explicit-over-
 * implicit convention established by the customer_success_health_scores
 * checkpoint, rather than relying on Postgres's "USING doubles as WITH
 * CHECK when none is given" behavior used by the earlier 39A-3 arc.
 *
 * Known, accepted, non-gap behavior: PostgreSQL's documented row-
 * security semantics exempt foreign-key ON DELETE CASCADE actions from
 * row-security policy evaluation entirely (the cascade is enforced by
 * the FK constraint machinery, not by a role-scoped DML statement that
 * RLS would intercept). Consequently, deleting a `firms` row will
 * always cascade-delete its ai_retrieval_indexes row (firm_id is
 * ->cascadeOnDelete()) regardless of which tenant's context is
 * currently active in the session — this is expected, identical
 * behavior to every other cascade-on-firms table already forced in
 * this repository (e.g. customer_success_health_scores itself), not a
 * gap introduced or left open by this migration.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'ai_retrieval_indexes';

    private const POLICY = 'ai_retrieval_indexes_tenant_isolation';

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
