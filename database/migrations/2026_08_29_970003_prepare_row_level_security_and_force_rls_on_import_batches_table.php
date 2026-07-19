<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * import_batches — third of Wave 9's six-table batch (see
 * 2026_08_29_970001's docblock for the full batch list and ordering
 * rationale). Lands after migration_projects (2026_08_29_970002) since
 * import_batches.migration_project_id references that table.
 *
 * import_batches has NO pre-existing policy — this migration does all
 * three steps (ENABLE, CREATE POLICY, FORCE) in one batch, per
 * docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: import_batches carries a direct, NOT NULL
 * firm_id column, cascadeOnDelete(). The ImportBatch model uses
 * BelongsToTenant + HasPublicUuid — a genuine tenant-owned row, and the
 * root of the Import Center workflow (stage, map, dry-run/preview,
 * validate, confirm, apply, rollback).
 *
 * Command shape: combined, symmetric, FOR ALL — import_batches is
 * fully mutable across its four writer services (ImportBatchService,
 * ImportApplyService, ImportPreviewService, ImportRowValidationService,
 * ImportRollbackService), all updated in this same commit to establish
 * tenant context at every write site (see the accompanying service
 * changes).
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. migration_project_id is a nullable FK to migration_projects with
 *      no composite foreign key or trigger tying migration_projects.
 *      firm_id to this row's own firm_id — ImportBatchService::create()
 *      does not perform an explicit cross-firm assertion between the
 *      given Firm and the given (optional) MigrationProject. Confirmed
 *      reachable, not hypothetical: a caller could pass a
 *      MigrationProject belonging to a different firm than the Firm
 *      argument. Documented here as an accepted, residual
 *      application-layer gap — same posture as export_jobs' and
 *      migration_projects' single-hop-FK gaps above. No composite FK
 *      is added this wave (not as tight a same-batch pair as e.g. Wave
 *      8's support_access_sessions).
 *   2. created_by_firm_user_id is a nullable FK to firm_users with the
 *      same class of gap as export_jobs.requested_by_firm_user_id.
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
    private const TABLE = 'import_batches';

    private const POLICY = 'import_batches_tenant_isolation';

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
