<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * migration_projects — second of Wave 9's six-table batch (see
 * 2026_08_29_970001's docblock for the full batch list and ordering
 * rationale). Lands before import_batches (2026_08_29_970003) because
 * import_batches.migration_project_id references this table — no
 * ordering requirement is imposed by RLS itself, but this mirrors the
 * table-creation order already established in database/migrations/
 * 2026_07_11_900004_create_migration_projects_table.php and
 * 2026_07_11_900005_create_import_batches_table.php.
 *
 * migration_projects has NO pre-existing policy — this migration does
 * all three steps (ENABLE, CREATE POLICY, FORCE) in one batch, per
 * docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: migration_projects carries a direct, NOT
 * NULL firm_id column, cascadeOnDelete(). The MigrationProject model
 * uses BelongsToTenant + HasPublicUuid — a genuine tenant-owned row.
 *
 * Command shape: combined, symmetric, FOR ALL — migration_projects is
 * fully mutable via MigrationProjectService (Draft -> InProgress ->
 * Completed/Cancelled/Failed).
 *
 * Known, deliberately-deferred gap (not closed by this migration):
 *   created_by_firm_user_id is a nullable FK to firm_users with no
 *   composite foreign key or trigger tying firm_users.firm_id to this
 *   row's own firm_id — same class of accepted, residual gap as
 *   export_jobs.requested_by_firm_user_id (2026_08_29_970001).
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
    private const TABLE = 'migration_projects';

    private const POLICY = 'migration_projects_tenant_isolation';

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
