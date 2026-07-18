<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * deployment_health_checks — sixth and last of a six-table, one-batch
 * FORCE ROW LEVEL SECURITY activation covering the governance/support/
 * platform domain (Section 39A-8 Wave 8). See 2026_08_28_960001's
 * docblock for the full batch rationale and table order. Kept last
 * because it has zero dependency on the other five tables in this
 * batch — placed here purely to preserve one clean, combined-wave
 * commit, not because of any FK or functional ordering requirement.
 *
 * deployment_health_checks has NO pre-existing policy to flip FORCE on
 * for — this migration does all three steps (ENABLE, CREATE POLICY,
 * FORCE) in one batch, never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: deployment_health_checks carries a
 * cascadeOnDelete() firm_id column (see database/migrations/
 * 2026_07_25_900002_create_deployment_health_checks_table.php:20). The
 * DeploymentHealthCheck model uses BelongsToTenant + HasPublicUuid, and
 * booted() blocks both UPDATE and DELETE (fully append-only, mirroring
 * WebhookEvent/AiUsageEvent's exact immutability pattern) — so despite
 * the fully-append-only nature at the Eloquent layer, the canonical
 * FOR ALL / combined command shape is still used, matching every other
 * table forced in this rollout (RLS governs INSERT/SELECT regardless of
 * whether UPDATE/DELETE can ever actually occur).
 *
 * Deliberate schema/policy mismatch, accepted as-is: unlike all five
 * other tables in this batch (and most tables in this rollout),
 * firm_id on deployment_health_checks is NULLABLE at the schema level.
 * Every current writer (DeploymentHealthEnvelopeService::buildEnvelope()/
 * reportOffline()) always sets a real, non-null firm_id — confirmed by
 * direct repository search; there is no code path today that inserts a
 * null-firm_id row. The standard non-null-style policy (identical
 * NULLIF/current_setting expression as every other table, no NULL-
 * handling branch) is applied anyway, rather than inventing a NULL-
 * aware policy branch for a purely hypothetical future row type — no
 * other table in this rollout has needed one, and if/when a genuine
 * null-firm_id row type is ever introduced, it will need its own
 * explicit policy design at that time, not a retrofit here. Note this
 * means any hypothetical future null-firm_id row would fail closed
 * under this policy (NULL firm_id can never equal the NULLIF(...)::bigint
 * comparison, on either side) — an accepted consequence of this
 * decision, not a bug. (Separately, and purely informationally:
 * RowLevelSecurityCoverageMappingService::fullTableInventory()'s
 * description of this table's ownership path as "self (own NOT NULL
 * firm_id column)" is factually wrong given the nullable schema —
 * flagged here for a future registry-accuracy cleanup, not fixed by
 * this migration.)
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent deployment_health_checks rows
 *      regardless of which tenant's context is currently active.
 *      Expected, identical behavior to every other cascade-on-firms
 *      table already forced in this repository.
 *   2. No other tenant-scoped foreign key columns exist on this table
 *      besides firm_id itself, so unlike every other table in this
 *      wave there is no single-hop cross-firm-mismatch risk here —
 *      the simplest table in this batch.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'deployment_health_checks';

    private const POLICY = 'deployment_health_checks_tenant_isolation';

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
