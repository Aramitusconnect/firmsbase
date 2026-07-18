<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wave 1 (Section 39A-5 follow-on), one of three tables activated in
 * this wave (ai_retrieval_indexes, deployment_configs,
 * firm_ai_settings) — this migration handles firm_ai_settings only.
 *
 * Like customer_success_health_scores (see
 * database/migrations/2026_08_26_940001_prepare_row_level_security_and_force_rls_on_customer_success_health_scores_table.php),
 * firm_ai_settings has NO pre-existing RLS policy — it is listed under
 * RowLevelSecurityCoverageMappingService::missingPreparedTables(), so
 * this migration does all three required steps in one batch: ENABLE
 * ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL SECURITY,
 * never leaving RLS-enabled-with-no-policy as an intermediate state.
 *
 * Table selection rationale: firm_ai_settings.firm_id is a direct,
 * UNIQUE, NOT NULL foreign key to firms
 * (foreignId('firm_id')->unique()->constrained('firms')->cascadeOnDelete(),
 * see database/migrations/2026_07_23_900001_create_firm_ai_settings_table.php)
 * — one row per firm, no nullable-firm_id design question. Its only
 * real (currently unreachable-from-any-route, but genuinely wired)
 * readers are AiBudgetEnforcementService::checkFirmBudget() and
 * AiUsageRecorderService::computeCostCents(), both invoked from inside
 * AiUsageRecorderService::record() — the tenant-context wiring for
 * that single call chain is handled in the same commit as this
 * migration (see AiUsageRecorderService::record()'s own docblock).
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the
 * customer_success_health_scores checkpoint's own deliberate, reviewed
 * choice (explicit over the "USING doubles as WITH CHECK" convention
 * used by every 39A-3 preparation policy).
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'firm_ai_settings';

    private const POLICY = 'firm_ai_settings_tenant_isolation';

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
     * Full rollback: this migration introduced the policy itself (no
     * prior preparation migration existed for firm_ai_settings), so
     * down() must remove all three effects it added: FORCE, the
     * policy, and RLS being enabled at all — restoring the table to
     * its true pre-this-migration (MISSING_PREPARED_TABLES) state.
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
