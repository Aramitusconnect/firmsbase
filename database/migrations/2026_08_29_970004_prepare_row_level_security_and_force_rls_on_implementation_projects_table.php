<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * implementation_projects — fourth of Wave 9's six-table batch (see
 * 2026_08_29_970001's docblock for the full batch list and ordering
 * rationale).
 *
 * implementation_projects has NO pre-existing policy — this migration
 * does all three steps (ENABLE, CREATE POLICY, FORCE) in one batch, per
 * docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: implementation_projects carries a direct,
 * NOT NULL firm_id column, unique('firm_id') (one row per firm),
 * cascadeOnDelete(). Informational inconsistency only, no action
 * required: the ImplementationProject model does NOT use
 * BelongsToTenant despite its non-null firm_id — unlike export_jobs/
 * migration_projects/import_batches above, which all correctly do.
 *
 * REQUIRED co-landed service change: ImplementationTaskService::
 * complete()/skip()/block() previously called
 * $this->updateProjectProgress($task->implementationProject) — a lazy
 * relation load of this now-forced table from an ImplementationTask,
 * which carries no firm_id column of its own (only
 * implementation_project_id) to key a tenant-context wrap on before
 * that lazy load resolves. This is landed in the same commit as this
 * migration: complete()/skip()/block() now take the already-known
 * ImplementationProject as an explicit parameter instead of relying on
 * the lazy relation. The only production caller
 * (ImplementationTaskServiceTest.php:33,45,57) already holds the
 * project in scope before calling these methods; that test file's call
 * sites will need updating in a separate test-focused phase — flagged,
 * not fixed here (implementation code is this migration's + this
 * commit's only remit).
 *
 * Known, deliberately-deferred gap (not closed by this migration):
 *   assigned_to is a nullable FK to platform_admins, which is NOT
 *   tenant-owned (a platform-global identity) — no cross-firm
 *   assertion is needed or performed for this column.
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
    private const TABLE = 'implementation_projects';

    private const POLICY = 'implementation_projects_tenant_isolation';

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
