<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * fleet_migration_instance_status — fifth of Wave 9's six-table batch
 * (see 2026_08_29_970001's docblock for the full batch list and
 * ordering rationale). This is the only firm-scoped record in an
 * otherwise-global batch-run construct: its other FK
 * (fleet_migration_run_id) points at fleet_migration_runs, a genuinely
 * platform-global table with no firm_id at all — out of scope for this
 * migration and NOT force-RLS'd.
 *
 * fleet_migration_instance_status has NO pre-existing policy — this
 * migration does all three steps (ENABLE, CREATE POLICY, FORCE) in one
 * batch, per docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: fleet_migration_instance_status carries a
 * direct, NOT NULL firm_id column, cascadeOnDelete(), and an existing
 * unique(['fleet_migration_run_id', 'firm_id']) that is already correct
 * and sufficient for per-firm-scoped writes to affect at most one row.
 * Informational inconsistency only, no action required: the
 * FleetMigrationInstanceStatus model does NOT use BelongsToTenant
 * despite its non-null firm_id.
 *
 * REQUIRED co-landed service change: this table's sole writer,
 * FleetMigrationOrchestrationService, previously performed cross-firm
 * bulk queries/updates with no per-firm scoping at all (a single
 * exists() check across every firm in complete(), a single bulk
 * UPDATE ... WHERE fleet_migration_run_id = ? with no firm_id
 * narrowing in applyInstance()'s failure branch and in rollback()) —
 * a genuine fail-open bug once FORCE RLS lands (a single active
 * app.current_firm_id session setting cannot see, update, or aggregate
 * rows belonging to any other firm). createRun(), applyInstance(),
 * rollback(), complete(), and summarize() are all rewritten in this
 * same commit to loop explicitly over
 * Firm::whereIn('deployment_mode', [dedicated, private_enterprise])
 * and read/write one firm's row at a time inside its own
 * runWithFirmContext() call, merging results in PHP (no BYPASSRLS, no
 * OR TRUE, no admin-role carve-out).
 *
 * Accepted, documented residual gap: a firm whose deployment_mode
 * changes (e.g. Dedicated -> Saas) after being enrolled by createRun()
 * but before a later applyInstance()/rollback()/complete()/summarize()
 * call would be silently excluded from that later loop's per-firm
 * enumeration, since every one of those methods re-derives its firm set
 * from the CURRENT deployment_mode rather than from the run's own
 * already-created instance rows. Not closed by this migration or by
 * the service rewrite — flagged here for awareness, matching this
 * rollout's "document, don't hide" standard for residual gaps.
 *
 *   Standard cascade-bypasses-RLS caveat applies here as it does to
 *   every cascade-on-firms table already forced in this repository.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'fleet_migration_instance_status';

    private const POLICY = 'fleet_migration_instance_status_tenant_isolation';

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
     * Full rollback: this migration introduced the policy itself, so
     * down() must remove all three effects up() added: FORCE, the
     * policy, and row-level security being enabled at all.
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
