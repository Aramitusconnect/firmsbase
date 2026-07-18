<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wave 1 (39A-5-successor RLS activation rollout), one of three tables
 * drawn from RowLevelSecurityCoverageMappingService::missingPreparedTables()
 * in this wave (ai_retrieval_indexes, deployment_configs,
 * firm_ai_settings) — each activated via its own narrowly-scoped
 * migration/commit. RowLevelSecurityCoverageMappingService itself is
 * NOT updated by this migration: the wave's registry move (all three
 * tables from MISSING_PREPARED_TABLES to PREPARED_TABLES) is done in a
 * single follow-up wave-integration commit once all three land, so
 * this table intentionally still reads as "missing prepared" in the
 * registry until then, even though its live database state is already
 * force-enforced.
 *
 * Like the customer_success_health_scores checkpoint before it,
 * deployment_configs has NO pre-existing RLS policy — RowLevelSecurityCoverageMappingService
 * lists it under MISSING_PREPARED_TABLES, meaning no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration performs all three required steps
 * (docs/governance/future-table-requirements.md #4/#5) in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Ownership: deployment_configs.firm_id is a direct, UNIQUE, NOT NULL
 * FK to firms (see database/migrations/2026_07_25_900001_create_deployment_configs_table.php
 * — foreignId('firm_id')->unique()->constrained('firms')->cascadeOnDelete()).
 * trust_iolta_disabled_acknowledged_by is a nullable acknowledging-actor
 * FK to users, not an ownership column, and needs no policy
 * consideration.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the
 * customer_success_health_scores checkpoint's own reviewed choice.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'deployment_configs';

    private const POLICY = 'deployment_configs_tenant_isolation';

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
     * Full rollback: this migration introduced the policy itself (not
     * merely flipping FORCE on for a pre-existing policy), so down()
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
            throw new RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
